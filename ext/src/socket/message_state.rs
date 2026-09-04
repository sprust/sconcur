//! The inbound frames of one
//! connection, streamed to PHP one frame per `next()`.
//!
//! Shared by the server (a handler reading via Connection::read()) and, when it
//! lands, the client. The read half lives in the state, beside the connection
//! and its buffered reader.

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
    /// Ends the stream on a graceful drain. A half-close of the socket would do
    /// the same by making the blocked read return EOF; this is the local
    /// equivalent, and the difference is what the drain tests below pin.
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
            // can answer EOF while the bytes sit there unread. A half-closing
            // drain could not lose them, because a read must hand back what is
            // buffered before it may report EOF, so this ordering is
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
                    // The idle deadline ends the stream cleanly, the way a read
                    // deadline would.
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

#[cfg(test)]
mod tests {
    //! The drain semantics, which SocketServerMaxConnectionsTest kept catching
    //! from the outside.
    //!
    //! A drain that half-closed the socket would hand back a frame already
    //! buffered before EOF, and one still on the wire would arrive normally.
    //! Cancelling a token instead can pre-empt a read that was about to succeed
    //! — so both halves of the compensation are asserted here rather than left
    //! to the PHP suite to catch statistically, once in forty runs.

    use std::time::Duration;
    use tokio::net::{TcpListener, TcpStream};

    use super::*;
    use crate::dto::Message;
    use crate::socket::frame::write_frame;
    use crate::types::method::Method;

    static ERRORS: Factory = Factory::new("socket");

    /// A connected pair: the state reads the server end, the test writes the
    /// client end. A real socket rather than a mock, because what is being
    /// asserted is a race between a read of one and a cancellation.
    async fn connected_pair() -> (TcpStream, TcpStream) {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();

        let connecting = tokio::spawn(async move { TcpStream::connect(address).await.unwrap() });

        let (server, _) = listener.accept().await.unwrap();
        let client = connecting.await.unwrap();

        (server, client)
    }

    fn message() -> Arc<Message> {
        Arc::new(Message {
            flow_key: "flow".to_string(),
            method: Method::SocketServe,
            task_key: "task".to_string(),
            payload: Vec::new(),
            is_next: false,
            owner_id: 0,
        })
    }

    fn state(server: TcpStream, drain: CancellationToken, grace: Duration) -> MessageState {
        let (reader, _writer) = server.into_split();

        // The write half is dropped: nothing here writes back, and keeping it
        // would hold the connection open past the test.
        MessageState::new(message(), reader, 0, 0, &ERRORS, drain, grace)
    }

    /// The regression the biased select exists for, on the second frame.
    ///
    /// Both branches are ready — the frame is in the socket buffer and the drain
    /// has fired — and an unbiased select answers EOF about half the time. It has
    /// to be the SECOND frame: on the first the window below would rescue the
    /// read anyway, because the pinned future is handed to the timeout with the
    /// buffered bytes still in it. Past the first delivery there is no window,
    /// and the ordering is the only thing left.
    ///
    /// Repeated, because a coin flip asserted once is not an assertion.
    #[tokio::test]
    async fn a_buffered_frame_wins_over_a_fired_drain_after_a_delivery() {
        for attempt in 0..20 {
            let (server, mut client) = connected_pair().await;
            let drain = CancellationToken::new();

            let state = state(server, drain.clone(), FIRST_FRAME_DRAIN_GRACE);

            write_frame(&mut client, b"first").await.unwrap();

            assert!(state.next().await.has_next, "attempt {attempt}: the first frame was lost");

            write_frame(&mut client, b"second").await.unwrap();

            // Let the bytes land in the receive buffer, so the read really is
            // ready when the drain fires.
            tokio::time::sleep(Duration::from_millis(20)).await;

            drain.cancel();

            let result = state.next().await;

            assert!(result.has_next, "attempt {attempt}: the drain swallowed a buffered frame");
            assert_eq!(result.payload, b"second");
        }
    }

    /// The same ordering on the client's shape, where there is no window at all
    /// and so nothing else can save a buffered frame.
    #[tokio::test]
    async fn a_buffered_frame_wins_over_a_fired_drain_without_a_window() {
        for attempt in 0..20 {
            let (server, mut client) = connected_pair().await;
            let drain = CancellationToken::new();

            write_frame(&mut client, b"ping").await.unwrap();

            tokio::time::sleep(Duration::from_millis(20)).await;

            drain.cancel();

            let state = state(server, drain, Duration::ZERO);
            let result = state.next().await;

            assert!(result.has_next, "attempt {attempt}: the drain swallowed a buffered frame");
            assert_eq!(result.payload, b"ping");
        }
    }

    /// The window the biased select cannot cover: when the drain lands the read
    /// is honestly pending, because the frame is still on the wire. The limiting
    /// connection under a concurrency cap is dispatched and drained almost at
    /// once, which is exactly this.
    #[tokio::test]
    async fn a_first_frame_still_in_flight_gets_its_window() {
        let (server, mut client) = connected_pair().await;
        let drain = CancellationToken::new();

        drain.cancel();

        tokio::spawn(async move {
            tokio::time::sleep(Duration::from_millis(50)).await;

            write_frame(&mut client, b"late").await.unwrap();

            // Held open past the write: dropping the client here would close the
            // connection and could race the frame with an EOF.
            tokio::time::sleep(Duration::from_millis(500)).await;
        });

        let state = state(server, drain, FIRST_FRAME_DRAIN_GRACE);
        let result = state.next().await;

        assert!(result.has_next, "the first frame was dropped inside its own window");
        assert_eq!(result.payload, b"late");
    }

    /// The window is for the FIRST frame only: a connection that has already
    /// been served keeps the immediate EOF, or a drained server would wait a
    /// quarter second per connection for nothing.
    #[tokio::test]
    async fn a_connection_that_delivered_ends_at_once() {
        let (server, mut client) = connected_pair().await;
        let drain = CancellationToken::new();

        write_frame(&mut client, b"first").await.unwrap();

        let state = state(server, drain.clone(), FIRST_FRAME_DRAIN_GRACE);

        assert!(state.next().await.has_next);

        drain.cancel();

        let start = Instant::now();
        let result = state.next().await;

        assert!(!result.has_next, "the stream should have ended");
        assert!(
            start.elapsed() < FIRST_FRAME_DRAIN_GRACE,
            "waited {:?}, so the window was applied after a delivery",
            start.elapsed(),
        );
    }

    /// The client's shape: zero grace, because there the token means "the
    /// connection is over" and waiting past it would only delay the news.
    #[tokio::test]
    async fn zero_grace_ends_at_once() {
        let (server, _client) = connected_pair().await;
        let drain = CancellationToken::new();

        drain.cancel();

        let state = state(server, drain, Duration::ZERO);

        let start = Instant::now();
        let result = state.next().await;

        assert!(!result.has_next, "the stream should have ended");
        assert!(
            start.elapsed() < FIRST_FRAME_DRAIN_GRACE,
            "waited {:?}, so a client paid the server's window",
            start.elapsed(),
        );
    }
}
