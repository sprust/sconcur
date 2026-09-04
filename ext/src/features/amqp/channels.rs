//! The
//! process-wide registry of open channels, and what one open channel holds.
//!
//! The registry is global on purpose: an acknowledgement may well arrive from
//! another coroutine, and so from another flow, than the consumer that received
//! the message. It is also the only registry a channel is in — which channels a
//! connection handle owns is answered by walking it.

use std::collections::HashMap;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use lapin::options::{BasicCancelOptions, BasicQosOptions};
use lapin::protocol::basic::AMQPProperties;
use lapin::types::ShortString;
use lapin::{Channel, Error as LapinError};
use tokio::sync::Notify;
use tokio_util::sync::CancellationToken;

use super::connections::ConnectionHandle;
use super::consumerstats;
use super::payloads::ChannelOpenParams;

/// A channel with no consumers that has run no command for this long is closed.
/// It is the safety net for the channels an application dropped without closing
/// in a way PHP could not report (a fatal error, a killed worker) — the usual
/// path is `Channel::close()` or its destructor.
const CHANNEL_IDLE_TTL: Duration = Duration::from_secs(30 * 60);
const CHANNEL_SWEEP_INTERVAL: Duration = Duration::from_secs(60);

const CHANNEL_CLOSE_TIMEOUT: Duration = Duration::from_secs(5);
/// Bounds the `basic.cancel` sent while a consumer is torn down.
const CONSUMER_CANCEL_TIMEOUT: Duration = Duration::from_secs(5);

/// Buffers the publisher confirms the broker has sent but a wait loop has not
/// collected yet.
const CONFIRM_QUEUE_SIZE: usize = 1024;
/// Buffers the messages the broker returned as unroutable.
const RETURN_QUEUE_SIZE: usize = 128;

/// One message the broker could not route, kept until a wait collects it.
pub struct StoredReturn {
    pub reply_code: i64,
    pub reply_text: String,
    pub exchange: String,
    pub routing_key: String,
    pub body: Vec<u8>,
    pub properties: AMQPProperties,
}

#[derive(Default)]
struct EntryState {
    /// The tags this channel has open, onto the key of the stream each is read
    /// through, so cancelling a consumer can drop that stream as well.
    consumers: HashMap<String, String>,
    closed: bool,
    /// What the broker said when it closed the channel. It is what turns "No
    /// channel available." into the 404 or 406 that actually happened.
    close_reason: Option<(i64, String)>,
    last_used_at: Option<Instant>,

    confirming: bool,
    pending: i64,
    /// The delivery tag the next publish on this channel will carry. AMQP
    /// numbers publisher confirms per channel, monotonically from one after
    /// `confirm.select`, so counting them here yields exactly the tags the
    /// broker answers with — which lapin's `Confirmation` does not carry.
    publish_tag: u64,
    confirmations: Vec<(u64, bool)>,
    returns: Vec<StoredReturn>,
}

/// One open channel: the driver channel itself, the handle that owns it, its
/// consumers, and — once the channel is in publisher-confirm mode — what the
/// broker has reported about the messages published on it.
pub struct ChannelEntry {
    pub id: String,
    pub number: i64,
    pub handle: Arc<ConnectionHandle>,
    channel: Channel,

    /// Serializes the driver channel: a cancel or a qos may arrive from another
    /// coroutine while a command is running, and
    /// keeping the order the broker sees is what makes a failure attributable.
    command_lock: tokio::sync::Mutex<()>,

    state: Mutex<EntryState>,
    /// Released whenever something a wait loop cares about happens; each waiter
    /// re-checks its own condition and parks again if it is not met.
    changed: Notify,
    /// Cancelled once the channel is, so a wait loop ends instead of parking
    /// forever on a channel the broker took away.
    gone: CancellationToken,
}

impl ChannelEntry {
    pub fn channel(&self) -> &Channel {
        &self.channel
    }

    pub fn touch(&self) {
        self.state.lock().unwrap().last_used_at = Some(Instant::now());
    }

    pub fn is_closed(&self) -> bool {
        self.state.lock().unwrap().closed
    }

