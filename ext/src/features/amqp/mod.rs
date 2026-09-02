//! Mirrors ext-go-legacy/internal/features/amqp: the AMQP 0-9-1 client.
//!
//! The connection, the channels, the topology, publishing and consuming all
//! live here; PHP stays a thin orchestrator. See docs/amqp.md for the shape the
//! feature exposes and .ai/plans/amqp-rust-port.md for how it was ported.

pub mod channels;
pub mod commands;
pub mod connections;
pub mod consume_serve;
pub mod consume_state;
pub mod consumerstats;
pub mod payloads;
pub mod values;

use std::sync::Arc;
use std::time::Instant;

use crate::dto::Result;
use crate::errs::Factory;
use crate::features::{BoxFuture, Feature};
use crate::helpers::calc_execution_ms;
use crate::tasks::Task;

use channels::{ChannelEntry, Channels, CommandError};
use connections::Connections;

pub static ERRORS: Factory = Factory::new("amqp");

// The scope markers a failure is prefixed with, so the PHP side knows which
// exception to raise and what the failure did to the resource. The payload of a
// failed task is "<scope>:<code>: <text>", where the code is the AMQP reply code
// (0 when the broker named none) — ext-amqp puts that code into the exception,
// and application code branches on it.

/// The broker is unreachable or the connection died. PHP raises
/// `ConnectionException`, whichever exception the caller asked for.
pub const SCOPE_NETWORK: &str = "net";
/// The broker closed the channel over this failure. PHP raises the caller's
/// exception and marks its `Channel` closed.
pub const SCOPE_CHANNEL: &str = "chn";
/// The channel was already gone when this command reached it, and the broker
/// said why. It is not `SCOPE_CHANNEL`, because that one means "the broker
/// refused this method" and PHP raises the exception of the call — a confirm
/// wait that finds the channel closed by an earlier publish's 404 is not a
/// confirm timeout. Here the failure belongs to the channel, so PHP always
/// raises `ChannelException`, carrying the reply code that actually closed it.
pub const SCOPE_CHANNEL_GONE: &str = "chg";
/// The command failed with the channel left usable.
pub const SCOPE_COMMAND: &str = "err";

/// What a wait loop reports when its deadline passes. It reaches PHP unwrapped,
/// where it becomes `PublishConfirmTimeoutException`, so nothing matches on the
/// wording and it can simply read as English.
pub const WAIT_TIMEOUT_TEXT: &str = "wait timeout exceeded";
/// What a prefetch size is refused with.
///
/// `basic.qos` carries a prefetch-size field, and RabbitMQ has never
/// implemented it — it answers a non-zero one with 540 NOT_IMPLEMENTED, which
/// is a connection-level refusal. The driver here goes further: `amq-protocol`
/// leaves the field out of its generated `basic.qos` altogether, so it is
/// physically not sendable, and a size asked for would be dropped on the floor
/// rather than refused by the broker.
///
/// Dropping a limit somebody set is the one thing not to do quietly, so it is
/// refused here instead — the same outcome the broker gives, named by the side
/// that actually made the decision.
pub const PREFETCH_SIZE_TEXT: &str =
    "prefetch size is not supported: RabbitMQ has never implemented basic.qos's \
     prefetch-size, and this core cannot send one";

/// What a command that outran the deadline PHP gave it reports. It reaches PHP
/// as a command-scope failure, so the connection and the channel it ran on stay
/// usable — the next command simply queues behind the one still finishing.
pub const COMMAND_TIMEOUT_TEXT: &str = "command timeout exceeded";

/// The feature's registries. They live on the process `Core` rather than in
/// statics of their own so a fork throws them away with everything else,
/// instead of leaving the child holding a map of the parent's connections
/// behind a mutex that may have been locked at the moment of the fork.
pub struct Registries {
    pub connections: Connections,
    pub channels: Channels,
    pub consume_serve: consume_serve::Streams,
}

impl Registries {
    pub fn new() -> Self {
        Registries {
            connections: Connections::new(),
            channels: Channels::new(),
            consume_serve: consume_serve::Streams::new(),
        }
    }
}

pub fn registries() -> &'static Registries {
    crate::core::get().amqp()
}

pub fn channels() -> &'static Channels {
    &registries().channels
}

pub fn connections() -> &'static Connections {
    &registries().connections
}

pub struct AmqpFeature;

static INSTANCE: AmqpFeature = AmqpFeature;

pub fn get() -> &'static AmqpFeature {
    &INSTANCE
}

impl Feature for AmqpFeature {
    fn handle(&self, task: Task) -> BoxFuture {
        Box::pin(async move {
            let message = task.message();

            let envelope: payloads::Envelope = match rmp_serde::from_slice(&message.payload) {
                Ok(envelope) => envelope,
                Err(error) => {
                    task.add_result(Result::error(
                        message,
                        ERRORS.by_err("parse envelope", error),
                    ))
                    .await;

                    return;
                }
            };

            commands::dispatch(&task, &envelope).await;
        })
    }

    /// A detached push carries no flow and awaits no result: it is the last
    /// word of a PHP object that was destroyed with its coroutine. Only the
    /// commands that release a resource are accepted, and they run off the PHP
    /// thread — this runs inside `push()`, and closing a channel waits on the
    /// broker.
    fn handle_detached(&self, task: Task) {
        let message = task.message();

        let Ok(envelope) = rmp_serde::from_slice::<payloads::Envelope>(&message.payload) else {
            return;
        };

        crate::core::get().runtime().spawn(async move {
            commands::dispatch_detached(&envelope).await;
        });
    }
}

