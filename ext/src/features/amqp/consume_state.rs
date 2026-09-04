//! One consumer streamed to
//! PHP through the shared streaming-state registry.
//!
//! The first `next()` returns the consumer tag, and every following one returns
//! the next delivery. The stream ends when the consumer is cancelled, the
//! channel or connection dies, the flow stops, or the read timeout expires.

use std::sync::Arc;
use std::sync::atomic::{AtomicBool, AtomicI64, Ordering};
use std::time::{Duration, Instant};

use futures_util::StreamExt;
use lapin::Consumer;
use lapin::options::BasicConsumeOptions;
use tokio_util::sync::CancellationToken;

use crate::dto::{Message, Result};
use crate::helpers::calc_execution_ms;
use crate::states::{self, StateCloseFuture, StateContract, StateFuture};
use crate::tasks::Task;

use super::channels::{ChannelEntry, CommandError};
use super::connections::{DEFAULT_RPC_TIMEOUT, ms_or_default};
use super::consumerstats;
use super::payloads::{self, ChannelCommand};
use super::{SCOPE_COMMAND, error_payload, fail, network_error_payload};

/// Backs the generated consumer tags. The tag is generated here rather than
/// left to the driver because PHP needs it to cancel the consumer and to route
/// deliveries, and lapin keeps a generated one to itself.
static CONSUMER_COUNTER: AtomicI64 = AtomicI64::new(0);

pub fn next_consumer_tag() -> String {
    format!(
        "sconcur-ctag-{}",
        CONSUMER_COUNTER.fetch_add(1, Ordering::SeqCst) + 1
    )
}

struct ConsumeState {
    message: Arc<Message>,
    entry: Arc<ChannelEntry>,
    consumer_tag: String,
    read_timeout: Duration,
    start_time: Instant,
    auto_ack: bool,
    flow: CancellationToken,
    meta_sent: AtomicBool,
    cleaned: AtomicBool,
    consumer: tokio::sync::Mutex<Consumer>,
}

impl StateContract for ConsumeState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            if !self.meta_sent.swap(true, Ordering::SeqCst) {
                return Result::success_with_next(
                    &self.message,
                    payloads::encode_consumer_meta(&self.consumer_tag),
                    calc_execution_ms(self.start_time),
                );
            }

            let mut consumer = self.consumer.lock().await;

            // A delivery that is already buffered wins over shutdown: with both
            // ready, an unbiased select could pick the cancellation at random
            // and drop a message that had already arrived.
            let received = if self.read_timeout.is_zero() {
                tokio::select! {
                    biased;

                    delivery = consumer.next() => Some(delivery),
                    _ = self.flow.cancelled() => None,
                }
            } else {
                tokio::select! {
                    biased;

                    delivery = consumer.next() => Some(delivery),
                    _ = tokio::time::sleep(self.read_timeout) => {
                        // ext-amqp ends the consume loop with this exact failure
                        // when read_timeout passes with no delivery, wording
                        // included.
                        return Result::error(
                            &self.message,
                            error_payload(SCOPE_COMMAND, 0, "Consumer timeout exceed"),
                        );
                    }
                    _ = self.flow.cancelled() => None,
                }
            };

            match received {
                Some(Some(Ok(delivery))) => {
                    // Counted here, where the delivery leaves for PHP: the
                    // acknowledgement that settles it arrives as an ordinary
                    // command, so the pair needs nothing extra on the wire.
                    consumerstats::stats().delivery_dispatched(
                        &self.entry.id,
                        delivery.delivery_tag,
                        self.auto_ack,
                    );

                    Result::success_with_next(
                        &self.message,
                        payloads::encode_delivery(&payloads::DeliveryOut {
                            channel_id: &self.entry.id,
                            consumer_tag: &self.consumer_tag,
                            delivery_tag: delivery.delivery_tag,
                            redelivered: delivery.redelivered,
                            exchange_name: delivery.exchange.as_str(),
                            routing_key: delivery.routing_key.as_str(),
                            body: &delivery.data,
                            properties: &delivery.properties,
                        }),
                        calc_execution_ms(self.start_time),
                    )
                }
                Some(Some(Err(error))) => {
                    self.entry.note_protocol_failure(&error);

                    self.ended()
                }
                // The driver ended the stream: the consumer was cancelled, the
                // channel died, or the queue was deleted.
                Some(None) => self.ended(),
                // The flow is going away, which is the normal end of a consume
                // loop.
                None => Result::success(
                    &self.message,
                    Vec::new(),
                    calc_execution_ms(self.start_time),
                ),
            }
        })
    }

    /// Cancels the consumer, leaving the channel open — a channel outlives its
    /// consumers. It runs on a deadline of its own: by the time cleanup runs,
    /// the task's own is long gone.
    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            if self.cleaned.swap(true, Ordering::SeqCst) {
                return;
            }

            consumerstats::stats().consumer_closed(&self.entry.id, &self.consumer_tag);

            // Only if this consumer is still registered — PHP may have
            // cancelled it already. That also drops it from the registry, so the
            // idle sweeper sees the channel as idle.
            self.entry.cancel_consumer(&self.consumer_tag).await;
        })
    }
}