    /// Whether the connection this channel lives on has gone away. The
    /// connection is the one thing that cannot be raced: a channel-level 404
    /// answered while this side has not yet noticed the channel go must not be
    /// classified as a dead connection, or it would take every other channel of
    /// that connection down with it.
    pub fn connection_closed(&self) -> bool {
        self.handle.pooled.is_closed()
    }

    /// What the broker said when it closed this channel, or `None` when nobody
    /// said anything — the channel was released from this side, or it is not
    /// closed.
    pub fn close_reason(&self) -> Option<(i64, String)> {
        self.state.lock().unwrap().close_reason.clone()
    }

    /// Records the reason the broker closed this channel. Called from wherever
    /// a channel-level refusal is first seen: a command that was refused
    /// outright, and the publisher confirm that is rejected with the very error
    /// that closed the channel — which is what makes a publish to a missing
    /// exchange nameable at all, since `basic.publish` carries no reply.
    pub fn record_close_reason(&self, code: i64, text: String) {
        let mut state = self.state.lock().unwrap();

        if state.close_reason.is_none() {
            state.close_reason = Some((code, text));
        }
    }

    fn is_idle_since(&self, moment: Instant) -> bool {
        let state = self.state.lock().unwrap();

        state.consumers.is_empty() && state.last_used_at.is_some_and(|used| used < moment)
    }

    /// Runs one driver call serialized against the other commands of this
    /// channel and bounded by the deadline PHP gave it.
    ///
    /// Bounding a driver call needs nothing of its own here: the call is a
    /// future, and the timeout drops it.
    pub async fn run<T, F>(&self, timeout: Duration, call: F) -> Result<T, CommandError>
    where
        F: std::future::Future<Output = Result<T, LapinError>>,
    {
        let _guard = self.command_lock.lock().await;

        match tokio::time::timeout(timeout, call).await {
            Ok(Ok(value)) => Ok(value),
            Ok(Err(error)) => {
                self.note_protocol_failure(&error);

                Err(CommandError::Driver(error))
            }
            Err(_) => Err(CommandError::CommandTimeout),
        }
    }

    /// A channel-level refusal closes the channel on the broker, so the reason
    /// is recorded and the entry leaves the registry with the very command that
    /// saw it — rather than lingering there, answering later commands and
    /// counting towards the connection's channel limit, until the idle sweeper
    /// gets to it half an hour later.
    ///
    /// lapin reports the close as the error of whatever ran into it, so no
    /// close listener is needed — and the reason arrives earlier than a listener
    /// would deliver it.
    pub fn note_protocol_failure(&self, error: &LapinError) {
        let Some((code, text)) = protocol_failure(error) else {
            return;
        };

        if is_connection_close_code(code) {
            return;
        }

        self.record_close_reason(code, text);

        // Detached synchronously, so a `usedChannels()` on the very next
        // crossing already reports it gone; the teardown itself talks to the
        // broker and goes to the runtime.
        if let Some(entry) = super::channels().detach(&self.id) {
            crate::core::get().runtime().spawn(async move {
                entry.close().await;
            });
        }
    }

    pub fn register_consumer(&self, consumer_tag: &str, task_key: &str) {
        let mut state = self.state.lock().unwrap();

        state
            .consumers
            .insert(consumer_tag.to_string(), task_key.to_string());
        state.last_used_at = Some(Instant::now());
    }

    /// Drops a consumer from the registry and reports the key of the stream it
    /// was read through, so a cancelled consumer takes that stream with it. The
    /// answer is `None` when the consumer was gone already, and a tag is never
    /// cancelled twice.
    pub fn forget_consumer(&self, consumer_tag: &str) -> Option<String> {
        let mut state = self.state.lock().unwrap();

        let task_key = state.consumers.remove(consumer_tag);

        state.last_used_at = Some(Instant::now());

        task_key
    }

    /// Sends the `basic.cancel` for a consumer this feature still holds, on a
    /// deadline of its own: by the time a stream is torn down its task deadline
    /// is long gone.
    pub async fn cancel_consumer(&self, consumer_tag: &str) {
        if self.forget_consumer(consumer_tag).is_none() {
            return;
        }

        self.send_cancel(consumer_tag, false).await;
    }

