//! Mirrors ext/internal/socket: the length-prefix framing and per-connection I/O
//! shared by the socket server (accept-side) and the socket client (dial-side).
//! Neutral infrastructure with no Method of its own; a feature depends on this,
//! not on the other feature.

pub mod frame;
pub mod message_state;

use std::sync::atomic::{AtomicI64, Ordering};
use tokio::sync::{mpsc, oneshot};

pub use frame::write_frame;
pub use message_state::MessageState;

/// What an action does to a connection. `Frame` writes one length-prefixed frame
/// to the peer; `Close` closes the connection. The numeric values are part of
/// the PHP<->core protocol (the RespondPayload/SendPayload `op` field).
#[derive(Clone, Copy, PartialEq, Eq)]
pub enum WriteKind {
    Frame = 0,
    Close = 1,
}

impl WriteKind {
    pub fn from_code(code: i64) -> Self {
        match code {
            1 => WriteKind::Close,
            _ => WriteKind::Frame,
        }
    }
}

/// One action a PHP handler performs on its connection. `done` carries the
/// outcome back to the issuing coroutine, so the handler gets real write
/// backpressure and learns about a dead connection.
pub struct WriteCommand {
    pub kind: WriteKind,
    pub data: Vec<u8>,
    pub done: oneshot::Sender<Result<(), String>>,
}

/// The rendezvous between a connection's write loop and the PHP handler's
/// write/close commands.
///
/// Go needs three channels here — commands, abandoned, and a per-command done —
/// because a send and an abandon have to be selected over. A bounded sender
/// carries the first two: the write loop dropping its receiver *is* the
/// abandon signal, and a send onto a dropped channel fails instead of hanging.
pub struct PendingConnection {
    pub commands: mpsc::Sender<WriteCommand>,
    /// Ends the connection's inbound stream on a graceful drain, so a handler
    /// reading in a loop finishes while it can still write a final frame.
    pub read_stopped: tokio_util::sync::CancellationToken,
    /// Force-closes the connection once the drain grace elapses, so a push-only
    /// handler that never reads still unwinds.
    pub closed: tokio_util::sync::CancellationToken,
}

impl PendingConnection {
    /// Ends the read side only (graceful drain). Go half-closes the TCP
    /// connection so a blocked read returns EOF; here the read future is
    /// cancelled instead, which ends the handler's loop the same way. The
    /// difference is on the wire — the peer is not sent a FIN until the
    /// connection is closed for good.
    pub fn close_read(&self) {
        self.read_stopped.cancel();
    }

    pub fn close(&self) {
        self.read_stopped.cancel();
        self.closed.cancel();
    }
}

/// Hands one command to the connection's write loop and waits for it to be
/// applied, so the handler coroutine only continues once the bytes hit the wire.
/// A closed channel means the write loop has stopped consuming — the handler
/// unwinds instead of hanging.
pub async fn dispatch(
    pending: &PendingConnection,
    kind: WriteKind,
    data: Vec<u8>,
) -> Result<(), String> {
    let (done, outcome) = oneshot::channel();

    if pending
        .commands
        .send(WriteCommand { kind, data, done })
        .await
        .is_err()
    {
        return Err("connection abandoned".to_string());
    }

    match outcome.await {
        Ok(result) => result,
        Err(_) => Err("connection abandoned".to_string()),
    }
}

/// Backs `next_connection_id`. Shared across server and client so an id is
/// unique within the process.
static CONNECTION_COUNTER: AtomicI64 = AtomicI64::new(0);

/// Mints a unique connection id scoped to a flow: `<flowKey>:c:<n>`.
pub fn next_connection_id(flow_key: &str) -> String {
    format!(
        "{}:c:{}",
        flow_key,
        CONNECTION_COUNTER.fetch_add(1, Ordering::Relaxed) + 1
    )
}
