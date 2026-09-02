//! Mirrors ext-go-legacy/internal/features/amqp/consume_serve.go: the delivery stream of a
//! supervised worker.
//!
//! It is the AMQP counterpart of the servers' accept stream, and it is
//! self-pumping for the same reason: a worker doing tens of thousands of
//! messages a second must not pay a `next()` crossing, a task and a spawn per
//! message. PHP drives it with `Scheduler::serve()`, exactly as it drives the
//! three servers.
//!
//! The channels belong here rather than to a PHP object. That is what lets a
//! worker stop without asking the runtime anything about the coroutine it is
//! stopping: a drain cancels the consumers and leaves the channels open so the
//! acknowledgements in flight still land, and the flow ending closes them.

use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use futures_util::StreamExt;
use tokio_util::sync::CancellationToken;

use crate::dto::{Message, Result};
use crate::helpers::calc_execution_ms;
use crate::tasks::Task;

use super::channels::ChannelEntry;
use super::connections::{ConnectionHandle, DEFAULT_RPC_TIMEOUT, ms_or_default};
use super::consume_state::{next_consumer_tag, open_consumer};
use super::consumerstats;
use super::payloads::{self, ConsumeServeParams, ConsumeServeQueue};
use super::{SCOPE_COMMAND, channels, classify, connections, error_payload, network_error_payload};

/// How long a consumer the broker took away waits before it is opened again.
/// The same second the PHP consumer waited when it did the reopening itself.
const DEFAULT_REOPEN_DELAY: Duration = Duration::from_secs(1);

/// One open consumer: the channel it runs on and the tag it answers to.
#[derive(Clone)]
struct LiveConsumer {
    entry: Arc<ChannelEntry>,
    consumer_tag: String,
}

#[derive(Default)]
struct StreamState {
    /// Set by `stop_consuming`: from then on a consumer whose deliveries stop
    /// is one this side cancelled, not one the broker took away, so it is not
    /// opened again.
    stopping: bool,
    /// The consumer of each slot that has one, by slot index — what
    /// `stop_consuming` cancels.
    live: HashMap<usize, LiveConsumer>,
    /// Every channel this stream owns, by id. Kept apart from `live` because
    /// the two have different lifetimes: a cancelled consumer leaves its channel
    /// open so the acknowledgements of the handlers still running land on it,
    /// and only the flow ending closes it.
    entries: HashMap<String, Arc<ChannelEntry>>,
}

pub struct ServeStream {
    message: Arc<Message>,
    task: Task,
    handle: Arc<ConnectionHandle>,
    params: ConsumeServeParams,
    start_time: Instant,
    flow: CancellationToken,
    /// Guards the last result of the stream: whatever ends it says so once, and
    /// the deliveries that were already on their way are dropped rather than
    /// published after it.
    ended: AtomicBool,
    state: Mutex<StreamState>,
}

/// The supervised streams of this process, by flow key — so a drain can cancel
/// the consumers early without cancelling the flow, the way the servers close
/// their listener early.
pub struct Streams {
    streams: Mutex<HashMap<String, Arc<ServeStream>>>,
}

impl Streams {
    pub fn new() -> Self {
        Streams {
            streams: Mutex::new(HashMap::new()),
        }
    }

    fn store(&self, flow_key: String, stream: Arc<ServeStream>) {
        self.streams.lock().unwrap().insert(flow_key, stream);
    }

    fn take(&self, flow_key: &str) -> Option<Arc<ServeStream>> {
        self.streams.lock().unwrap().remove(flow_key)
    }

    fn find(&self, flow_key: &str) -> Option<Arc<ServeStream>> {
        self.streams.lock().unwrap().get(flow_key).cloned()
    }
}

/// Cancels every consumer of a worker's stream, leaving its channels open.
///
/// Leaving them open is the whole point: the handlers PHP has already been
/// given are still running, and their acknowledgements travel on those channels.
/// Closing here would hand finished messages back to the broker for another
/// worker to do again.
pub fn stop_consuming(flow_key: &str) {
    let Some(stream) = super::registries().consume_serve.find(flow_key) else {
        return;
    };

    crate::core::get().runtime().spawn(async move {
        stream.stop_consuming().await;
    });
}

