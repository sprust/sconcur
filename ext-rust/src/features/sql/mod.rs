//! Mirrors ext/internal/features/sql: one feature handling every SQL command
//! for one driver, with the driver selected per Method.

pub mod drivers;
pub mod dsn;
pub mod payloads;
pub mod pg_bindings;
pub mod pools;
pub mod rows_state;
pub mod transactions;
pub mod values;

use std::sync::Arc;
use std::time::{Duration, Instant};
use tokio::sync::mpsc;
use tokio_util::sync::CancellationToken;

use crate::dto::Result;
use crate::errs::Factory;
use crate::features::{BoxFuture, Feature};
use crate::helpers::calc_execution_ms;
use crate::states;
use crate::states::StateContract;
use crate::tasks::Task;

use drivers::{begin_transaction, exec_on_pool, exec_on_transaction, run_query, run_transaction_query};
use pools::Acquired;
use rows_state::{RowMessage, RowsState};
use transactions::{TransactionHolderState, TransactionSession};
use values::normalize_bindings;

static MYSQL_ERRORS: Factory = Factory::new("mysql");
static PGSQL_ERRORS: Factory = Factory::new("pgsql");

/// The feature's process-wide registries, owned by the Core so a fork discards
/// them: pool handles and live transactions belong to the process that opened
/// them.
pub struct Registries {
    pools: pools::Pools,
    transactions: transactions::Transactions,
}

impl Registries {
    pub fn new() -> Self {
        Registries {
            pools: pools::Pools::new(),
            transactions: transactions::Transactions::new(),
        }
    }

    pub fn pools(&self) -> &pools::Pools {
        &self.pools
    }

    pub fn transactions(&self) -> &transactions::Transactions {
        &self.transactions
    }
}

fn transactions() -> &'static transactions::Transactions {
    crate::core::get().sql().transactions()
}

/// Handles SQL commands for one driver on top of sqlx. The feature itself is
/// driver-agnostic; `driver_name` selects the pool to open and `errors` labels
/// the messages (they differ on the Go side too — driver "pgx", label "pgsql").
pub struct SqlFeature {
    driver_name: &'static str,
    errors: &'static Factory,
}

static MYSQL: SqlFeature = SqlFeature {
    driver_name: "mysql",
    errors: &MYSQL_ERRORS,
};

static PGSQL: SqlFeature = SqlFeature {
    driver_name: "pgsql",
    errors: &PGSQL_ERRORS,
};

pub fn get_mysql() -> &'static SqlFeature {
    &MYSQL
}

pub fn get_pgsql() -> &'static SqlFeature {
    &PGSQL
}

/// Closes every open pool. Called on extension shutdown.
pub fn close_all_pools() {
    pools::get().close_all();
}

impl Feature for SqlFeature {
    fn handle(&self, task: Task) -> BoxFuture {
        let feature: &'static SqlFeature = if self.driver_name == "mysql" {
            get_mysql()
        } else {
            get_pgsql()
        };

        Box::pin(async move {
            let message = task.message();

            let envelope: payloads::Envelope = match rmp_serde::from_slice(&message.payload) {
                Ok(envelope) => envelope,
                Err(error) => {
                    task.add_result(Result::error(
                        message,
                        feature.errors.by_err("parse envelope", error),
                    )).await;

                    return;
                }
            };

            // The sweeper needs a runtime, so it is armed on first use rather
            // than when the registry is built.
            pools::start_sweeper();

            match envelope.command.as_str() {
                "qry" => feature.handle_query(&task, &envelope).await,
                "exe" => feature.handle_exec(&task, &envelope).await,
                "beg" => feature.handle_begin(&task, &envelope).await,
                "cmt" => feature.handle_finalize(&task, &envelope, true).await,
                "rlb" => feature.handle_finalize(&task, &envelope, false).await,
                _ => task.add_result(Result::error(
                    message,
                    feature.errors.by_text("unknown command"),
                )).await,
            }
        })
    }
}

impl SqlFeature {
    async fn acquire(&'static self, envelope: &payloads::Envelope) -> std::result::Result<Acquired, String> {
        pools::get()
            .acquire(
                self.driver_name,
                &envelope.dsn,
                envelope.max_open_conns,
                envelope.max_idle_conns,
                envelope.conn_max_lifetime_ms,
            )
            .await
            .map_err(|error| self.errors.by_text(&error))
    }

