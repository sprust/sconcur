//! Mirrors ext/internal/features/wsserver/message_state.go: the inbound data
//! messages of one connection, streamed to PHP one message per `next()`.
//!
//! The connection is pumped by a dedicated read task, so control frames are
//! processed even when the handler is push-only and never reads; this state only
//! sees the data messages that task forwards.

use std::sync::Arc;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{Duration, Instant};
use tokio::sync::mpsc;
use tokio_util::sync::CancellationToken;

use crate::dto::{Message, Result};
use crate::helpers::calc_execution_ms;
use crate::states::{StateCloseFuture, StateContract, StateFuture};
use crate::ws::{InboundMessage, encode_inbound};

/// How long `next()` still waits for the connection's FIRST inbound message
/// after the drain signal fires.
///
/// The limiting connection under a concurrency cap is dispatched and drained
/// almost simultaneously, so its first message may still be on the wire when the
/// drain begins; without this window the handler gets EOF and that exchange is
/// silently bounced. A connection that has already delivered a message keeps the
/// immediate EOF.
const FIRST_MESSAGE_DRAIN_GRACE: Duration = Duration::from_millis(250);

pub struct MessageState {
    message: Arc<Message>,
    messages: tokio::sync::Mutex<mpsc::Receiver<InboundMessage>>,
    drain: CancellationToken,
    delivered: AtomicU64,
    start_time: Instant,
}

impl MessageState {
    pub fn new(
        message: Arc<Message>,
        messages: mpsc::Receiver<InboundMessage>,
        drain: CancellationToken,
    ) -> Self {
        MessageState {
            message,
            messages: tokio::sync::Mutex::new(messages),
            drain,
            delivered: AtomicU64::new(0),
            start_time: Instant::now(),
        }
    }

    fn finished(&self) -> Result {
        Result::success(&self.message, Vec::new(), calc_execution_ms(self.start_time))
    }

    fn delivered(&self, message: InboundMessage) -> Result {
        self.delivered.fetch_add(1, Ordering::Relaxed);

        Result::success_with_next(
            &self.message,
            encode_inbound(&message),
            calc_execution_ms(self.start_time),
        )
    }
}

impl StateContract for MessageState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            let mut messages = self.messages.lock().await;

            // A message that already arrived wins over the drain signal. With
            // both ready, a plain select would pick at random and could drop a
            // buffered message, answering EOF while data was waiting — the
            // half-close semantic is "stop new input, flush what arrived".
            match messages.try_recv() {
                Ok(message) => return self.delivered(message),
                Err(mpsc::error::TryRecvError::Disconnected) => return self.finished(),
                Err(mpsc::error::TryRecvError::Empty) => {}
            }

            if self.drain.is_cancelled() {
                if self.delivered.load(Ordering::Relaxed) > 0 {
                    return self.finished();
                }

                return match tokio::time::timeout(FIRST_MESSAGE_DRAIN_GRACE, messages.recv()).await
                {
                    Ok(Some(message)) => self.delivered(message),
                    _ => self.finished(),
                };
            }

            tokio::select! {
                biased;

                received = messages.recv() => match received {
                    Some(message) => self.delivered(message),
                    None => self.finished(),
                },
                _ = self.drain.cancelled() => {
                    // Same rule on the racing path: flush a message that landed
                    // with the signal rather than answering EOF over it.
                    match messages.try_recv() {
                        Ok(message) => self.delivered(message),
                        _ => self.finished(),
                    }
                }
            }
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            // The connection is closed by its owner (the server's connection
            // task); nothing to do here.
        })
    }
}