/// Opens the consumers of one supervised worker and streams their deliveries
/// under a single task.
pub async fn handle_consume_serve(task: &Task, raw: &rmpv::Value) {
    let start_time = Instant::now();
    let message = task.message();

    let params = match rmpv_decode::<ConsumeServeParams>(raw) {
        Ok(params) => params,
        Err(error) => {
            task.add_result(Result::error(
                message,
                super::ERRORS.by_err("parse consume serve params", error),
            ))
            .await;

            return;
        }
    };

    let Some(handle) = connections().find(&params.connection_id) else {
        task.add_result(Result::error(
            message,
            network_error_payload("No connection available."),
        ))
        .await;

        return;
    };

    if params.queues.is_empty() {
        task.add_result(Result::error(
            message,
            error_payload(SCOPE_COMMAND, 0, "No queues to consume."),
        ))
        .await;

        return;
    }

    let queues = params.queues.clone();

    let stream = Arc::new(ServeStream {
        message: task.message_arc(),
        task: task.clone(),
        handle,
        params,
        start_time,
        flow: task.context().clone(),
        ended: AtomicBool::new(false),
        state: Mutex::new(StreamState::default()),
    });

    super::registries()
        .consume_serve
        .store(message.flow_key.clone(), stream.clone());

    // The flow ending is what closes the channels — a hard stopFlow with no
    // drain before it included.
    {
        let closing = stream.clone();
        let flow = task.context().clone();

        crate::core::get().runtime().spawn(async move {
            flow.cancelled().await;

            closing.close().await;
        });
    }

    consumerstats::start_telemetry();

    let mut slot = 0usize;

    for queue in queues {
        for _ in 0..queue.consumers.max(1) {
            let running = stream.clone();
            let queue = queue.clone();
            let index = slot;

            crate::core::get().runtime().spawn(async move {
                running.run_slot(index, queue).await;
            });

            slot += 1;
        }
    }
}

impl ServeStream {
    /// Cancels the live consumers, all at once: each `basic.cancel` waits on the
    /// broker, and a worker with a dozen of them would otherwise drain a dozen
    /// waits deep.
    async fn stop_consuming(&self) {
        let consumers: Vec<LiveConsumer> = {
            let mut state = self.state.lock().unwrap();

            state.stopping = true;

            state.live.values().cloned().collect()
        };

        futures_util::future::join_all(
            consumers
                .iter()
                .map(|consumer| consumer.entry.cancel_consumer(&consumer.consumer_tag)),
        )
        .await;
    }

    /// Releases everything the stream owns. It runs when the flow ends — the
    /// serve loop stops the flow on its way out, after the last handler has
    /// finished.
    async fn close(&self) {
        super::registries()
            .consume_serve
            .take(&self.message.flow_key);

        let entries: Vec<Arc<ChannelEntry>> = {
            let mut state = self.state.lock().unwrap();

            state.stopping = true;

            let entries = state.entries.values().cloned().collect();

            state.live.clear();
            state.entries.clear();

            entries
        };

        futures_util::future::join_all(
            entries.iter().map(|entry| channels().close(&entry.id)),
        )
        .await;
    }