    /// Streams a SELECT. An autocommit query holds a pooled connection until the
    /// cursor closes; a transaction query runs on the pinned transaction and
    /// leaves its connection to the begin task.
    async fn handle_query(&'static self, task: &Task, envelope: &payloads::Envelope) {
        let message = task.message();

        let params: payloads::QueryParams = match rmpv::ext::from_value(envelope.data.clone()) {
            Ok(params) => params,
            Err(error) => {
                task.add_result(Result::error(
                    message,
                    self.errors.by_err("parse query params", error),
                )).await;

                return;
            }
        };

        let bindings = normalize_bindings(&params.bindings);
        let sql = params.sql.clone();

        // The cursor outlives this call (PHP pulls it via next), so the deadline
        // and the cancellation ride with the state, not with a defer here.
        let cancel = CancellationToken::new();
        let producer_cancel = cancel.clone();
        let flow_cancel = task.context().clone();
        let timeout_ms = envelope.timeout_ms;

        // Sized to the batch so the producer runs one batch ahead of PHP and
        // then blocks — the backpressure Go gets from holding an open cursor.
        let capacity = if params.batch_size > 0 {
            (params.batch_size as usize).min(1024)
        } else {
            256
        };

        let (sender, receiver) = mpsc::channel::<RowMessage>(capacity);

        if params.transaction_id.is_empty() {
            let acquired = match self.acquire(envelope).await {
                Ok(acquired) => acquired,
                Err(error) => {
                    task.add_result(Result::error(message, error)).await;

                    return;
                }
            };

            tokio::spawn(async move {
                let driver = acquired.driver().clone();

                run_query(driver, sql, bindings, sender, producer_cancel, flow_cancel, timeout_ms).await;

                // Held to here on purpose: the pooled connection is released
                // when the producer ends, not when the request handler returns.
                drop(acquired);
            });
        } else {
            let Some(session) = transactions().load(&params.transaction_id) else {
                task.add_result(Result::error(
                    message,
                    self.errors
                        .by_text(&format!("unknown transaction {}", params.transaction_id)),
                )).await;

                return;
            };

            tokio::spawn(async move {
                run_transaction_query(session, sql, bindings, sender, producer_cancel, flow_cancel).await;
            });
        }

        let state = Arc::new(RowsState::new(
            message_arc(task),
            params.batch_size,
            self.errors,
            receiver,
            cancel,
        ));

        match states::get()
            .start(task.context().clone(), &message.task_key, state.clone())
            .await
        {
            Ok(result) => task.add_result(result).await,
            Err(error) => {
                state.close().await;

                task.add_result(Result::error(message, self.errors.by_err("start query", error))).await;
            }
        }
    }

    /// Runs a non-row statement and answers with affected-rows and
    /// last-insert-id.
    async fn handle_exec(&'static self, task: &Task, envelope: &payloads::Envelope) {
        let message = task.message();
        let start_time = Instant::now();

        let params: payloads::ExecParams = match rmpv::ext::from_value(envelope.data.clone()) {
            Ok(params) => params,
            Err(error) => {
                task.add_result(Result::error(
                    message,
                    self.errors.by_err("parse exec params", error),
                )).await;

                return;
            }
        };

        let bindings = normalize_bindings(&params.bindings);

        let outcome = if params.transaction_id.is_empty() {
            let acquired = match self.acquire(envelope).await {
                Ok(acquired) => acquired,
                Err(error) => {
                    task.add_result(Result::error(message, error)).await;

                    return;
                }
            };

            with_timeout(
                envelope.timeout_ms,
                exec_on_pool(acquired.driver().clone(), params.sql, bindings),
            )
            .await
        } else {
            let Some(session) = transactions().load(&params.transaction_id) else {
                task.add_result(Result::error(
                    message,
                    self.errors
                        .by_text(&format!("unknown transaction {}", params.transaction_id)),
                )).await;

                return;
            };

            with_timeout(
                envelope.timeout_ms,
                exec_on_transaction(session, params.sql, bindings),
            )
            .await
        };

        match outcome {
            Ok((affected_rows, last_insert_id)) => task.add_result(Result::success(
                message,
                values::encode_exec_result(affected_rows, last_insert_id),
                calc_execution_ms(start_time),
            )).await,
            Err(error) => task.add_result(Result::error(
                message,
                self.errors.by_text(&format!("exec error: {error}")),
            )).await,
        }
    }

