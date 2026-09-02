//! Mirrors ext/internal/features/sql/drivers_mysql.go and drivers_pgsql.go:
//! everything that differs between the two drivers, kept in one place so the
//! feature above stays driver-agnostic.
//!
//! Go gets this separation for free from database/sql. Here the two pool types
//! are distinct, so each operation is written twice — the price of not having a
//! single dynamic driver interface, and the reason this file exists at all.

use sqlx::{Column, Row};
use tokio::sync::mpsc;
use tokio_util::sync::CancellationToken;

use super::payloads::BeginParams;
use super::pools::Driver;
use super::rows_state::RowMessage;
use super::transactions::{TransactionSession, TxHandle};
use super::pg_bindings::{self, PgBound, PgRawText};
use super::values::{Binding, read_mysql_row, read_pg_row};
use futures_util::StreamExt;
use std::sync::Arc;

macro_rules! bind_mysql {
    ($query:expr, $bindings:expr) => {{
        let mut query = $query;

        for binding in $bindings {
            query = match binding {
                Binding::Null => query.bind(Option::<i64>::None),
                Binding::Bool(flag) => query.bind(*flag),
                Binding::Int(number) => query.bind(*number),
                Binding::Float(number) => query.bind(*number),
                Binding::Text(text) => query.bind(text.as_str()),
                Binding::Bytes(bytes) => query.bind(bytes.as_slice()),
            };
        }

        query
    }};
}

macro_rules! bind_pg {
    ($query:expr, $bindings:expr) => {{
        let mut query = $query;

        for binding in $bindings {
            query = match binding {
                PgBound::Null => query.bind(Option::<i64>::None),
                PgBound::Bool(flag) => query.bind(*flag),
                PgBound::SmallInt(number) => query.bind(*number),
                PgBound::Int4(number) => query.bind(*number),
                PgBound::Int(number) => query.bind(*number),
                PgBound::Float4(number) => query.bind(*number),
                PgBound::Float(number) => query.bind(*number),
                PgBound::Text(text) => query.bind(text.as_str()),
                PgBound::RawText(bytes) => query.bind(PgRawText(bytes.as_slice())),
                PgBound::Bytes(bytes) => query.bind(bytes.as_slice()),
                PgBound::Decimal(number) => query.bind(number.clone()),
                PgBound::Date(date) => query.bind(*date),
                PgBound::Timestamp(stamp) => query.bind(*stamp),
                PgBound::TimestampTz(stamp) => query.bind(*stamp),
            };
        }

        query
    }};
}

/// Describes the statement so each text binding can be sent as the type its
/// parameter slot actually holds. See pg_bindings for why this round-trip is
/// unavoidable; sqlx caches it with the prepared statement, so it is paid once
/// per distinct SQL string, not per call.
async fn pg_bind<'e, E>(executor: E, sql: &str, bindings: &[Binding]) -> Vec<PgBound>
where
    E: sqlx::Executor<'e, Database = sqlx::Postgres>,
{
    let parameters = match executor.describe(sql).await {
        Ok(described) => match described.parameters() {
            Some(sqlx::Either::Left(types)) => types.to_vec(),
            _ => Vec::new(),
        },
        // A describe failure is not reported here: the statement is about to run
        // anyway and will fail with the real message.
        Err(_) => Vec::new(),
    };

    pg_bindings::coerce(bindings, &parameters)
}

/// Runs a SELECT on a pooled connection and pushes its rows into the channel the
/// cursor state reads. Ends on the last row, on an error, on the cursor closing
/// or on the flow stopping — whichever comes first.
pub async fn run_query(
    driver: Driver,
    sql: String,
    bindings: Vec<Binding>,
    sender: mpsc::Sender<RowMessage>,
    cancel: CancellationToken,
    flow_cancel: CancellationToken,
    timeout_ms: i64,
) {
    let deadline = async {
        if timeout_ms > 0 {
            tokio::time::sleep(std::time::Duration::from_millis(timeout_ms as u64)).await;
        } else {
            std::future::pending::<()>().await;
        }
    };

    tokio::select! {
        _ = cancel.cancelled() => {}
        _ = flow_cancel.cancelled() => {}
        _ = deadline => {
            let _ = sender.send(RowMessage::Error("query timeout".to_string())).await;
        }
        _ = pump(driver, &sql, &bindings, &sender) => {}
    }
}