    /// The `basic.cancel` itself, without the registry check — for a consumer
    /// the broker accepted but this side never registered, which is what a
    /// registration that outran its deadline leaves behind.
    pub async fn send_cancel(&self, consumer_tag: &str, no_wait: bool) {
        if self.is_closed() {
            return;
        }

        let _ = self
            .run(
                CONSUMER_CANCEL_TIMEOUT,
                self.channel.basic_cancel(
                    ShortString::from(consumer_tag.to_string()),
                    BasicCancelOptions { nowait: no_wait },
                ),
            )
            .await;
    }

    // --- publisher confirms ---------------------------------------------------

    pub fn in_confirm_mode(&self) -> bool {
        self.state.lock().unwrap().confirming
    }

    /// Puts the channel into publisher-confirm mode. Calling it twice is a
    /// no-op. The flag is set while the command lock is held, so a publish
    /// waiting for the same lock is counted and one that already went through
    /// was published before confirm mode and gets no confirmation.
    pub async fn start_confirm_mode(
        &self,
        timeout: Duration,
        no_wait: bool,
    ) -> Result<(), CommandError> {
        if self.in_confirm_mode() {
            return Ok(());
        }

        let guard = self.command_lock.lock().await;

        if self.in_confirm_mode() {
            return Ok(());
        }

        let selecting = self
            .channel
            .confirm_select(lapin::options::ConfirmSelectOptions { nowait: no_wait });

        let outcome = match tokio::time::timeout(timeout, selecting).await {
            Ok(Ok(())) => Ok(()),
            Ok(Err(error)) => {
                self.note_protocol_failure(&error);

                Err(CommandError::Driver(error))
            }
            Err(_) => Err(CommandError::CommandTimeout),
        };

        if outcome.is_ok() {
            self.state.lock().unwrap().confirming = true;
        }

        drop(guard);

        outcome
    }

    /// Records one more message awaiting a confirm and answers the delivery tag
    /// the broker will use for it; `None` outside publisher-confirm mode.
    pub fn publishing(&self) -> Option<u64> {
        let mut state = self.state.lock().unwrap();

        state.last_used_at = Some(Instant::now());

        if !state.confirming {
            return None;
        }

        state.pending += 1;
        state.publish_tag += 1;

        Some(state.publish_tag)
    }

    /// Publishes one message, taking its delivery tag under the same lock the
    /// write is made under.
    ///
    /// The tag cannot be assigned before the lock. AMQP numbers publisher
    /// confirms per channel in the order the publishes arrive, so two
    /// coroutines publishing on one channel would otherwise be handed tags in
    /// one order and reach the broker in another — and a nack would then name
    /// the wrong message.
    pub async fn publish(
        &self,
        timeout: Duration,
        exchange: ShortString,
        routing_key: ShortString,
        options: lapin::options::BasicPublishOptions,
        body: &[u8],
        properties: AMQPProperties,
    ) -> Result<(Option<u64>, lapin::PublisherConfirm), CommandError> {
        let _guard = self.command_lock.lock().await;

        // Counted inside the lock, and given back on a failure: a publish the
        // broker never received gets no confirmation, and a wait loop counting
        // on one would never end.
        let delivery_tag = self.publishing();

        let publishing = self
            .channel
            .basic_publish(exchange, routing_key, options, body, properties);

        match tokio::time::timeout(timeout, publishing).await {
            Ok(Ok(confirm)) => Ok((delivery_tag, confirm)),
            Ok(Err(error)) => {
                self.note_protocol_failure(&error);

                if delivery_tag.is_some() {
                    self.publish_failed();
                }

                Err(CommandError::Driver(error))
            }
            Err(_) => {
                if delivery_tag.is_some() {
                    self.publish_failed();
                }

                Err(CommandError::CommandTimeout)
            }
        }
    }

