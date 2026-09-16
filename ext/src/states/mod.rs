//! The registry of streaming states
//! (cursor batches, request-body chunks, client message streams) driven by
//! `next()`.

use std::collections::HashMap;
use std::future::Future;
use std::pin::Pin;
use std::sync::{Arc, Mutex, OnceLock};
use tokio_util::sync::CancellationToken;

use crate::dto::Result;
use crate::tasks::Task;

pub type StateFuture<'a> = Pin<Box<dyn Future<Output = Result> + Send + 'a>>;
pub type StateCloseFuture<'a> = Pin<Box<dyn Future<Output = ()> + Send + 'a>>;

/// A stream PHP pulls one batch at a time.
///
/// Both halves are async, because a task here may not block on the driver.
/// `close` in particular has to be awaited
/// rather than fired and forgotten — abandoning a MongoDB cursor must reach the
/// server before PHP looks at the open-cursor count, which is exactly what
/// MongodbAbandonedCursorTest asserts.
pub trait StateContract: Send + Sync {
    fn next(&self) -> StateFuture<'_>;
    fn close(&self) -> StateCloseFuture<'_>;
}

pub struct States {
    states: Mutex<HashMap<String, Entry>>,
}

/// A registered state and, when its cleanup is hooked to a flow, the token that
/// retires the hook. The hook has to be told: a flow outlives the streams opened
/// on it, and one that is never cancelled — the flow of a coroutine that loops
/// for the life of the process — would otherwise keep one parked task per
/// stream it ever read, long after each was closed.
struct Entry {
    state: Arc<dyn StateContract>,
    released: Option<CancellationToken>,
}

static INSTANCE: OnceLock<States> = OnceLock::new();

pub fn get() -> &'static States {
    INSTANCE.get_or_init(|| States {
        states: Mutex::new(HashMap::new()),
    })
}

impl States {
    /// Registers a state, hooks its cleanup to the flow, and reads its first
    /// batch.
    ///
    /// The hook is not optional. When PHP abandons a stream — breaks out of a
    /// cursor early — nothing calls `next()` again, so the only thing that ever
    /// removes the state is the flow ending. Without it the registry keeps the
    /// state forever and, for MongoDB, the server-side cursor with it, which is
    /// what MongodbAbandonedCursorTest measures.
    pub async fn start(
        &self,
        flow_ctx: CancellationToken,
        task_key: &str,
        state: Arc<dyn StateContract>,
    ) -> std::result::Result<Result, String> {
        self.register_with_flow(flow_ctx, task_key.to_string(), state.clone(), || {})?;

        Ok(self.handle_next(task_key, state).await)
    }

    /// Stores a state without reading its first batch and without hooking
    /// cleanup — the caller owns its lifetime and must call delete_state.
    pub fn register(
        &self,
        task_key: String,
        state: Arc<dyn StateContract>,
    ) -> std::result::Result<(), String> {
        self.insert(task_key, state, None)
    }

    /// Stores a state without reading its first batch, and deletes it when the
    /// flow ends unless something deleted it first — for a state the caller
    /// cannot start because its first batch is not ready yet.
    ///
    /// `on_flow_end` runs right before that deletion, and only then: whatever
    /// the caller keeps beside the state is its own to release on the ordinary
    /// path. The hook ends with the state either way, so a flow that is never
    /// cancelled keeps nothing of a state that is gone.
    pub fn register_with_flow<F>(
        &self,
        flow_ctx: CancellationToken,
        task_key: String,
        state: Arc<dyn StateContract>,
        on_flow_end: F,
    ) -> std::result::Result<(), String>
    where
        F: FnOnce() + Send + 'static,
    {
        let released = CancellationToken::new();

        self.insert(task_key.clone(), state, Some(released.clone()))?;

        tokio::spawn(async move {
            // Biased towards the release: when the last batch and the flow's end
            // land together the state is already gone, and the flow branch would
            // only run on_flow_end for nothing. Taking either branch is still
            // safe — delete_state closes a state exactly once.
            tokio::select! {
                biased;
                _ = released.cancelled() => {}
                _ = flow_ctx.cancelled() => {
                    on_flow_end();

                    get().delete_state(&task_key).await;
                }
            }
        });

        Ok(())
    }