    /// Opens a transaction on a pooled connection and registers the holder
    /// state. The result carries hasNext so the begin task stays alive for the
    /// whole transaction.
    async fn handle_begin(&'static self, task: &Task, envelope: &payloads::Envelope) {
        let message = task.message();
        let start_time = Instant::now();

        let params: payloads::BeginParams = match rmpv::ext::from_value(envelope.data.clone()) {
            Ok(params) => params,
            Err(error) => {
                task.add_result(Result::error(
                    message,
                    self.errors.by_err("parse begin params", error),
                )).await;

                return;
            }
        };

        let acquired = match self.acquire(envelope).await {
            Ok(acquired) => acquired,
            Err(error) => {
                task.add_result(Result::error(message, error)).await;

                return;
            }
        };

        let handle = match begin_transaction(acquired.driver().clone(), &params).await {
            Ok(handle) => handle,
            Err(error) => {
                task.add_result(Result::error(message, self.errors.by_text(&format!("begin: {error}")))).await;

                return;
            }
        };

        let transaction_id = message.task_key.clone();

        let session = Arc::new(TransactionSession::new(transaction_id.clone(), handle, acquired));

        if !transactions().store(session.clone()) {
            let _ = session.rollback().await;

            task.add_result(Result::error(
                message,
                self.errors
                    .by_text(&format!("duplicate transaction {transaction_id}")),
            )).await;

            return;
        }

        let holder = Arc::new(TransactionHolderState {
            session: session.clone(),
            message: message_arc(task),
            start_time,
        });

        if let Err(error) = states::get().register(transaction_id.clone(), holder) {
            let _ = session.rollback().await;

            transactions().remove(&transaction_id);

            task.add_result(Result::error(
                message,
                self.errors.by_text(&format!("register transaction: {error}")),
            )).await;

            return;
        }

        // On flow stop: drop the holder, which rolls back (if not already
        // finalised) and releases the pool.
        let flow_cancel = task.context().clone();
        let stop_id = transaction_id.clone();

        tokio::spawn(async move {
            flow_cancel.cancelled().await;

            transactions().remove(&stop_id);
            states::get().delete_state(&stop_id).await;
        });

        task.add_result(Result::success_with_next(
            message,
            Vec::new(),
            calc_execution_ms(start_time),
        )).await;
    }

    /// Commits or rolls back the transaction named by the command; PHP then
    /// releases the holder via next(). Finalising is idempotent, so a stop that
    /// races the explicit call cannot double-release.
    async fn handle_finalize(&'static self, task: &Task, envelope: &payloads::Envelope, is_commit: bool) {
        let message = task.message();
        let start_time = Instant::now();

        let params: payloads::TransactionRefParams = match rmpv::ext::from_value(envelope.data.clone()) {
            Ok(params) => params,
            Err(error) => {
                task.add_result(Result::error(
                    message,
                    self.errors.by_err("parse transaction ref", error),
                )).await;

                return;
            }
        };

        let Some(session) = transactions().load(&params.transaction_id) else {
            task.add_result(Result::error(
                message,
                self.errors
                    .by_text(&format!("unknown transaction {}", params.transaction_id)),
            )).await;

            return;
        };

        let outcome = if is_commit {
            session.commit().await
        } else {
            session.rollback().await
        };

        transactions().remove(&params.transaction_id);

        match outcome {
            Ok(()) => task.add_result(Result::success(
                message,
                Vec::new(),
                calc_execution_ms(start_time),
            )).await,
            Err(error) => task.add_result(Result::error(
                message,
                self.errors.by_text(&format!("finalize transaction: {error}")),
            )).await,
        }
    }
}

/// The task's message as an Arc, for the states that outlive the call.
fn message_arc(task: &Task) -> Arc<crate::dto::Message> {
    task.message_arc()
}

async fn with_timeout<F, T>(timeout_ms: i64, future: F) -> std::result::Result<T, String>
where
    F: std::future::Future<Output = std::result::Result<T, String>>,
{
    if timeout_ms <= 0 {
        return future.await;
    }

    match tokio::time::timeout(Duration::from_millis(timeout_ms as u64), future).await {
        Ok(outcome) => outcome,
        Err(_) => Err("timeout".to_string()),
    }
}