/// Closes every channel and connection the feature holds. A process that never
/// opened one is left alone.
///
/// Synchronous, because `destroy()` is: it runs on the PHP thread, which is not
/// a runtime worker, so blocking on the runtime there is safe. The bound is
/// what keeps a broker that stopped answering from wedging `destroy()` — the
/// sockets go with the process either way.
pub fn shutdown() {
    consumerstats::stop_telemetry();

    if !connections().ever_opened() {
        return;
    }

    crate::core::get().runtime().block_on(async {
        let _ = tokio::time::timeout(
            std::time::Duration::from_secs(5),
            connections().close_all(),
        )
        .await;
    });
}

// --- answering a task ---------------------------------------------------------

/// Answers the task with an already encoded payload.
pub async fn respond(task: &Task, payload: Vec<u8>, start_time: Instant) {
    task.add_result(Result::success(
        task.message(),
        payload,
        calc_execution_ms(start_time),
    ))
    .await;
}

/// Answers a command that reports nothing but its success.
pub async fn respond_done(task: &Task, start_time: Instant) {
    task.add_result(Result::success(
        task.message(),
        Vec::new(),
        calc_execution_ms(start_time),
    ))
    .await;
}

/// Answers the task with an error carrying its scope and the AMQP reply code.
/// The channel the command ran on (`None` when there is none) decides how a
/// "not open" failure is classified: the same driver error means a dead channel
/// in one case and a dead connection in the other.
pub async fn fail(task: &Task, entry: Option<&Arc<ChannelEntry>>, what: &str, error: CommandError) {
    let (scope, code, text) = classify(entry, what, error);

    task.add_result(Result::error(task.message(), error_payload(&scope, code, &text)))
        .await;
}

pub fn error_payload(scope: &str, code: i64, text: &str) -> String {
    format!("{scope}:{code}: {text}")
}

/// Marks a failure that happened before any channel existed — a dial that could
/// not reach the broker.
pub fn network_error_payload(text: &str) -> String {
    error_payload(SCOPE_NETWORK, 0, text)
}

/// Turns a command failure into the scope, the reply code and the message PHP
/// will raise.
pub fn classify(
    entry: Option<&Arc<ChannelEntry>>,
    what: &str,
    error: CommandError,
) -> (String, i64, String) {
    match error {
        CommandError::WaitTimeout => (
            SCOPE_COMMAND.to_string(),
            0,
            WAIT_TIMEOUT_TEXT.to_string(),
        ),
        // A command that outran its own deadline is a command failure, not a
        // dead connection.
        CommandError::CommandTimeout => (
            SCOPE_COMMAND.to_string(),
            0,
            COMMAND_TIMEOUT_TEXT.to_string(),
        ),
        CommandError::ChannelGone => match entry {
            Some(entry) => channel_gone_failure(entry),
            None => (SCOPE_CHANNEL.to_string(), 0, "No channel available.".to_string()),
        },
        CommandError::Message(text) => (SCOPE_COMMAND.to_string(), 0, ERRORS.by_text(&text)),
        CommandError::Driver(driver) => classify_driver(entry, what, driver),
    }
}

fn classify_driver(
    entry: Option<&Arc<ChannelEntry>>,
    what: &str,
    error: lapin::Error,
) -> (String, i64, String) {
    // A broker refusal names its own code, and the code says whether it took
    // the channel or the whole connection with it.
    if let Some((code, reason)) = channels::protocol_failure(&error) {
        if channels::is_connection_close_code(code) {
            return (
                SCOPE_NETWORK.to_string(),
                code,
                format!("Server connection error: {code}, message: {reason}"),
            );
        }

        return (
            SCOPE_CHANNEL.to_string(),
            code,
            format!("Server channel error: {code}, message: {reason}"),
        );
    }

    // A channel or connection the driver refuses to use because it is no longer
    // open. Which of the two died is decided by asking the connection, not by
    // asking whether this side has noticed the channel go: in the window
    // between the two, a channel-level 404 would otherwise be reported as a
    // dead connection, marking every other channel of that connection unusable
    // over one bad routing key.
    if matches!(
        error.kind(),
        lapin::ErrorKind::InvalidChannelState(..) | lapin::ErrorKind::InvalidChannel(..)
    ) {
        if let Some(entry) = entry {
            if !entry.connection_closed() {
                return channel_gone_failure(entry);
            }
        }

        return (
            SCOPE_NETWORK.to_string(),
            0,
            ERRORS.by_err(what, error),
        );
    }

    if matches!(
        error.kind(),
        lapin::ErrorKind::IOError(..)
            | lapin::ErrorKind::InvalidConnectionState(..)
            | lapin::ErrorKind::MissingHeartbeatError
            | lapin::ErrorKind::RuntimeShutdownError(..)
    ) {
        return (SCOPE_NETWORK.to_string(), 0, ERRORS.by_err(what, error));
    }

    (SCOPE_COMMAND.to_string(), 0, ERRORS.by_err(what, error))
}

/// Describes a channel that is no longer usable, naming what closed it when the
/// broker said so.
///
/// What it buys is the cause: a 404 or a 406 the broker answered a publish with
/// is otherwise invisible, because `basic.publish` carries no reply and the next
/// command on that channel could only say the channel was gone.
fn channel_gone_failure(entry: &Arc<ChannelEntry>) -> (String, i64, String) {
    match entry.close_reason() {
        Some((code, reason)) => (
            SCOPE_CHANNEL_GONE.to_string(),
            code,
            format!("Server channel error: {code}, message: {reason}"),
        ),
        None => (
            SCOPE_CHANNEL.to_string(),
            0,
            "No channel available.".to_string(),
        ),
    }
}
