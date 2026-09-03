//! Mirrors ext-go-legacy/internal/socket/message_state.go: the inbound frames of one
//! connection, streamed to PHP one frame per `next()`.
//!
//! Shared by the server (a handler reading via Connection::read()) and, when it
//! lands, the client. The read half lives in the state, the way Go keeps the
//! connection and its buffered reader there.

use std::sync::Arc;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{Duration, Instant};
use tokio::net::tcp::OwnedReadHalf;
use tokio_util::sync::CancellationToken;

use crate::dto::{Message, Result};
use crate::errs::Factory;
use crate::helpers::calc_execution_ms;
use crate::socket::frame::{FrameError, read_frame};
use crate::states::{StateCloseFuture, StateContract, StateFuture};

/// How long `next()` still waits for the connection's FIRST frame after the
/// drain signal fires.
///
/// The limiting connection under a concurrency cap is dispatched and drained
/// almost simultaneously, so its first frame may still be on the wire when the
/// drain begins; without this window the handler gets EOF and that exchange is
/// silently bounced. A connection that has already delivered a frame keeps the
/// immediate EOF.
///
/// Server-only — a client passes zero. There the same token means "the
/// connection is over", not "stop taking new input", and holding the stream open
/// a quarter of a second past that would be a regression, not a fix.
pub const FIRST_FRAME_DRAIN_GRACE: Duration = Duration::from_millis(250);

pub struct MessageState {
    message: Arc<Message>,
    reader: tokio::sync::Mutex<OwnedReadHalf>,
    /// The idle timeout between frames. Zero means a connection may stay idle
    /// forever.
    read_timeout: Duration,
    max_message_bytes: i64,
    err_factory: &'static Factory,
    start_time: Instant,
    /// Ends the stream on a graceful drain: Go half-closes the socket so the
    /// blocked read returns EOF, and this is the local equivalent.
    read_stopped: CancellationToken,
    /// How many frames this connection has handed to its handler. Only the
    /// first-or-not distinction is used, but a counter says what it means where
    /// a flag would not.
    delivered: AtomicU64,
    /// Zero disables the first-frame window; see FIRST_FRAME_DRAIN_GRACE.
    first_frame_drain_grace: Duration,
}

impl MessageState {
    pub fn new(
        message: Arc<Message>,
        reader: OwnedReadHalf,
        read_timeout_ms: i64,
        max_message_bytes: i64,
        err_factory: &'static Factory,
        read_stopped: CancellationToken,
        first_frame_drain_grace: Duration,
    ) -> Self {
        MessageState {
            message,
            reader: tokio::sync::Mutex::new(reader),
            read_timeout: Duration::from_millis(read_timeout_ms.max(0) as u64),
            max_message_bytes,
            err_factory,
            start_time: Instant::now(),
            read_stopped,
            delivered: AtomicU64::new(0),
            first_frame_drain_grace,
        }
    }

    /// A clean connection end: the stream finishes so the PHP loop exits and the
    /// connection coroutine completes without an error reaching the handler.
    fn finished(&self) -> Result {
        Result::success(&self.message, Vec::new(), calc_execution_ms(self.start_time))
    }

    fn delivered(&self, frame: Vec<u8>) -> Result {
        self.delivered.fetch_add(1, Ordering::Relaxed);

        Result::success_with_next(&self.message, frame, calc_execution_ms(self.start_time))
    }
}

impl StateContract for MessageState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            let mut reader = self.reader.lock().await;

            let read = read_frame(&mut *reader, self.max_message_bytes);

            // Pinned so the drain arm below can keep waiting on this very
            // future: dropping it would throw away whatever of a partial frame
            // it has already taken off the socket.
            tokio::pin!(read);

            // `biased`, and the read polled first. With a frame readable and the
            // drain signal already fired, an unbiased select picks at random and
            // can answer EOF while the bytes sit there unread. Go cannot lose
            // them — its drain half-closes the socket, and read() must hand back
            // what is buffered before it may report EOF — so this ordering is
            // what makes the two cores behave alike.
            let outcome = if self.read_timeout.is_zero() {
                tokio::select! {
                    biased;

                    outcome = &mut read => Some(outcome),
                    _ = self.read_stopped.cancelled() => None,
                }
            } else {
                tokio::select! {
                    biased;

                    outcome = &mut read => Some(outcome),
                    // The idle deadline ends the stream cleanly, exactly as Go's
                    // read deadline does.
                    _ = tokio::time::sleep(self.read_timeout) => return self.finished(),
                    _ = self.read_stopped.cancelled() => None,
                }
            };

            let outcome = match outcome {
                Some(outcome) => outcome,
                // Drained while this call was parked. A connection that has
                // already delivered something ends now; one that has not gets a
                // bounded window, because its first frame may still be on the
                // wire — the limiting connection under a concurrency cap is
                // dispatched and drained almost at once.
                None => {
                    if self.delivered.load(Ordering::Relaxed) > 0
                        || self.first_frame_drain_grace.is_zero()
                    {
                        return self.finished();
                    }

                    match tokio::time::timeout(self.first_frame_drain_grace, &mut read).await {
                        Ok(outcome) => outcome,
                        Err(_) => return self.finished(),
                    }
                }
            };

            match outcome {
                Ok(frame) => self.delivered(frame),
                Err(FrameError::Closed) => self.finished(),
                Err(FrameError::TooLarge) => Result::error(
                    &self.message,
                    self.err_factory
                        .by_text("read frame: message frame exceeds maxMessageBytes"),
                ),
                Err(FrameError::Failed(error)) => Result::error(
                    &self.message,
                    self.err_factory.by_text(&format!("read frame: {error}")),
                ),
            }
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            // The connection itself is closed by its owner (the server's
            // connection task); nothing to do here.
        })
    }
}
