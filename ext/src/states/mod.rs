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
    states: Mutex<HashMap<String, Arc<dyn StateContract>>>,
}

static INSTANCE: OnceLock<States> = OnceLock::new();

pub fn get() -> &'static States {
    INSTANCE.get_or_init(|| States {
        states: Mutex::new(HashMap::new()),
    })
}

impl States {
    /// Registers a state, hooks its cleanup to the flow, and reads its first
    /// batch. Mirrors States.Start, including the `context.AfterFunc` half.
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
        self.register(task_key.to_string(), state.clone())?;

        let key = task_key.to_string();

        tokio::spawn(async move {
            flow_ctx.cancelled().await;

            get().delete_state(&key).await;
        });

        Ok(self.handle_next(task_key, state).await)
    }

    /// Stores a state without reading its first batch and without hooking
    /// cleanup — the caller owns its lifetime and must call delete_state.
    /// Mirrors States.Register.
    pub fn register(
        &self,
        task_key: String,
        state: Arc<dyn StateContract>,
    ) -> std::result::Result<(), String> {
        let mut states = self.states.lock().unwrap();

        if states.contains_key(&task_key) {
            return Err("state already exists".to_string());
        }

        states.insert(task_key, state);

        Ok(())
    }

    pub async fn next(&self, task: &Task) {
        let message = task.message();

        // The state is cloned out and the lock released before awaiting: a batch
        // can take as long as the database does, and holding the registry for
        // that would serialize every other stream in the process.
        let state = self.states.lock().unwrap().get(&message.task_key).cloned();

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
        let state = self.states.lock().unwrap().remove(task_key);

        // Closed outside the registry lock: it may talk to the server.
        if let Some(state) = state {
            state.close().await;
        }
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