async fn pump(driver: Driver, sql: &str, bindings: &[Binding], sender: &mpsc::Sender<RowMessage>) {
    match driver {
        Driver::Mysql(pool) => {
            let query = bind_mysql!(sqlx::query(sql), bindings);
            let mut stream = query.fetch(&pool);
            let mut sent_columns = false;

            while let Some(item) = stream.next().await {
                match item {
                    Ok(row) => {
                        if !sent_columns {
                            sent_columns = true;

                            if sender.send(RowMessage::Columns(column_names(&row))).await.is_err() {
                                return;
                            }
                        }

                        match read_mysql_row(&row) {
                            Ok(values) => {
                                if sender.send(RowMessage::Row(values)).await.is_err() {
                                    return;
                                }
                            }
                            Err(error) => {
                                let _ = sender.send(RowMessage::Error(format!("scan error: {error}"))).await;

                                return;
                            }
                        }
                    }
                    Err(error) => {
                        let _ = sender.send(RowMessage::Error(format!("query error: {error}"))).await;

                        return;
                    }
                }
            }

            if !sent_columns {
                let _ = sender.send(RowMessage::Columns(Vec::new())).await;
            }
        }
        Driver::Pgsql(pool) => {
            let bound = pg_bind(&pool, sql, bindings).await;
            let query = bind_pg!(sqlx::query(sql), &bound);
            let mut stream = query.fetch(&pool);
            let mut sent_columns = false;

            while let Some(item) = stream.next().await {
                match item {
                    Ok(row) => {
                        if !sent_columns {
                            sent_columns = true;

                            if sender.send(RowMessage::Columns(column_names(&row))).await.is_err() {
                                return;
                            }
                        }

                        match read_pg_row(&row) {
                            Ok(values) => {
                                if sender.send(RowMessage::Row(values)).await.is_err() {
                                    return;
                                }
                            }
                            Err(error) => {
                                let _ = sender.send(RowMessage::Error(format!("scan error: {error}"))).await;

                                return;
                            }
                        }
                    }
                    Err(error) => {
                        let _ = sender.send(RowMessage::Error(format!("query error: {error}"))).await;

                        return;
                    }
                }
            }

            if !sent_columns {
                let _ = sender.send(RowMessage::Columns(Vec::new())).await;
            }
        }
    }
}

fn column_names<R: Row>(row: &R) -> Vec<String> {
    row.columns()
        .iter()
        .map(|column| column.name().to_string())
        .collect()
}

/// The transaction-bound SELECT. The session's handle is held for the whole
/// stream: a transaction is one connection, and its statements are serial by
/// nature.
pub async fn run_transaction_query(
    session: Arc<TransactionSession>,
    sql: String,
    bindings: Vec<Binding>,
    sender: mpsc::Sender<RowMessage>,
    cancel: CancellationToken,
    flow_cancel: CancellationToken,
) {
    tokio::select! {
        _ = cancel.cancelled() => {}
        _ = flow_cancel.cancelled() => {}
        _ = pump_transaction(&session, &sql, &bindings, &sender) => {}
    }
}

async fn pump_transaction(
    session: &TransactionSession,
    sql: &str,
    bindings: &[Binding],
    sender: &mpsc::Sender<RowMessage>,
) {
    let mut guard = session.handle.lock().await;

    let Some(handle) = guard.as_mut() else {
        let _ = sender
            .send(RowMessage::Error("transaction already finalized".to_string()))
            .await;

        return;
    };

    let mut sent_columns = false;

    match handle {
        TxHandle::Mysql(transaction) => {
            let query = bind_mysql!(sqlx::query(sql), bindings);
            let mut stream = query.fetch(&mut **transaction);

            while let Some(item) = stream.next().await {
                match item {
                    Ok(row) => {
                        if !sent_columns {
                            sent_columns = true;

                            if sender.send(RowMessage::Columns(column_names(&row))).await.is_err() {
                                return;
                            }
                        }

                        match read_mysql_row(&row) {
                            Ok(values) => {
                                if sender.send(RowMessage::Row(values)).await.is_err() {
                                    return;
                                }
                            }
                            Err(error) => {
                                let _ = sender.send(RowMessage::Error(format!("scan error: {error}"))).await;

                                return;
                            }
                        }
                    }
                    Err(error) => {
                        let _ = sender.send(RowMessage::Error(format!("query error: {error}"))).await;

                        return;
                    }
                }
            }
        }
        TxHandle::Pgsql(transaction) => {
            let bound = pg_bind(&mut **transaction, sql, bindings).await;
            let query = bind_pg!(sqlx::query(sql), &bound);
            let mut stream = query.fetch(&mut **transaction);

            while let Some(item) = stream.next().await {
                match item {
                    Ok(row) => {
                        if !sent_columns {
                            sent_columns = true;

                            if sender.send(RowMessage::Columns(column_names(&row))).await.is_err() {
                                return;
                            }
                        }

                        match read_pg_row(&row) {
                            Ok(values) => {
                                if sender.send(RowMessage::Row(values)).await.is_err() {
                                    return;
                                }
                            }
                            Err(error) => {
                                let _ = sender.send(RowMessage::Error(format!("scan error: {error}"))).await;

                                return;
                            }
                        }
                    }
                    Err(error) => {
                        let _ = sender.send(RowMessage::Error(format!("query error: {error}"))).await;

                        return;
                    }
                }
            }
        }
    }

    if !sent_columns {
        let _ = sender.send(RowMessage::Columns(Vec::new())).await;
    }
}

