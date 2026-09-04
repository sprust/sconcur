//! Mirrors ext-go-legacy/internal/features/wsserver/message_state.go: the inbound data
//! messages of one connection, streamed to PHP one message per `next()`.
//!
//! The connection is pumped by a dedicated read task, so control frames are
//! processed even when the handler is push-only and never reads; this state only
//! sees the data messages that task forwards.
//!
//! Kept here rather than in the server because the client needs the same thing.
//! Go carries two copies; the only server-specific part is the drain grace, and
//! a client simply never fires that token.

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

    /// What a drained stream answers: a message already in hand wins, a
    /// connection that has delivered one ends immediately, and one that has not
    /// gets a bounded window for its first — its request may still be on the
    /// wire.
    async fn drained(&self, messages: &mut mpsc::Receiver<InboundMessage>) -> Result {
        if let Ok(message) = messages.try_recv() {
            return self.delivered(message);
        }

        if self.delivered.load(Ordering::Relaxed) > 0 {
            return self.finished();
        }

        match tokio::time::timeout(FIRST_MESSAGE_DRAIN_GRACE, messages.recv()).await {
            Ok(Some(message)) => self.delivered(message),
            _ => self.finished(),
        }
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
                return self.drained(&mut messages).await;
            }

            tokio::select! {
                biased;

                received = messages.recv() => match received {
                    Some(message) => self.delivered(message),
                    None => self.finished(),
                },
                // The drain arriving while this call is already parked is the
                // common case, not the rare one: the connection that reaches the
                // limit is dispatched and drained almost at once, so its handler
                // is usually waiting here when the signal lands. It gets the same
                // grace as one that arrived after — answering EOF here bounced
                // that connection's first exchange, which is what made the
                // maxConnections test flaky.
                _ = self.drain.cancelled() => self.drained(&mut messages).await,
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

#[cfg(test)]
mod tests {
    //! The drain semantics, which the socket state has its own copy of and its
    //! own tests for. This one was written to the same rules and checked only by
    //! the PHP suite, which catches a wrong choice here statistically — the
    //! WsServerMaxConnections case, once in a run of runs.
    //!
    //! The half-close semantic is "stop new input, flush what arrived", and every
    //! test below is one clause of it.

    use super::*;

    use crate::dto::Message;
    use crate::types::method::Method;

    fn message() -> Arc<Message> {
        Arc::new(Message {
            flow_key: "flow".to_string(),
            method: Method::WsServe,
            task_key: "task".to_string(),
            payload: Vec::new(),
            is_next: false,
            owner_id: 0,
        })
    }

    fn inbound(text: &str) -> InboundMessage {
        InboundMessage {
            binary: false,
            data: text.as_bytes().to_vec(),
        }
    }

    /// The payload PHP would see, with the type byte encode_inbound prefixes.
    fn payload_of(result: &Result) -> String {
        String::from_utf8_lossy(&result.payload[1..]).to_string()
    }

    /// A message already in hand wins over a drain that has already fired. An
    /// unbiased choice here answers EOF while data is waiting, which drops the
    /// exchange the handler was about to serve.
    #[tokio::test]
    async fn a_buffered_message_wins_over_a_fired_drain() {
        let (tx, rx) = mpsc::channel(4);
        let drain = CancellationToken::new();

        tx.send(inbound("first")).await.unwrap();
        drain.cancel();

        let state = MessageState::new(message(), rx, drain);
        let result = state.next().await;

        assert!(result.has_next, "the buffered message should have been delivered");
        assert_eq!(payload_of(&result), "first");
    }

    /// A connection that has already delivered something ends at once when the
    /// drain fires: its exchange is over, and the grace below is not for it.
    #[tokio::test]
    async fn a_connection_that_delivered_ends_at_once() {
        let (tx, rx) = mpsc::channel(4);
        let drain = CancellationToken::new();

        tx.send(inbound("first")).await.unwrap();

        let state = MessageState::new(message(), rx, drain.clone());

        assert!(state.next().await.has_next);

        drain.cancel();

        let started = Instant::now();
        let result = state.next().await;

        assert!(!result.has_next, "a delivered connection should end on drain");
        assert!(
            started.elapsed() < FIRST_MESSAGE_DRAIN_GRACE / 2,
            "it should not have waited out the first-message grace",
        );
    }

    /// The other half: a connection that has NOT delivered gets a bounded window
    /// for its first message, because the connection at the concurrency limit is
    /// dispatched and drained almost together and its first message may still be
    /// in flight. Without the window that exchange is bounced.
    #[tokio::test]
    async fn a_first_message_still_in_flight_gets_its_window() {
        let (tx, rx) = mpsc::channel(4);
        let drain = CancellationToken::new();

        drain.cancel();

        let state = MessageState::new(message(), rx, drain);

        tokio::spawn(async move {
            tokio::time::sleep(Duration::from_millis(30)).await;

            let _ = tx.send(inbound("late but wanted")).await;
        });

        let result = state.next().await;

        assert!(result.has_next, "the first message should have been waited for");
        assert_eq!(payload_of(&result), "late but wanted");
    }

    /// The drain landing while next() is already parked is the common case, not
    /// the rare one, and it gets the same window as one that fired before the
    /// call. Answering EOF here is what made the max-connections case flaky.
    #[tokio::test]
    async fn a_drain_that_lands_while_parked_still_gives_the_window() {
        let (tx, rx) = mpsc::channel(4);
        let drain = CancellationToken::new();
        let state = MessageState::new(message(), rx, drain.clone());

        tokio::spawn(async move {
            tokio::time::sleep(Duration::from_millis(10)).await;

            drain.cancel();

            tokio::time::sleep(Duration::from_millis(20)).await;

            let _ = tx.send(inbound("after the drain")).await;
        });

        let result = state.next().await;

        assert!(result.has_next, "the parked call should have been given the window");
        assert_eq!(payload_of(&result), "after the drain");
    }

    /// A window that expires with nothing on the wire ends the stream rather
    /// than waiting on a connection nobody is going to use.
    #[tokio::test]
    async fn an_empty_window_ends_the_stream() {
        let (tx, rx) = mpsc::channel(4);
        let drain = CancellationToken::new();

        drain.cancel();

        let state = MessageState::new(message(), rx, drain);
        let started = Instant::now();
        let result = state.next().await;

        drop(tx);

        assert!(!result.has_next);
        assert!(
            started.elapsed() >= FIRST_MESSAGE_DRAIN_GRACE,
            "it should have waited out the whole window before giving up",
        );
    }

    /// The connection going away ends the stream, drain or no drain.
    #[tokio::test]
    async fn a_closed_connection_ends_the_stream() {
        let (tx, rx) = mpsc::channel::<InboundMessage>(4);

        drop(tx);

        let state = MessageState::new(message(), rx, CancellationToken::new());

        assert!(!state.next().await.has_next);
    }
}
