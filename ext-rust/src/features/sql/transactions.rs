//! Mirrors ext/internal/features/sql/transactions.go.
//!
//! A transaction outlives the task that opened it: PHP begins on one task and
//! then sends queries, execs and the final commit on tasks of their own. The
//! begin task stays alive as a registered stream (hasNext) so its pooled
//! connection survives, exactly as on the Go side.

use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use std::time::Instant;

use crate::dto::{Message, Result};
use crate::helpers::calc_execution_ms;
use crate::states::{StateCloseFuture, StateContract, StateFuture};

use super::pools::Acquired;

/// The live transaction. sqlx's commit/rollback consume it, so it sits in an
/// Option that the first finaliser takes — which is also what makes finalising
/// idempotent, the job `sync.Once` does in the Go original.
pub enum TxHandle {
    Mysql(sqlx::Transaction<'static, sqlx::MySql>),
    Pgsql(sqlx::Transaction<'static, sqlx::Postgres>),
}

pub struct TransactionSession {
    pub id: String,
    /// An async mutex, not a std one: a query on this transaction is held across
    /// an await, and the driver serialises statements on one connection anyway.
    pub handle: tokio::sync::Mutex<Option<TxHandle>>,
    /// Released when the session is dropped — the counterpart of Go's
    /// `pools.release` inside `cleanup`.
    _acquired: Acquired,
}

impl TransactionSession {
    pub fn new(id: String, handle: TxHandle, acquired: Acquired) -> Self {
        TransactionSession {
            id,
            handle: tokio::sync::Mutex::new(Some(handle)),
            _acquired: acquired,
        }
    }

    pub async fn commit(&self) -> std::result::Result<(), String> {
        self.finalize(true).await
    }

    pub async fn rollback(&self) -> std::result::Result<(), String> {
        self.finalize(false).await
    }

    /// Runs at most once: whichever of commit, rollback or the holder's cleanup
    /// arrives first takes the handle, and the rest find nothing to do.
    async fn finalize(&self, is_commit: bool) -> std::result::Result<(), String> {
        let Some(handle) = self.handle.lock().await.take() else {
            return Ok(());
        };

        let outcome = match (handle, is_commit) {
            (TxHandle::Mysql(transaction), true) => transaction.commit().await,
            (TxHandle::Mysql(transaction), false) => transaction.rollback().await,
            (TxHandle::Pgsql(transaction), true) => transaction.commit().await,
            (TxHandle::Pgsql(transaction), false) => transaction.rollback().await,
        };

        outcome.map_err(|error| error.to_string())
    }
}

/// Maps a transaction id (the begin task key) to its live session, so a
/// query/exec/commit/rollback arriving on its own task finds the pinned
/// transaction. On the Core, like every other registry here, so a fork does not
/// hand a child transactions belonging to another process.
pub struct Transactions {
    sessions: Mutex<HashMap<String, Arc<TransactionSession>>>,
}

impl Transactions {
    pub fn new() -> Self {
        Transactions {
            sessions: Mutex::new(HashMap::new()),
        }
    }

    /// Stores the session unless the id is taken. Mirrors LoadOrStore: a false
    /// return means a duplicate, which the caller reports rather than papering
    /// over.
    pub fn store(&self, session: Arc<TransactionSession>) -> bool {
        let mut sessions = self.sessions.lock().unwrap();

        if sessions.contains_key(&session.id) {
            return false;
        }

        sessions.insert(session.id.clone(), session);

        true
    }

    pub fn load(&self, id: &str) -> Option<Arc<TransactionSession>> {
        self.sessions.lock().unwrap().get(id).cloned()
    }

    pub fn remove(&self, id: &str) -> Option<Arc<TransactionSession>> {
        self.sessions.lock().unwrap().remove(id)
    }
}

/// Keeps the begin task alive (registered with hasNext) so the pinned connection
/// survives across the transaction's commands. Its `next` is the release marker
/// PHP pulls after commit/rollback; `close` rolls back as a safety net, which is
/// a no-op once the transaction was already finalised.
pub struct TransactionHolderState {
    pub session: Arc<TransactionSession>,
    pub message: Arc<Message>,
    pub start_time: Instant,
}

impl StateContract for TransactionHolderState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            Result::success(&self.message, Vec::new(), calc_execution_ms(self.start_time))
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            // The safety-net rollback, awaited now that close() can be: a
            // transaction left open until the connection drops would hold its
            // locks for as long as the pool keeps that connection alive.
            let _ = self.session.rollback().await;
        })
    }
}