    /// Takes back what `publishing()` counted: a publish the broker never
    /// received gets no confirmation, and a wait loop counting on one would
    /// never end.
    pub fn publish_failed(&self) {
        let mut state = self.state.lock().unwrap();

        if state.pending > 0 {
            state.pending -= 1;
        }
    }

    pub fn record_confirmation(&self, delivery_tag: u64, acked: bool) {
        {
            let mut state = self.state.lock().unwrap();

            push_bounded(
                &mut state.confirmations,
                (delivery_tag, acked),
                CONFIRM_QUEUE_SIZE,
            );

            if state.pending > 0 {
                state.pending -= 1;
            }
        }

        self.changed.notify_waiters();
    }

    pub fn record_return(&self, returned: StoredReturn) {
        {
            let mut state = self.state.lock().unwrap();

            push_bounded(&mut state.returns, returned, RETURN_QUEUE_SIZE);
        }

        self.changed.notify_waiters();
    }

    /// Waits until every message published since the last wait has been
    /// confirmed or rejected, and hands back everything collected on the way,
    /// the returned messages included.
    ///
    /// A channel that was never put into confirm mode has nothing to wait for
    /// and runs into the deadline, which is what the extension does as well.
    pub async fn wait_for_confirms(
        &self,
        flow: &CancellationToken,
        timeout: Duration,
    ) -> Result<(Vec<(u64, bool)>, Vec<StoredReturn>), CommandError> {
        let deadline = if timeout.is_zero() {
            None
        } else {
            Some(tokio::time::Instant::now() + timeout)
        };

        loop {
            // Registered before the state lock is released, so an event that
            // fires in between wakes this waiter instead of being missed.
            let waiting = self.changed.notified();
            tokio::pin!(waiting);
            waiting.as_mut().enable();

            {
                let mut state = self.state.lock().unwrap();

                if state.confirming && state.pending == 0 {
                    return Ok(drain(&mut state));
                }
            }

            let timed_out = match deadline {
                Some(deadline) => tokio::select! {
                    _ = waiting => false,
                    _ = tokio::time::sleep_until(deadline) => true,
                    _ = self.gone.cancelled() => return Err(CommandError::ChannelGone),
                    _ = flow.cancelled() => return Err(CommandError::CommandTimeout),
                },
                None => tokio::select! {
                    _ = waiting => false,
                    _ = self.gone.cancelled() => return Err(CommandError::ChannelGone),
                    _ = flow.cancelled() => return Err(CommandError::CommandTimeout),
                },
            };

            if timed_out {
                return Err(CommandError::WaitTimeout);
            }
        }
    }

    /// Closes the channel, cancelling nothing: closing ends its consumers on
    /// the broker, and a `basic.cancel` sent alongside would arrive out of
    /// order.
    async fn close(&self) {
        {
            let mut state = self.state.lock().unwrap();

            if state.closed {
                return;
            }

            state.closed = true;
            state.consumers.clear();
        }

        // Whatever PHP was still holding on this channel is gone with it: the
        // broker has taken those deliveries back, so they must not stay counted
        // as in flight.
        consumerstats::stats().channel_gone(&self.id);

        // Everything parked on this channel ends now.
        self.gone.cancel();
        self.changed.notify_waiters();

        let _ = tokio::time::timeout(
            CHANNEL_CLOSE_TIMEOUT,
            self.channel.close(200, "sconcur".into()),
        )
        .await;
    }
}

/// Hands over everything collected so far and starts a fresh batch.
fn drain(state: &mut EntryState) -> (Vec<(u64, bool)>, Vec<StoredReturn>) {
    (
        std::mem::take(&mut state.confirmations),
        std::mem::take(&mut state.returns),
    )
}

/// Appends and keeps the tail at most `limit` long, dropping from the front.
///
/// What a channel keeps for a wait loop that has not come. An application may
/// publish in confirm mode, or as mandatory, and never call the matching wait —
/// a returned message carries its whole body, so an unbounded backlog is the
/// channel quietly eating the heap. The oldest go first.
fn push_bounded<T>(values: &mut Vec<T>, value: T, limit: usize) {
    values.push(value);

    if values.len() > limit {
        values.drain(..values.len() - limit);
    }
}

