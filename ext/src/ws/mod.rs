//! Mirrors ext-go-legacy/internal/ws: the neutral WebSocket plumbing shared by the ws
//! server (accept-side) and, when it lands, the ws client. Like `socket` for the
//! raw TCP features, both depend on this rather than on each other.

pub mod message_state;

pub use message_state::MessageState;

use tokio::sync::{mpsc, oneshot};

/// What an action does to a connection. `Frame` writes one message to the peer;
/// `Close` closes the connection. The numeric values are part of the PHP<->core
/// protocol (the RespondPayload/SendParams `op` field).
#[derive(Clone, Copy, PartialEq, Eq)]
pub enum WriteKind {
    Frame,
    Close,
}

impl WriteKind {
    pub fn from_code(code: i64) -> Self {
        match code {
            1 => WriteKind::Close,
            _ => WriteKind::Frame,
        }
    }
}

/// The one-byte prefix the inbound payload carries to PHP so the Connection can
/// recover the WebSocket message type via `lastMessageWasBinary()`.
pub const MESSAGE_TYPE_TEXT: u8 = 0;
pub const MESSAGE_TYPE_BINARY: u8 = 1;

/// One WebSocket data message read from a connection.
pub struct InboundMessage {
    pub binary: bool,
    pub data: Vec<u8>,
}

/// Prefixes one type byte to the payload so PHP can recover the message type.
pub fn encode_inbound(message: &InboundMessage) -> Vec<u8> {
    let mut encoded = Vec::with_capacity(message.data.len() + 1);

    encoded.push(if message.binary {
        MESSAGE_TYPE_BINARY
    } else {
        MESSAGE_TYPE_TEXT
    });
    encoded.extend_from_slice(&message.data);

    encoded
}

/// Maps a PHP message-type code (1 binary, anything else text).
pub fn is_binary(code: i64) -> bool {
    code as u8 == MESSAGE_TYPE_BINARY
}

pub struct WriteCommand {
    pub kind: WriteKind,
    pub binary: bool,
    pub data: Vec<u8>,
    pub done: oneshot::Sender<Result<(), String>>,
}

/// The rendezvous between a connection's write loop and the PHP handler's
/// commands. As in `socket`, the sender carries both the hand-over and the
/// abandon signal: the write loop dropping its receiver fails a late send
/// instead of hanging it.
pub struct PendingConnection {
    pub commands: mpsc::Sender<WriteCommand>,
    /// Ends the connection's inbound stream on a graceful drain, so a handler
    /// blocked on read() unwinds while it can still write a final message — the
    /// WebSocket mirror of the socket server's read half-close.
    pub drain: tokio_util::sync::CancellationToken,
    pub closed: tokio_util::sync::CancellationToken,
}

impl PendingConnection {
    pub fn start_drain(&self) {
        self.drain.cancel();
    }

    pub fn close(&self) {
        self.drain.cancel();
        self.closed.cancel();
    }
}

pub async fn dispatch(
    pending: &PendingConnection,
    kind: WriteKind,
    binary: bool,
    data: Vec<u8>,
) -> Result<(), String> {
    let (done, outcome) = oneshot::channel();

    if pending
        .commands
        .send(WriteCommand {
            kind,
            binary,
            data,
            done,
        })
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
