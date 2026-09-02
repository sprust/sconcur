//! Mirrors ext-go-legacy/internal/tasks/task.go.

use crossbeam_channel::{Receiver, Sender};
use std::sync::Arc;
use tokio::sync::Semaphore;
use tokio_util::sync::CancellationToken;

use crate::dto::{Message, Result};

/// The shared results channel and the permits that bound it, which travel
/// together because neither is correct alone: a sender without the accounting
/// would overrun the buffer, and a permit released without a result taken out
/// of the channel would let it.
///
/// One permit is one free slot. A publisher takes one before it sends and does
/// not give it back — the side that pulls the result out does, which is what
/// makes the wait exact: a blocked publisher is woken by the consumer that made
/// room for it, once, instead of asking again on a timer.
#[derive(Clone)]
pub struct ResultSink {
    tx: Sender<Result>,
    slots: Arc<Semaphore>,
}

impl ResultSink {
    pub fn new(capacity: usize) -> (Self, Receiver<Result>) {
        let (tx, rx) = crossbeam_channel::bounded(capacity);

        (
            ResultSink {
                tx,
                slots: Arc::new(Semaphore::new(capacity)),
            },
            rx,
        )
    }

    /// Hands back the slots of results the consumer has taken out of the
    /// channel. Called for every result that leaves it, including one dropped
    /// as stale — the slot is free either way.
    pub fn release(&self, taken: usize) {
        self.slots.add_permits(taken);
    }
}

/// Task carries one message through its feature handler. Like the Go original
/// it holds the owning flow's cancellation token directly instead of deriving a
/// per-task child: a task is never cancelled individually before its result is
/// delivered (stopFlow cancels the whole flow).
#[derive(Clone)]
pub struct Task {
    message: Arc<Message>,
    flow_ctx: CancellationToken,
    results: ResultSink,
}

impl Task {
    pub fn new(flow_ctx: CancellationToken, results: ResultSink, message: Arc<Message>) -> Self {
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
    /// The second version yielded eight times and then woke itself every 100 µs
    /// until a slot appeared. That fixed the tail but kept the shape wrong: a
    /// goroutine parked on a channel send is woken once, by the receiver, and
    /// this asked again on a timer. A thousand blocked tasks meant a thousand
    /// timers firing at 10 kHz, which is a cliff Go did not have in the very
    /// place a wide fan-out lands.
    ///
    /// A permit is a slot, so acquiring one *is* the park, and the consumer's
    /// release is the wake.
    pub async fn add_result(&self, result: Result) {
        let permit = tokio::select! {
            biased;

            permit = self.results.slots.acquire() => permit,
            // The flow went away while this task waited for room. Nobody is
            // coming for the result.
            _ = self.flow_ctx.cancelled() => return,
        };

        let Ok(permit) = permit else {
            return;
        };

        // The consumer releases it, not this scope: the slot stays taken for as
        // long as the result sits in the channel.
        permit.forget();

        if self.results.tx.try_send(result).is_err() {
            // Unreachable while the accounting holds — a permit means a free
            // slot — but a leaked permit would shrink the buffer for the life of
            // the process, so it goes back rather than being trusted away.
            self.results.release(1);
        }
    }

    /// Publishes a result from a detached fire-and-forget task, which runs
    /// synchronously on the PHP thread inside push() — the very thread that
    /// drains the channel. Waiting for a slot here would deadlock the worker,
    /// and nothing awaits these results anyway (owner id 0, PHP drops them on
    /// delivery), so they go best-effort and are dropped when the buffer is
    /// full. Mirrors the empty-flow-key branch of tasks.Task.AddResult.
    pub fn add_result_detached(&self, result: Result) {
        let Ok(permit) = self.results.slots.try_acquire() else {
            return;
        };

        permit.forget();

        if self.results.tx.try_send(result).is_err() {
            self.results.release(1);
        }
    }
}