/// What a command can fail with, before it is turned into the scope and code
/// PHP raises.
pub enum CommandError {
    /// A wait loop ran out of the deadline it was given.
    WaitTimeout,
    /// A command outran its own deadline. It is a command failure, not a dead
    /// connection: the next command on the channel simply queues behind the one
    /// still finishing.
    CommandTimeout,
    /// The channel was gone before the wait could end.
    ChannelGone,
    Driver(LapinError),
    /// A failure of this side rather than of the broker — a stream registry
    /// that refused the key, say. It carries its own words because there is no
    /// driver error to read them off.
    Message(String),
}

/// The reply code and text of a broker refusal, when that is what the error is.
pub fn protocol_failure(error: &LapinError) -> Option<(i64, String)> {
    match error.kind() {
        lapin::ErrorKind::ProtocolError(broker) => {
            Some((broker.get_id() as i64, broker.get_message().to_string()))
        }
        _ => None,
    }
}

/// The AMQP 0-9-1 reply codes that close the connection rather than the
/// channel. Not a range: the specification puts CONNECTION-FORCED at 320 and
/// INVALID-PATH at 402, below the 5xx codes and below the channel codes 403 to
/// 406. A threshold would call a broker shutdown — which is a 320 to every
/// connection at once — a channel failure, and PHP would then read it as being
/// about the message rather than about the transport.
pub fn is_connection_close_code(code: i64) -> bool {
    matches!(
        code,
        320 | 402 | 501 | 502 | 503 | 504 | 505 | 506 | 530 | 540 | 541
    )
}

pub struct Channels {
    entries: Mutex<HashMap<String, Arc<ChannelEntry>>>,
    counter: AtomicI64,
    sweeping: std::sync::atomic::AtomicBool,
}

impl Channels {
    pub fn new() -> Self {
        Channels {
            entries: Mutex::new(HashMap::new()),
            counter: AtomicI64::new(0),
            sweeping: std::sync::atomic::AtomicBool::new(false),
        }
    }

    /// Opens a channel on the handle's connection, applies its prefetch and
    /// registers it.
    pub async fn open(
        &'static self,
        handle: &Arc<ConnectionHandle>,
        params: &ChannelOpenParams,
        timeout: Duration,
    ) -> Result<Arc<ChannelEntry>, CommandError> {
        let opening = handle.pooled.connection.create_channel();

        let channel = match tokio::time::timeout(timeout, opening).await {
            Ok(Ok(channel)) => channel,
            Ok(Err(error)) => return Err(CommandError::Driver(error)),
            Err(_) => return Err(CommandError::CommandTimeout),
        };

        // The per-consumer prefetch of a freshly opened channel. The
        // channel-wide form is a Qos command of its own: writing the
        // per-consumer limits clears it, so setting both at open time only ever
        // meant sending one to overwrite the other.
        let qos = channel.basic_qos(
            params.prefetch_count.clamp(0, u16::MAX as i64) as u16,
            BasicQosOptions { global: false },
        );

        match tokio::time::timeout(timeout, qos).await {
            Ok(Ok(())) => {}
            Ok(Err(error)) => {
                let _ = channel.close(200, "sconcur".into()).await;

                return Err(CommandError::Driver(error));
            }
            Err(_) => {
                let _ = channel.close(200, "sconcur".into()).await;

                return Err(CommandError::CommandTimeout);
            }
        }

        if handle.is_released() {
            let _ = channel.close(200, "sconcur".into()).await;

            return Err(CommandError::ChannelGone);
        }

        let entry = Arc::new(ChannelEntry {
            id: format!("amqp:ch:{}", self.counter.fetch_add(1, Ordering::SeqCst) + 1),
            // Counted, not derived from how many channels the handle has:
            // closing a channel would otherwise hand the next one a number
            // another live channel already has.
            number: handle.next_channel_number(),
            handle: handle.clone(),
            channel,
            command_lock: tokio::sync::Mutex::new(()),
            state: Mutex::new(EntryState {
                last_used_at: Some(Instant::now()),
                ..EntryState::default()
            }),
            changed: Notify::new(),
            gone: CancellationToken::new(),
        });

        self.entries
            .lock()
            .unwrap()
            .insert(entry.id.clone(), entry.clone());

        if !self.sweeping.swap(true, Ordering::SeqCst) {
            self.start_sweeper();
        }

        self.watch(entry.clone());

        Ok(entry)
    }