    /// Keeps one consumer on one queue alive for as long as this worker
    /// consumes.
    ///
    /// A consumer is taken away by more than the queue being deleted: a channel
    /// dies over an unrelated 404, a cluster node fails over. That leaves the
    /// queue unread while its neighbours carry on, so the slot opens a fresh
    /// channel and a fresh consumer a moment later, for as long as reopening can
    /// work.
    ///
    /// What ends the whole stream is the connection going away. It is shared by
    /// every slot and cannot be reopened from here, so the stream fails, PHP's
    /// serve loop raises, the worker exits, and its master starts a fresh
    /// process with a fresh connection.
    async fn run_slot(self: Arc<Self>, slot: usize, queue: ConsumeServeQueue) {
        let mut first = true;

        loop {
            let opened = self.open_consumer(slot, &queue).await;

            let (entry, consumer_tag, mut consumer) = match opened {
                Ok(opened) => opened,
                Err((entry, error)) => {
                    if self.finished() {
                        return;
                    }

                    // The first open is the worker's start-up: a queue that is
                    // not there, or credentials that cannot consume it, must be
                    // heard about now rather than retried silently for the life
                    // of the worker.
                    if first {
                        let (scope, code, text) = classify(
                            entry.as_ref(),
                            &format!("consumer {} could not be opened", queue.name),
                            error,
                        );

                        self.end(Result::error(
                            &self.message,
                            error_payload(&scope, code, &text),
                        ))
                        .await;

                        return;
                    }

                    if self.connection_gone() {
                        self.fail_connection(&queue.name).await;

                        return;
                    }

                    crate::logger::write(format!(
                        "amqp: consumer {} could not be reopened\n",
                        queue.name
                    ));

                    if !self.pause().await {
                        return;
                    }

                    continue;
                }
            };

            first = false;

            let lost = self.pump(&entry, &consumer_tag, &mut consumer).await;

            self.forget(slot);

            if !lost {
                // Asked to stop, or the flow is going away. The channel stays
                // open either way: the handlers still holding a delivery of it
                // answer the broker on it, and close() is what releases it once
                // the flow ends.
                return;
            }

            self.release(&entry).await;

            if self.connection_gone() {
                self.fail_connection(&queue.name).await;

                return;
            }

            crate::logger::write(format!(
                "amqp: consumer {} ({consumer_tag}) was taken away; reopening\n",
                queue.name
            ));

            if !self.pause().await {
                return;
            }
        }
    }

    /// Gives the slot a channel of its own and registers its consumer on it.
    ///
    /// A channel is never shared between slots: the commands of one are
    /// serialized on the broker, so sharing would turn N consumers into a queue
    /// of N — and a reopened consumer gets a fresh one, since whatever ended the
    /// last one usually took the channel with it.
    #[allow(clippy::type_complexity)]
    async fn open_consumer(
        &self,
        slot: usize,
        queue: &ConsumeServeQueue,
    ) -> std::result::Result<
        (Arc<ChannelEntry>, String, lapin::Consumer),
        (Option<Arc<ChannelEntry>>, super::channels::CommandError),
    > {
        let timeout = ms_or_default(self.params.timeout_ms, DEFAULT_RPC_TIMEOUT);

        let prefetch_count = if queue.prefetch_count > 0 {
            queue.prefetch_count
        } else {
            self.params.prefetch_count
        };

        let open_params = payloads::ChannelOpenParams {
            connection_id: self.params.connection_id.clone(),
            prefetch_size_bytes: 0,
            prefetch_count: prefetch_count.max(0),
            timeout_ms: self.params.timeout_ms,
        };

        let entry = match channels().open(&self.handle, &open_params, timeout).await {
            Ok(entry) => entry,
            Err(error) => return Err((None, error)),
        };

        self.own(&entry);

        let consumer_tag = next_consumer_tag();

        let consumer = match open_consumer(
            &entry,
            &consumer_tag,
            &queue.name,
            self.params.auto_ack,
            false,
            false,
            false,
            lapin::types::FieldTable::default(),
            timeout,
        )
        .await
        {
            Ok(consumer) => consumer,
            Err(error) => {
                self.release(&entry).await;

                return Err((Some(entry), error));
            }
        };

        entry.register_consumer(&consumer_tag, "");

        consumerstats::stats().consumer_opened(&entry.id, &consumer_tag);

        // Recorded only once it is fully open: a drain that arrives in the
        // middle cancels what exists, and the slot itself sees the stop on its
        // next turn.
        if !self.remember(
            slot,
            LiveConsumer {
                entry: entry.clone(),
                consumer_tag: consumer_tag.clone(),
            },
        ) {
            self.release(&entry).await;

            return Err((
                Some(entry),
                super::channels::CommandError::ChannelGone,
            ));
        }

        Ok((entry, consumer_tag, consumer))
    }

    /// Publishes every delivery of one consumer, and answers whether the
    /// consumer was taken away — as opposed to this side ending it.
    async fn pump(
        &self,
        entry: &Arc<ChannelEntry>,
        consumer_tag: &str,
        consumer: &mut lapin::Consumer,
    ) -> bool {
        loop {
            let received = tokio::select! {
                biased;

                delivery = consumer.next() => delivery,
                _ = self.flow.cancelled() => return false,
            };

            match received {
                Some(Ok(delivery)) => self.publish(entry, consumer_tag, delivery).await,
                Some(Err(error)) => {
                    entry.note_protocol_failure(&error);

                    return !self.finished();
                }
                None => return !self.finished(),
            }
        }
    }