    fn insert(
        &self,
        task_key: String,
        state: Arc<dyn StateContract>,
        released: Option<CancellationToken>,
    ) -> std::result::Result<(), String> {
        let mut states = self.states.lock().unwrap();

        if states.contains_key(&task_key) {
            return Err("state already exists".to_string());
        }

        states.insert(task_key, Entry { state, released });

        Ok(())
    }

    pub async fn next(&self, task: &Task) {
        let message = task.message();

        // The state is cloned out and the lock released before awaiting: a batch
        // can take as long as the database does, and holding the registry for
        // that would serialize every other stream in the process.
        let state = self
            .states
            .lock()
            .unwrap()
            .get(&message.task_key)
            .map(|entry| entry.state.clone());

        let Some(state) = state else {
            task.add_result(Result::error(message, "state not started".to_string())).await;

            return;
        };

        let mut result = self.handle_next(&message.task_key, state).await;

        // The state keeps the original message, but each next() may arrive on a
        // different flow. Route the result back to whoever issued THIS next,
        // not the flow that opened the stream — otherwise the per-flow
        // demultiplexer never delivers it. The owner id must follow the same
        // rule, or the PHP side drops the result as belonging to someone else.
        result.flow_key = message.flow_key.clone();
        result.owner_id = message.owner_id;

        task.add_result(result).await;
    }

    async fn handle_next(&self, task_key: &str, state: Arc<dyn StateContract>) -> Result {
        let mut result = state.next().await;

        if !result.has_next {
            self.delete_state(task_key).await;
        }

        result.task_key = task_key.to_string();

        result
    }