/// Returns (affectedRows, lastInsertId). Postgres has no last-insert-id, and
/// answers 0 there — the same value pgx gives database/sql.
pub async fn exec_on_pool(
    driver: Driver,
    sql: String,
    bindings: Vec<Binding>,
) -> Result<(i64, i64), String> {
    match driver {
        Driver::Mysql(pool) => {
            let query = bind_mysql!(sqlx::query(&sql), &bindings);
            let outcome = query.execute(&pool).await.map_err(|error| error.to_string())?;

            Ok((outcome.rows_affected() as i64, outcome.last_insert_id() as i64))
        }
        Driver::Pgsql(pool) => {
            let bound = pg_bind(&pool, &sql, &bindings).await;
            let query = bind_pg!(sqlx::query(&sql), &bound);
            let outcome = query.execute(&pool).await.map_err(|error| error.to_string())?;

            Ok((outcome.rows_affected() as i64, 0))
        }
    }
}

pub async fn exec_on_transaction(
    session: Arc<TransactionSession>,
    sql: String,
    bindings: Vec<Binding>,
) -> Result<(i64, i64), String> {
    let mut guard = session.handle.lock().await;

    let Some(handle) = guard.as_mut() else {
        return Err("transaction already finalized".to_string());
    };

    match handle {
        TxHandle::Mysql(transaction) => {
            let query = bind_mysql!(sqlx::query(&sql), &bindings);
            let outcome = query
                .execute(&mut **transaction)
                .await
                .map_err(|error| error.to_string())?;

            Ok((outcome.rows_affected() as i64, outcome.last_insert_id() as i64))
        }
        TxHandle::Pgsql(transaction) => {
            let bound = pg_bind(&mut **transaction, &sql, &bindings).await;
            let query = bind_pg!(sqlx::query(&sql), &bound);
            let outcome = query
                .execute(&mut **transaction)
                .await
                .map_err(|error| error.to_string())?;

            Ok((outcome.rows_affected() as i64, 0))
        }
    }
}

/// Opens the transaction. The isolation level and read-only flag are applied
/// with an explicit statement before BEGIN, which is what database/sql's
/// TxOptions does under the hood.
pub async fn begin_transaction(driver: Driver, params: &BeginParams) -> Result<TxHandle, String> {
    let prelude = isolation_statement(params);

    match driver {
        Driver::Mysql(pool) => {
            let mut transaction = pool.begin().await.map_err(|error| error.to_string())?;

            if let Some(statement) = prelude {
                sqlx::query(&statement)
                    .execute(&mut *transaction)
                    .await
                    .map_err(|error| error.to_string())?;
            }

            Ok(TxHandle::Mysql(transaction))
        }
        Driver::Pgsql(pool) => {
            let mut transaction = pool.begin().await.map_err(|error| error.to_string())?;

            if let Some(statement) = prelude {
                sqlx::query(&statement)
                    .execute(&mut *transaction)
                    .await
                    .map_err(|error| error.to_string())?;
            }

            Ok(TxHandle::Pgsql(transaction))
        }
    }
}

/// Maps Go's sql.IsolationLevel constants, which the PHP side sends verbatim.
/// Levels with no portable statement (Snapshot, Linearizable, WriteCommitted)
/// fall through to the server default rather than guessing.
fn isolation_statement(params: &BeginParams) -> Option<String> {
    let level = match params.isolation_level {
        1 => Some("READ UNCOMMITTED"),
        2 => Some("READ COMMITTED"),
        4 => Some("REPEATABLE READ"),
        6 => Some("SERIALIZABLE"),
        _ => None,
    };

    match (level, params.read_only) {
        (None, false) => None,
        (None, true) => Some("SET TRANSACTION READ ONLY".to_string()),
        (Some(level), false) => Some(format!("SET TRANSACTION ISOLATION LEVEL {level}")),
        (Some(level), true) => Some(format!("SET TRANSACTION ISOLATION LEVEL {level}, READ ONLY")),
    }
}
