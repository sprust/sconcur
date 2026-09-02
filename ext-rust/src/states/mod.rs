//! Mirrors ext/internal/states/states.go: the registry of streaming states
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
/// Both halves are async where Go's are plain methods: a goroutine may block on
/// the driver, a task here may not. `close` in particular has to be awaited
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
