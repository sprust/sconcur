//! Mirrors ext-go-legacy/internal/socket/message_state.go: the inbound frames of one
//! connection, streamed to PHP one frame per `next()`.
//!
//! Shared by the server (a handler reading via Connection::read()) and, when it
//! lands, the client. The read half lives in the state, the way Go keeps the
//! connection and its buffered reader there.

use std::sync::Arc;
use std::time::{Duration, Instant};
use tokio::net::tcp::OwnedReadHalf;
use tokio_util::sync::CancellationToken;

use crate::dto::{Message, Result};
use crate::errs::Factory;
use crate::helpers::calc_execution_ms;
use crate::socket::frame::{FrameError, read_frame};
use crate::states::{StateCloseFuture, StateContract, StateFuture};

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
}

impl MessageState {
    pub fn new(
        message: Arc<Message>,
        reader: OwnedReadHalf,
        read_timeout_ms: i64,
        max_message_bytes: i64,
        err_factory: &'static Factory,
        read_stopped: CancellationToken,
    ) -> Self {
        MessageState {
            message,
            reader: tokio::sync::Mutex::new(reader),
            read_timeout: Duration::from_millis(read_timeout_ms.max(0) as u64),
            max_message_bytes,
            err_factory,
            start_time: Instant::now(),
            read_stopped,
        }
    }

    /// A clean connection end: the stream finishes so the PHP loop exits and the
    /// connection coroutine completes without an error reaching the handler.
    fn finished(&self) -> Result {
        Result::success(&self.message, Vec::new(), calc_execution_ms(self.start_time))
    }
}

impl StateContract for MessageState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            let mut reader = self.reader.lock().await;

            let read = read_frame(&mut *reader, self.max_message_bytes);

            let outcome = if self.read_timeout.is_zero() {
                tokio::select! {
                    _ = self.read_stopped.cancelled() => return self.finished(),
                    outcome = read => outcome,
                }
            } else {
                tokio::select! {
                    _ = self.read_stopped.cancelled() => return self.finished(),
                    // The idle deadline ends the stream cleanly, exactly as Go's
                    // read deadline does.
                    _ = tokio::time::sleep(self.read_timeout) => return self.finished(),
                    outcome = read => outcome,
                }
            };

            match outcome {
                Ok(frame) => Result::success_with_next(
                    &self.message,
                    frame,
                    calc_execution_ms(self.start_time),
                ),
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
