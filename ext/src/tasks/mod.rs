//! One task in flight, and the shared results channel it publishes into.

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

/// Task carries one message through its feature handler. It holds the owning
/// flow's cancellation token directly rather than deriving a per-task child: a task is never cancelled individually before its result is
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
    /// that it is the literal equivalent of parking on a full channel. It is
    /// not, and the measurement said so: on a pinned worker the
    /// runtime has one thread, so every full-buffer send made tokio hand its
    /// work to a freshly spawned replacement. Median latency stayed fine and
    /// the tail fell apart — /db held p50 at 1.2 ms while p99 sat at ~400 ms,
    /// reproducibly, across every run.
    ///
    /// The second version yielded eight times and then woke itself every 100 µs
    /// until a slot appeared. That fixed the tail but kept the shape wrong: a
    /// publisher blocked on a full channel should be woken once, by the
    /// receiver, and this asked again on a timer. A thousand blocked tasks meant
    /// a thousand timers firing at 10 kHz, which is a cliff in the very place a
    /// wide fan-out lands.
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

#[cfg(test)]
mod tests {
    use super::*;

    use crate::types::method::Method;
    use std::time::Duration;

    /// Long enough that a publisher which was going to make progress has, short
    /// enough that a test which parks forever fails quickly rather than hanging
    /// the suite.
    const SETTLE: Duration = Duration::from_millis(50);

    fn message() -> Arc<Message> {
        Arc::new(Message {
            flow_key: "flow".to_string(),
            method: Method::Sleep,
            task_key: "flow:1".to_string(),
            payload: Vec::new(),
            is_next: false,
            owner_id: 1,
        })
    }

    fn task(sink: ResultSink, flow_ctx: CancellationToken) -> Task {
        Task::new(flow_ctx, sink, message())
    }

    fn result() -> Result {
        Result::success(&message(), Vec::new(), 0)
    }

    /// The invariant the permits exist for. Both ways of getting it wrong are
    /// caught here: a publisher that drops the result on a full buffer finishes
    /// immediately, and one that oversends it finishes immediately too — the
    /// test wants it to still be parked.
    #[tokio::test]
    async fn a_publisher_parks_on_a_full_buffer_until_a_slot_is_released() {
        let (sink, rx) = ResultSink::new(2);
        let task = task(sink.clone(), CancellationToken::new());

        task.add_result(result()).await;
        task.add_result(result()).await;

        assert_eq!(rx.len(), 2, "the buffer should be full");

        let parked = tokio::spawn({
            let task = task.clone();

            async move { task.add_result(result()).await }
        });

        tokio::time::sleep(SETTLE).await;

        assert!(!parked.is_finished(), "the third publisher should be parked");
        assert_eq!(rx.len(), 2, "and nothing should have been oversent");

        // Taking one out is what wakes it — the consumer's release, not a timer.
        let _ = rx.recv().unwrap();
        sink.release(1);

        tokio::time::timeout(SETTLE, parked)
            .await
            .expect("releasing a slot should have woken the publisher")
            .expect("the publisher should not have panicked");

        assert_eq!(rx.len(), 2);
    }

    /// A flow that goes away while a publisher waits for room releases it.
    /// Without this the task would hold its permit-less park for the life of the
    /// process, and stopFlow would not actually stop anything.
    #[tokio::test]
    async fn a_cancelled_flow_releases_a_parked_publisher() {
        let (sink, rx) = ResultSink::new(1);
        let flow_ctx = CancellationToken::new();
        let task = task(sink, flow_ctx.clone());

        task.add_result(result()).await;

        let parked = tokio::spawn({
            let task = task.clone();

            async move { task.add_result(result()).await }
        });

        tokio::time::sleep(SETTLE).await;

        assert!(!parked.is_finished());

        flow_ctx.cancel();

        tokio::time::timeout(SETTLE, parked)
            .await
            .expect("cancelling the flow should have released the publisher")
            .expect("the publisher should not have panicked");

        // The result was never published: nobody was coming for it.
        assert_eq!(rx.len(), 1);
    }

    /// A detached result runs on the PHP thread inside push() — the same thread
    /// that drains the channel — so waiting for room there is a deadlock, not a
    /// delay. It drops the result instead, and the slot accounting survives the
    /// drop.
    #[tokio::test]
    async fn a_detached_result_is_dropped_rather_than_waiting() {
        let (sink, rx) = ResultSink::new(1);
        let task = task(sink.clone(), CancellationToken::new());

        task.add_result(result()).await;

        // Synchronous on purpose: if this ever waits, it hangs here.
        task.add_result_detached(result());

        assert_eq!(rx.len(), 1, "the detached result should have been dropped");

        // And the slot it could not take was not consumed: one release makes
        // room for exactly one more.
        let _ = rx.recv().unwrap();
        sink.release(1);

        task.add_result_detached(result());

        assert_eq!(rx.len(), 1);
    }

    /// The permits and the channel have to stay in step over many rounds: a slot
    /// leaked per result would shrink the buffer until it wedged, which is a
    /// failure that only shows up after hours of traffic.
    #[tokio::test]
    async fn slots_and_results_stay_in_step_over_many_rounds() {
        let (sink, rx) = ResultSink::new(4);
        let task = task(sink.clone(), CancellationToken::new());

        for _ in 0..50 {
            for _ in 0..4 {
                task.add_result(result()).await;
            }

            assert_eq!(rx.len(), 4);

            for _ in 0..4 {
                let _ = rx.recv().unwrap();
            }

            sink.release(4);
        }

        // The buffer still holds its whole capacity, which it would not if a
        // permit had gone missing on any of the 200 round trips.
        for _ in 0..4 {
            tokio::time::timeout(SETTLE, task.add_result(result()))
                .await
                .expect("the buffer should still take its full capacity");
        }

        assert_eq!(rx.len(), 4);
    }
}