impl ConsumeState {
    /// The stream ended without the flow going away, which is a failure:
    /// ext-amqp raises there, so the stream ends with an error rather than a
    /// quiet return.
    fn ended(&self) -> Result {
        if self.flow.is_cancelled() {
            return Result::success(
                &self.message,
                Vec::new(),
                calc_execution_ms(self.start_time),
            );
        }

        // A connection that died ends every consumer on it the same way the
        // broker cancelling one does, and a worker told only that its consumer
        // was cancelled would open another on a connection that is not there.
        if self.entry.connection_closed() {
            return Result::error(
                &self.message,
                network_error_payload(&format!(
                    "Consumer {} ended: no connection available.",
                    self.consumer_tag
                )),
            );
        }

        Result::error(
            &self.message,
            error_payload(
                SCOPE_COMMAND,
                0,
                &format!(
                    "Consumer {} was cancelled by the broker.",
                    self.consumer_tag
                ),
            ),
        )
    }
}

/// Registers a consumer and streams its deliveries. The consumer is not tied to
/// the task's deadline — the stream state owns it, and `close()` cancels it when
/// PHP stops reading or the flow ends.
pub async fn handle_consume(task: &Task, raw: &rmpv::Value) {
    let Some((entry, params, start_time)) =
        super::commands::resolve::<payloads::ConsumeParams>(task, raw, "consume").await
    else {
        return;
    };

    let message = task.message();

    let consumer_tag = if params.consumer_tag.is_empty() {
        next_consumer_tag()
    } else {
        params.consumer_tag.clone()
    };

    let timeout = ms_or_default(params.timeout_ms(), DEFAULT_RPC_TIMEOUT);

    let consumer = match open_consumer(
        &entry,
        &consumer_tag,
        &params.queue_name,
        params.auto_ack,
        params.exclusive,
        params.no_local,
        params.no_wait,
        super::commands::arguments(&params.arguments),
        timeout,
    )
    .await
    {
        Ok(consumer) => consumer,
        Err(error) => {
            fail(task, Some(&entry), "consume", error).await;

            return;
        }
    };

    entry.register_consumer(&consumer_tag, &message.task_key);

    consumerstats::stats().consumer_opened(&entry.id, &consumer_tag);
    consumerstats::start_telemetry();

    let state = Arc::new(ConsumeState {
        message: task.message_arc(),
        entry: entry.clone(),
        consumer_tag: consumer_tag.clone(),
        read_timeout: Duration::from_millis(params.read_timeout_ms.max(0) as u64),
        start_time,
        auto_ack: params.auto_ack,
        flow: task.context().clone(),
        meta_sent: AtomicBool::new(false),
        cleaned: AtomicBool::new(false),
        consumer: tokio::sync::Mutex::new(consumer),
    });

    // The first next() returns the consumer tag with the more-coming flag, so
    // the state stays alive and the deliveries can be pulled through next().
    // states::start hooks close() on flow stop.
    match states::get()
        .start(task.context().clone(), &message.task_key, state.clone())
        .await
    {
        Ok(result) => task.add_result(result).await,
        Err(error) => {
            state.close().await;

            fail(
                task,
                Some(&entry),
                "consume",
                CommandError::Message(error),
            )
            .await;
        }
    }
}

/// Registers the consumer on the driver channel, bounded by the command
/// deadline like every other method.
///
/// A registration that outran its deadline is the one place a dropped future is
/// not enough: the broker may have taken the `basic.consume` anyway and will
/// keep feeding it, and nothing else can cancel it — the channel's registry
/// never learned the tag. So the cancel is sent here, or the queue quietly
/// stops making progress at its prefetch.
#[allow(clippy::too_many_arguments)]
pub async fn open_consumer(
    entry: &Arc<ChannelEntry>,
    consumer_tag: &str,
    queue_name: &str,
    auto_ack: bool,
    exclusive: bool,
    no_local: bool,
    no_wait: bool,
    arguments: lapin::types::FieldTable,
    timeout: Duration,
) -> std::result::Result<Consumer, CommandError> {
    let outcome = entry
        .run(
            timeout,
            entry.channel().basic_consume(
                super::commands::short(queue_name),
                super::commands::short(consumer_tag),
                BasicConsumeOptions {
                    no_local,
                    no_ack: auto_ack,
                    exclusive,
                    nowait: no_wait,
                },
                arguments,
            ),
        )
        .await;

    if matches!(outcome, Err(CommandError::CommandTimeout)) {
        let entry = entry.clone();
        let consumer_tag = consumer_tag.to_string();

        crate::core::get().runtime().spawn(async move {
            entry.send_cancel(&consumer_tag, false).await;
        });
    }

    outcome
}
