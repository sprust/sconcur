//! Mirrors ext/internal/tasks/task.go.

use crossbeam_channel::{Sender, TrySendError};
use std::sync::Arc;
use std::time::Duration;

/// How many scheduler hops to give the PHP side to drain the buffer before
/// falling back to a timer. Yields are cheap; a timer costs a wakeup.
const SEND_YIELD_ATTEMPTS: usize = 8;

/// The retry interval once yielding has not helped. Long enough not to spin,
/// short enough that a drained slot is taken promptly.
const SEND_RETRY_INTERVAL: Duration = Duration::from_micros(100);
use tokio_util::sync::CancellationToken;

use crate::dto::{Message, Result};

/// Task carries one message through its feature handler. Like the Go original
/// it holds the owning flow's cancellation token directly instead of deriving a
/// per-task child: a task is never cancelled individually before its result is
/// delivered (stopFlow cancels the whole flow).
#[derive(Clone)]
pub struct Task {
    message: Arc<Message>,
    flow_ctx: CancellationToken,
    results: Sender<Result>,
}

impl Task {
    pub fn new(flow_ctx: CancellationToken, results: Sender<Result>, message: Arc<Message>) -> Self {
        Task {
            message,
            flow_ctx,
            results,
        }
    }

    pub fn context(&self) -> &CancellationToken {
        &self.flow_ctx
    }

    pub fn message(&self) -> &Message {
        &self.message
    }

    /// The message as a shared handle, for the states that outlive the call
    /// that created them (a cursor, a transaction holder).
    pub fn message_arc(&self) -> Arc<Message> {
        self.message.clone()
    }

    /// Publishes a result into the shared channel, waiting for room past the
    /// buffer. This is the flow-task path, and it is async on purpose.
    ///
    /// The first version blocked the worker with `block_in_place`, reasoning
    /// that it is the literal equivalent of a goroutine parking on a channel
    /// send. It is not, and the measurement said so: on a pinned worker the
    /// runtime has one thread, so every full-buffer send made tokio hand its
    /// work to a freshly spawned replacement. Median latency stayed fine and
    /// the tail fell apart — /db held p50 at 1.2 ms while p99 sat at ~400 ms,
    /// reproducibly, across every run.
    ///
    /// Yielding instead keeps the worker running other tasks while this one
    /// waits, which is what parking a goroutine actually costs.
    pub async fn add_result(&self, result: Result) {
        let mut pending = match self.results.try_send(result) {
            Ok(()) => return,
            Err(TrySendError::Full(back)) => back,
            Err(TrySendError::Disconnected(_)) => return,
        };

        // A full buffer means PHP has not drained it yet; it usually will
        // within a scheduling hop, so yield before ever reaching for a timer.
        for _ in 0..SEND_YIELD_ATTEMPTS {
            tokio::task::yield_now().await;

            pending = match self.results.try_send(pending) {
                Ok(()) => return,
                Err(TrySendError::Full(back)) => back,
                Err(TrySendError::Disconnected(_)) => return,
            };
        }

        loop {
            if self.flow_ctx.is_cancelled() {
                return;
            }

            tokio::time::sleep(SEND_RETRY_INTERVAL).await;

            pending = match self.results.try_send(pending) {
                Ok(()) => return,
                Err(TrySendError::Full(back)) => back,
                Err(TrySendError::Disconnected(_)) => return,
            };
        }
    }

    /// Publishes a result from a detached fire-and-forget task, which runs
    /// synchronously on the PHP thread inside push() — the very thread that
    /// drains the channel. Blocking here with a full buffer would deadlock the
    /// worker, and nothing awaits these results anyway (owner id 0, PHP drops
    /// them on delivery), so they go best-effort and are dropped when full.
    /// Mirrors the empty-flow-key branch of tasks.Task.AddResult.
    pub fn add_result_detached(&self, result: Result) {
        let _ = self.results.try_send(result);
    }
}