    pub async fn delete_state(&self, task_key: &str) {
        let Some(entry) = self.states.lock().unwrap().remove(task_key) else {
            return;
        };

        // Only the call that took the entry gets here, so the hook is retired
        // and the state closed once, whichever path deleted it.
        if let Some(released) = entry.released {
            released.cancel();
        }

        // Closed outside the registry lock: it may talk to the server.
        entry.state.close().await;
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    use crate::dto::Message;
    use crate::tasks::ResultSink;
    use crate::types::method::Method;
    use std::sync::atomic::{AtomicUsize, Ordering};
    use std::time::Duration;

    /// The registry is a process-wide singleton, and cargo runs these in
    /// parallel, so every test works under a key of its own.
    fn key(name: &str) -> String {
        format!("states-test:{name}")
    }

    fn message(flow_key: &str, task_key: &str, owner_id: i64) -> Arc<Message> {
        Arc::new(Message {
            flow_key: flow_key.to_string(),
            method: Method::Mongodb,
            task_key: task_key.to_string(),
            payload: Vec::new(),
            is_next: true,
            owner_id,
        })
    }

    /// A stream that hands out a fixed number of batches and counts how many
    /// times it was closed — the two things the registry is responsible for.
    struct FakeStream {
        remaining: Mutex<usize>,
        closes: Arc<AtomicUsize>,
        nexts: Arc<AtomicUsize>,
    }

    impl FakeStream {
        fn new(batches: usize) -> (Arc<Self>, Arc<AtomicUsize>, Arc<AtomicUsize>) {
            let closes = Arc::new(AtomicUsize::new(0));
            let nexts = Arc::new(AtomicUsize::new(0));

            let stream = Arc::new(FakeStream {
                remaining: Mutex::new(batches),
                closes: closes.clone(),
                nexts: nexts.clone(),
            });

            (stream, closes, nexts)
        }
    }

    impl StateContract for FakeStream {
        fn next(&self) -> StateFuture<'_> {
            Box::pin(async move {
                self.nexts.fetch_add(1, Ordering::SeqCst);

                let mut remaining = self.remaining.lock().unwrap();

                *remaining = remaining.saturating_sub(1);

                Result {
                    flow_key: "opening-flow".to_string(),
                    method: Method::Mongodb,
                    task_key: String::new(),
                    is_error: false,
                    payload: Vec::new(),
                    has_next: *remaining > 0,
                    execution_ms: 0,
                    owner_id: 999,
                }
            })
        }

        fn close(&self) -> StateCloseFuture<'_> {
            Box::pin(async move {
                self.closes.fetch_add(1, Ordering::SeqCst);
            })
        }
    }

    /// The ordinary ending: the batch that says there is no next takes the state
    /// out of the registry and closes it, without anyone asking.
    #[tokio::test]
    async fn the_last_batch_closes_the_stream_and_forgets_it() {
        let task_key = key("last-batch");
        let (stream, closes, _) = FakeStream::new(1);

        let result = get()
            .start(CancellationToken::new(), &task_key, stream)
            .await
            .expect("the stream should have registered");

        assert!(!result.has_next);
        assert_eq!(closes.load(Ordering::SeqCst), 1);

        // Gone from the registry: a next() on it is now an error, not a batch.
        let (sink, rx) = ResultSink::new(1);
        let task = Task::new(
            CancellationToken::new(),
            sink,
            message("flow", &task_key, 1),
        );

        get().next(&task).await;

        let refused = rx.recv().unwrap();

        assert!(refused.is_error);
        assert_eq!(String::from_utf8_lossy(&refused.payload), "state not started");
    }

    /// The reason `start` hooks the flow at all: PHP breaking out of a cursor
    /// early means nothing calls next() again, so the flow ending is the only
    /// thing left that can close the server-side cursor. This is what
    /// MongodbAbandonedCursorTest measures from the outside, one cursor at a
    /// time.
    #[tokio::test]
    async fn an_abandoned_stream_is_closed_when_its_flow_ends() {
        let task_key = key("abandoned");
        let flow_ctx = CancellationToken::new();
        let (stream, closes, _) = FakeStream::new(100);

        get()
            .start(flow_ctx.clone(), &task_key, stream)
            .await
            .expect("the stream should have registered");

        assert_eq!(closes.load(Ordering::SeqCst), 0, "still open, still has batches");

        flow_ctx.cancel();

        // The hook is a spawned task, so it lands on the next scheduler turns.
        for _ in 0..100 {
            if closes.load(Ordering::SeqCst) == 1 {
                break;
            }

            tokio::time::sleep(Duration::from_millis(5)).await;
        }

        assert_eq!(
            closes.load(Ordering::SeqCst),
            1,
            "cancelling the flow should have closed the abandoned stream",
        );
    }

    /// Waits for the spawned tasks of this test's runtime to finish and returns
    /// how many are still alive. `#[tokio::test]` builds a runtime per test, so
    /// the count sees this test's tasks and nobody else's.
    async fn alive_tasks_after_settling() -> usize {
        let metrics = tokio::runtime::Handle::current().metrics();

        for _ in 0..100 {
            if metrics.num_alive_tasks() == 0 {
                break;
            }

            tokio::time::sleep(Duration::from_millis(5)).await;
        }

        metrics.num_alive_tasks()
    }

    /// The leak a long-lived coroutine used to have: its flow is never
    /// cancelled, so a flow hook that waited for nothing else outlived every
    /// stream the coroutine read to the end — one parked task per cursor, for
    /// the life of the process.
    #[tokio::test]
    async fn a_stream_read_to_the_end_leaves_no_flow_hook_behind() {
        let flow_ctx = CancellationToken::new();

        for index in 0..50 {
            let task_key = key(&format!("finished-{index}"));
            let (stream, _, _) = FakeStream::new(1);

            get()
                .start(flow_ctx.clone(), &task_key, stream)
                .await
                .expect("the stream should have registered");
        }

        assert_eq!(
            alive_tasks_after_settling().await,
            0,
            "a finished stream should not keep its flow hook waiting",
        );

        assert!(!flow_ctx.is_cancelled());
    }

    /// The same leak through the other door: a state registered with a flow
    /// hook and deleted by its owner — a committed transaction, a closed
    /// subscription — must take the hook with it, and must not run the flow's
    /// cleanup for a flow that has not ended.
    #[tokio::test]
    async fn a_deleted_state_retires_its_flow_hook_without_running_it() {
        let flow_ctx = CancellationToken::new();
        let flow_ends = Arc::new(AtomicUsize::new(0));

        for index in 0..50 {
            let task_key = key(&format!("owner-deleted-{index}"));
            let (stream, _, _) = FakeStream::new(5);
            let counted = flow_ends.clone();

            get()
                .register_with_flow(flow_ctx.clone(), task_key.clone(), stream, move || {
                    counted.fetch_add(1, Ordering::SeqCst);
                })
                .expect("the state should have registered");

            get().delete_state(&task_key).await;
        }

        assert_eq!(alive_tasks_after_settling().await, 0);

        flow_ctx.cancel();

        tokio::task::yield_now().await;

        assert_eq!(flow_ends.load(Ordering::SeqCst), 0);
    }

    /// What the hook is for, on the registered path: nobody deletes the state,
    /// so the flow ending does — after the caller's own cleanup, and once.
    #[tokio::test]
    async fn an_abandoned_registered_state_is_released_when_its_flow_ends() {
        let task_key = key("registered-abandoned");
        let flow_ctx = CancellationToken::new();
        let flow_ends = Arc::new(AtomicUsize::new(0));
        let (stream, closes, _) = FakeStream::new(5);
        let counted = flow_ends.clone();

        get()
            .register_with_flow(flow_ctx.clone(), task_key.clone(), stream, move || {
                counted.fetch_add(1, Ordering::SeqCst);
            })
            .expect("the state should have registered");

        flow_ctx.cancel();

        assert_eq!(alive_tasks_after_settling().await, 0);
        assert_eq!(flow_ends.load(Ordering::SeqCst), 1);
        assert_eq!(closes.load(Ordering::SeqCst), 1);
    }

    /// The last batch and the flow's end racing each other, on a runtime with
    /// real threads so the two deletions can truly overlap: whichever wins,
    /// the stream is closed exactly once and the hook does not survive it.
    #[tokio::test(flavor = "multi_thread", worker_threads = 4)]
    async fn a_flow_ending_with_the_last_batch_closes_the_stream_once() {
        for index in 0..200 {
            let task_key = key(&format!("race-{index}"));
            let flow_ctx = CancellationToken::new();
            let (stream, closes, _) = FakeStream::new(5);

            get()
                .register_with_flow(flow_ctx.clone(), task_key.clone(), stream, || {})
                .expect("the state should have registered");

            let deleting = tokio::spawn({
                let task_key = task_key.clone();

                async move { get().delete_state(&task_key).await }
            });

            flow_ctx.cancel();

            deleting.await.expect("the deletion should not panic");

            assert_eq!(alive_tasks_after_settling().await, 0);
            assert_eq!(closes.load(Ordering::SeqCst), 1, "closed once, iteration {index}");
        }
    }

    /// A result belongs to whoever issued THIS next, not to the flow that opened
    /// the stream. Getting this wrong strands the result: the per-flow
    /// demultiplexer on the PHP side never delivers it, and the coroutine waits
    /// forever.
    #[tokio::test]
    async fn a_batch_is_routed_to_the_flow_that_asked_for_it() {
        let task_key = key("routing");
        let (stream, _, _) = FakeStream::new(3);

        get()
            .start(CancellationToken::new(), &task_key, stream)
            .await
            .expect("the stream should have registered");

        let (sink, rx) = ResultSink::new(1);
        let task = Task::new(
            CancellationToken::new(),
            sink,
            message("asking-flow", &task_key, 42),
        );

        get().next(&task).await;

        let batch = rx.recv().unwrap();

        // The stream itself answers with the opening flow and owner 999; both
        // are overwritten with the ones that asked.
        assert_eq!(batch.flow_key, "asking-flow");
        assert_eq!(batch.owner_id, 42);
        assert_eq!(batch.task_key, task_key);

        get().delete_state(&task_key).await;
    }

    #[tokio::test]
    async fn the_same_key_cannot_be_registered_twice() {
        let task_key = key("duplicate");
        let (first, _, _) = FakeStream::new(5);
        let (second, _, _) = FakeStream::new(5);

        get()
            .register(task_key.clone(), first)
            .expect("the first registration should succeed");

        let refused = get().register(task_key.clone(), second);

        assert_eq!(refused, Err("state already exists".to_string()));

        get().delete_state(&task_key).await;
    }

    /// Deleting is idempotent, which matters because two paths race to do it:
    /// the last batch and the flow hook. Closing a cursor twice is an error the
    /// server reports, not a no-op.
    #[tokio::test]
    async fn deleting_a_stream_twice_closes_it_once() {
        let task_key = key("double-delete");
        let (stream, closes, _) = FakeStream::new(5);

        get()
            .register(task_key.clone(), stream)
            .expect("the registration should succeed");

        get().delete_state(&task_key).await;
        get().delete_state(&task_key).await;

        assert_eq!(closes.load(Ordering::SeqCst), 1);
    }
}