    /// Hands one delivery to PHP as the next result of the stream.
    async fn publish(
        &self,
        entry: &Arc<ChannelEntry>,
        consumer_tag: &str,
        delivery: lapin::message::Delivery,
    ) {
        if self.ended.load(Ordering::SeqCst) {
            return;
        }

        // Counted where the delivery leaves for PHP: the acknowledgement that
        // settles it arrives as an ordinary command, so the pair needs nothing
        // extra on the wire.
        consumerstats::stats().delivery_dispatched(
            &entry.id,
            delivery.delivery_tag,
            self.params.auto_ack,
        );

        self.task
            .add_result(Result::success_with_next(
                &self.message,
                payloads::encode_delivery(&payloads::DeliveryOut {
                    channel_id: &entry.id,
                    consumer_tag,
                    delivery_tag: delivery.delivery_tag,
                    redelivered: delivery.redelivered,
                    exchange_name: delivery.exchange.as_str(),
                    routing_key: delivery.routing_key.as_str(),
                    body: &delivery.data,
                    properties: &delivery.properties,
                }),
                calc_execution_ms(self.start_time),
            ))
            .await;
    }

    /// Ends the stream because the connection every consumer shares is gone.
    async fn fail_connection(&self, queue_name: &str) {
        self.end(Result::error(
            &self.message,
            network_error_payload(&format!(
                "Consumer {queue_name} ended: no connection available."
            )),
        ))
        .await;
    }

    /// Publishes the last result of the stream, once. Whatever else was still
    /// on its way is dropped: PHP has left the loop by then.
    async fn end(&self, result: Result) {
        if self.ended.swap(true, Ordering::SeqCst) {
            return;
        }

        self.task.add_result(result).await;
    }

    /// Waits out the reopen delay, answering false when the stream ended while
    /// it waited.
    async fn pause(&self) -> bool {
        let delay = ms_or_default(self.params.reopen_delay_ms, DEFAULT_REOPEN_DELAY);

        tokio::select! {
            _ = tokio::time::sleep(delay) => !self.finished(),
            _ = self.flow.cancelled() => false,
        }
    }

    /// Whether there is any point going on: the flow is gone, or a stop was
    /// asked for.
    fn finished(&self) -> bool {
        self.flow.is_cancelled() || self.state.lock().unwrap().stopping
    }

    /// Whether the connection every slot shares is beyond reopening — the
    /// socket died, or PHP handed the connection back, which closes the channels
    /// of every slot on it just the same.
    fn connection_gone(&self) -> bool {
        self.handle.connection_gone()
    }

    /// Records a channel this stream opened, so `close()` releases it whatever
    /// the slot that opened it went on to do.
    fn own(&self, entry: &Arc<ChannelEntry>) {
        self.state
            .lock()
            .unwrap()
            .entries
            .insert(entry.id.clone(), entry.clone());
    }

    /// Closes a channel this stream is done with — a consumer that was taken
    /// away gets a fresh one, and the old one has nothing left on it.
    async fn release(&self, entry: &Arc<ChannelEntry>) {
        self.state.lock().unwrap().entries.remove(&entry.id);

        channels().close(&entry.id).await;
    }

    /// Records a slot's consumer, answering false when a stop got there first —
    /// the caller then gives the channel back instead of leaving one nobody will
    /// cancel.
    fn remember(&self, slot: usize, consumer: LiveConsumer) -> bool {
        let mut state = self.state.lock().unwrap();

        if state.stopping {
            return false;
        }

        state.live.insert(slot, consumer);

        true
    }

    fn forget(&self, slot: usize) {
        self.state.lock().unwrap().live.remove(&slot);
    }
}

fn rmpv_decode<P: serde::de::DeserializeOwned>(
    raw: &rmpv::Value,
) -> std::result::Result<P, String> {
    let mut buffer = Vec::new();

    rmpv::encode::write_value(&mut buffer, raw).map_err(|error| error.to_string())?;

    rmp_serde::from_slice(&buffer).map_err(|error| error.to_string())
}
