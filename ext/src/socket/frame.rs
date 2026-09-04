//! One length-prefixed frame is a
//! big-endian uint32 byte count followed by that many payload bytes.

use tokio::io::{AsyncReadExt, AsyncWriteExt};

/// The fixed size of the length prefix.
const FRAME_LENGTH_SIZE: usize = 4;

pub enum FrameError {
    /// The stream ended on a frame boundary, or the peer went away — a clean end
    /// rather than a failure.
    Closed,
    /// The declared length exceeds maxMessageBytes, so a malicious or buggy peer
    /// cannot make the server allocate an arbitrarily large buffer.
    TooLarge,
    Failed(String),
}

/// Reads one frame. `max_bytes` of 0 or less means no limit.
pub async fn read_frame<R>(reader: &mut R, max_bytes: i64) -> Result<Vec<u8>, FrameError>
where
    R: tokio::io::AsyncRead + Unpin,
{
    let mut header = [0_u8; FRAME_LENGTH_SIZE];

    match reader.read_exact(&mut header).await {
        Ok(_) => {}
        Err(error) if is_closed(&error) => return Err(FrameError::Closed),
        Err(error) => return Err(FrameError::Failed(error.to_string())),
    }

    // Kept unsigned and compared in the u64 domain: a length with the high bit
    // set must not become a negative signed value, slip past the size check and
    // ask for an absurd allocation — a remote crash from a crafted prefix.
    let length = u32::from_be_bytes(header);

    if max_bytes > 0 && length as u64 > max_bytes as u64 {
        return Err(FrameError::TooLarge);
    }

    if length == 0 {
        return Ok(Vec::new());
    }

    let mut payload = vec![0_u8; length as usize];

    match reader.read_exact(&mut payload).await {
        Ok(_) => Ok(payload),
        // A stream that ends mid-frame is still an end, not a protocol error,
        // and is reported as a clean close.
        Err(error) if is_closed(&error) => Err(FrameError::Closed),
        Err(error) => Err(FrameError::Failed(error.to_string())),
    }
}

/// Writes one frame in a single call (length prefix + payload), so a frame is
/// never split into two writes on the wire.
pub async fn write_frame<W>(writer: &mut W, payload: &[u8]) -> Result<(), String>
where
    W: tokio::io::AsyncWrite + Unpin,
{
    let mut buffer = Vec::with_capacity(FRAME_LENGTH_SIZE + payload.len());

    buffer.extend_from_slice(&(payload.len() as u32).to_be_bytes());
    buffer.extend_from_slice(payload);

    writer
        .write_all(&buffer)
        .await
        .map_err(|error| error.to_string())
}

/// Whether a read error means the connection ended normally — the peer closed,
/// our own read side went away, or the idle deadline elapsed. All of those
/// finish the stream cleanly rather than as an error the handler must see.
fn is_closed(error: &std::io::Error) -> bool {
    matches!(
        error.kind(),
        std::io::ErrorKind::UnexpectedEof
            | std::io::ErrorKind::ConnectionReset
            | std::io::ErrorKind::ConnectionAborted
            | std::io::ErrorKind::BrokenPipe
            | std::io::ErrorKind::NotConnected
            | std::io::ErrorKind::TimedOut
    )
}