    /// Watches one channel for the broker taking it away with no command of
    /// ours running into it — a channel that only consumes, whose queue is
    /// deleted under it. lapin reports the state rather than announcing the
    /// close, so it is read on a slow tick.
    /// The task ends with the channel.
    fn watch(&'static self, entry: Arc<ChannelEntry>) {
        crate::core::get().runtime().spawn(async move {
            loop {
                tokio::time::sleep(Duration::from_secs(1)).await;

                if entry.is_closed() {
                    return;
                }

                if entry.channel.status().connected() {
                    continue;
                }

                if let Some(entry) = super::channels().detach(&entry.id) {
                    entry.close().await;
                }

                return;
            }
        });
    }

    /// Removes a channel from the registry without closing it. The caller owns
    /// the teardown from there.
    pub fn detach(&self, channel_id: &str) -> Option<Arc<ChannelEntry>> {
        self.entries.lock().unwrap().remove(channel_id)
    }

    /// The channel behind an id, marked as used so the idle sweeper leaves it
    /// alone.
    pub fn find(&self, channel_id: &str) -> Option<Arc<ChannelEntry>> {
        let entry = self.entries.lock().unwrap().get(channel_id).cloned()?;

        entry.touch();

        Some(entry)
    }

    /// Closes one channel and drops it from the registry.
    pub async fn close(&self, channel_id: &str) {
        if let Some(entry) = self.detach(channel_id) {
            entry.close().await;
        }
    }

    /// Counts the channels of one connection handle.
    pub fn used_channels(&self, handle: &Arc<ConnectionHandle>) -> i64 {
        self.entries
            .lock()
            .unwrap()
            .values()
            .filter(|entry| Arc::ptr_eq(&entry.handle, handle))
            .count() as i64
    }

    /// Closes every channel of a connection handle — the handle was released,
    /// or its connection died.
    pub async fn drop_handle(&self, handle: &Arc<ConnectionHandle>) {
        let entries: Vec<Arc<ChannelEntry>> = {
            let mut registry = self.entries.lock().unwrap();

            let matching: Vec<String> = registry
                .iter()
                .filter(|(_, entry)| Arc::ptr_eq(&entry.handle, handle))
                .map(|(id, _)| id.clone())
                .collect();

            matching
                .into_iter()
                .filter_map(|id| registry.remove(&id))
                .collect()
        };

        // Closed at the same time rather than one after another: each close
        // waits up to the close timeout, and a connection holding a dozen
        // channels would otherwise make a disconnect take a dozen timeouts.
        futures_util::future::join_all(entries.iter().map(|entry| entry.close())).await;
    }

    fn start_sweeper(&'static self) {
        crate::core::get().runtime().spawn(async move {
            // Never stopped: the sweeper runs for the life of the process.
            loop {
                tokio::time::sleep(CHANNEL_SWEEP_INTERVAL).await;

                for entry in self.collect_expired() {
                    entry.close().await;
                }
            }
        });
    }

    /// Removes and returns the channels with no consumers that have been idle
    /// longer than the TTL. Closing is left to the caller, outside the lock.
    fn collect_expired(&self) -> Vec<Arc<ChannelEntry>> {
        let mut registry = self.entries.lock().unwrap();

        let Some(moment) = Instant::now().checked_sub(CHANNEL_IDLE_TTL) else {
            return Vec::new();
        };

        let expired: Vec<String> = registry
            .iter()
            .filter(|(_, entry)| entry.is_idle_since(moment))
            .map(|(id, _)| id.clone())
            .collect();

        expired
            .into_iter()
            .filter_map(|id| registry.remove(&id))
            .collect()
    }
}
