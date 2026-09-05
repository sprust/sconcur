//! Everything that differs between the two SQL drivers, kept in one place so
//! the feature above stays driver-agnostic.
//!
//! The two pool types are distinct, so each operation is written twice — the
//! price of not having one dynamic driver interface, and the reason this file
//! exists at all.

// sqlx 0.9 refuses a non-literal SQL string unless the caller says it audited
// it. Here the string IS the product: the PHP side hands the extension the query
// it wants run, exactly as PDO does, and parameters travel as bindings beside it
// rather than interpolated into it. The assertion states that; it does not make
// anything safe that was not.
use sqlx::{AssertSqlSafe, Column, Either, Executor, Row};
use tokio::sync::mpsc;
use tokio_util::sync::CancellationToken;

use super::payloads::BeginParams;
use super::pools::Driver;
use super::rows_state::RowMessage;
use super::transactions::{TransactionSession, TxHandle};
use super::pg_simple;
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

/// Whether a MySQL statement has to travel as text rather than as a prepared
/// statement.
///
/// MySQL refuses a whole class of commands in the prepared-statement protocol
/// with error 1295, "This command is not supported in the prepared statement
/// protocol yet": `SAVEPOINT`, `RELEASE SAVEPOINT`, `ROLLBACK TO SAVEPOINT`,
/// `LOCK TABLES`, `UNLOCK TABLES` and the rest of the list in docs/mysql.md.
/// Nested transactions rest on the three savepoint commands, so without this a
/// framework that opens a transaction inside a transaction cannot run at all.
///
/// sqlx picks the protocol by whether the query carries arguments, so the way to
/// reach the text one is to pass none — which is exactly what `raw_sql` does,
/// and what the Postgres side already does for its own reason (see pg_simple).
/// A statement with nothing to bind loses nothing by travelling as text;
/// anything with bindings keeps the prepared protocol and its escaping.
///
/// What the text protocol does add is statement stacking: sqlx asks for
/// `CLIENT_MULTI_STATEMENTS` in the handshake, so a bindings-free string holding
/// several statements separated by `;` runs all of them. That it runs them is
/// named in docs/mysql.md rather than guarded against here, because guarding
/// would mean parsing the caller's SQL to find a `;` that is not inside a
/// literal — and this core never parses it (see pg_simple for the same rule on
/// the other driver). What is guarded is the part that cannot be described on
/// the way back: a second *result set*, see `MULTI_RESULT_SET`.
fn mysql_is_textual(bindings: &[Binding]) -> bool {
    bindings.is_empty()
}

/// What a stacked SELECT is refused with. A cursor describes one result set —
/// one column list for the whole stream — so rows of a second one would reach
/// PHP keyed by the first one's column names, which is silent nonsense rather
/// than a shortfall. `fetch_many` marks the end of each statement, so the
/// boundary is visible and this says so out loud.
const MULTI_RESULT_SET: &str =
    "query error: the statement returned more than one result set; run one select per query";

/// Sends one MySQL row, preceded by the column list on the first row. `Err`
/// means the stream is over — the receiver is gone, or the row could not be
/// read and the error has already been sent.
async fn send_mysql_row(
    row: &sqlx::mysql::MySqlRow,
    sent_columns: &mut bool,
    sender: &mpsc::Sender<RowMessage>,
) -> std::result::Result<(), ()> {
    if !*sent_columns {
        *sent_columns = true;

        if sender.send(RowMessage::Columns(column_names(row))).await.is_err() {
            return Err(());
        }
    }

    match read_mysql_row(row) {
        Ok(values) => match sender.send(RowMessage::Row(values)).await {
            Ok(()) => Ok(()),
            Err(_) => Err(()),
        },
        Err(error) => {
            let _ = sender.send(RowMessage::Error(format!("scan error: {error}"))).await;

            Err(())
        }
    }
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
            let mut sent_columns = false;

            if mysql_is_textual(bindings) {
                let mut stream = pool.fetch_many(sqlx::raw_sql(AssertSqlSafe(sql)));
                let mut statement_ended = false;

                while let Some(item) = stream.next().await {
                    match item {
                        Ok(Either::Left(_)) => statement_ended = true,
                        Ok(Either::Right(row)) => {
                            if statement_ended {
                                let _ = sender.send(RowMessage::Error(MULTI_RESULT_SET.to_string())).await;

                                return;
                            }

                            if send_mysql_row(&row, &mut sent_columns, sender).await.is_err() {
                                return;
                            }
                        }
                        Err(error) => {
                            let _ = sender.send(RowMessage::Error(format!("query error: {error}"))).await;

                            return;
                        }
                    }
                }
            } else {
                let mut stream = bind_mysql!(sqlx::query(AssertSqlSafe(sql)), bindings).fetch(&pool);

                while let Some(item) = stream.next().await {
                    match item {
                        Ok(row) => {
                            if send_mysql_row(&row, &mut sent_columns, sender).await.is_err() {
                                return;
                            }
                        }
                        Err(error) => {
                            let _ = sender.send(RowMessage::Error(format!("query error: {error}"))).await;

                            return;
                        }
                    }
                }
            }

            if !sent_columns {
                let _ = sender.send(RowMessage::Columns(Vec::new())).await;
            }
        }
        Driver::Pgsql(pool) => {
            // Executed as a bare &str: sqlx's Execute for &str reports no
            // arguments, which is what sends this down the simple protocol.
            let statement = match pg_simple::statement(sql, bindings) {
                Ok(statement) => statement,
                Err(error) => {
                    let _ = sender.send(RowMessage::Error(error)).await;

                    return;
                }
            };

            let mut stream = pool.fetch(sqlx::raw_sql(AssertSqlSafe(statement.as_str())));
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
            if mysql_is_textual(bindings) {
                let mut stream = (&mut **transaction).fetch_many(sqlx::raw_sql(AssertSqlSafe(sql)));
                let mut statement_ended = false;

                while let Some(item) = stream.next().await {
                    match item {
                        Ok(Either::Left(_)) => statement_ended = true,
                        Ok(Either::Right(row)) => {
                            if statement_ended {
                                let _ = sender.send(RowMessage::Error(MULTI_RESULT_SET.to_string())).await;

                                return;
                            }

                            if send_mysql_row(&row, &mut sent_columns, sender).await.is_err() {
                                return;
                            }
                        }
                        Err(error) => {
                            let _ = sender.send(RowMessage::Error(format!("query error: {error}"))).await;

                            return;
                        }
                    }
                }
            } else {
                let mut stream =
                    bind_mysql!(sqlx::query(AssertSqlSafe(sql)), bindings).fetch(&mut **transaction);

                while let Some(item) = stream.next().await {
                    match item {
                        Ok(row) => {
                            if send_mysql_row(&row, &mut sent_columns, sender).await.is_err() {
                                return;
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
        TxHandle::Pgsql(transaction) => {
            let statement = match pg_simple::statement(sql, bindings) {
                Ok(statement) => statement,
                Err(error) => {
                    let _ = sender.send(RowMessage::Error(error)).await;

                    return;
                }
            };

            let mut stream = (&mut **transaction).fetch(sqlx::raw_sql(AssertSqlSafe(statement.as_str())));

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
            let outcome = if mysql_is_textual(&bindings) {
                pool.execute(sqlx::raw_sql(AssertSqlSafe(sql.as_str()))).await
            } else {
                bind_mysql!(sqlx::query(AssertSqlSafe(sql.as_str())), &bindings)
                    .execute(&pool)
                    .await
            }
            .map_err(|error| error.to_string())?;

            Ok((outcome.rows_affected() as i64, outcome.last_insert_id() as i64))
        }
        Driver::Pgsql(pool) => {
            let statement = pg_simple::statement(&sql, &bindings)?;

            let outcome = pool
                .execute(sqlx::raw_sql(AssertSqlSafe(statement.as_str())))
                .await
                .map_err(|error| error.to_string())?;

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
            let outcome = if mysql_is_textual(&bindings) {
                (&mut **transaction)
                    .execute(sqlx::raw_sql(AssertSqlSafe(sql.as_str())))
                    .await
            } else {
                bind_mysql!(sqlx::query(AssertSqlSafe(sql.as_str())), &bindings)
                    .execute(&mut **transaction)
                    .await
            }
            .map_err(|error| error.to_string())?;

            Ok((outcome.rows_affected() as i64, outcome.last_insert_id() as i64))
        }
        TxHandle::Pgsql(transaction) => {
            let statement = pg_simple::statement(&sql, &bindings)?;

            let outcome = (&mut **transaction)
                .execute(sqlx::raw_sql(AssertSqlSafe(statement.as_str())))
                .await
                .map_err(|error| error.to_string())?;

            Ok((outcome.rows_affected() as i64, 0))
        }
    }
}

/// Opens the transaction, with the isolation level and the read-only flag folded
/// into the statement that starts it.
///
/// They used to be applied by a `SET TRANSACTION` issued after `pool.begin()`,
/// which the docblock described as happening "before BEGIN" and which MySQL
/// rejects outright: error 1568, "Transaction characteristics can't be changed
/// while a transaction is in progress". Both `begin(isolationLevel:)` and
/// `begin(readOnly:)` therefore failed on MySQL, and Postgres survived only
/// because it tolerates `SET TRANSACTION` as a transaction's first statement.
pub async fn begin_transaction(driver: Driver, params: &BeginParams) -> Result<TxHandle, String> {
    match driver {
        Driver::Mysql(pool) => {
            let statement = mysql_begin_statement(params)?;

            // Unlike the queries above, this string is ours: mysql_begin_statement
            // builds it out of the enum values the payload was parsed into, so
            // nothing of the caller's text reaches it.
            let transaction = pool
                .begin_with(AssertSqlSafe(statement))
                .await
                .map_err(|error| error.to_string())?;

            Ok(TxHandle::Mysql(transaction))
        }
        Driver::Pgsql(pool) => {
            let transaction = pool
                .begin_with(AssertSqlSafe(pg_begin_statement(params)))
                .await
                .map_err(|error| error.to_string())?;

            Ok(TxHandle::Pgsql(transaction))
        }
    }
}

/// Postgres takes both characteristics in the BEGIN itself, so one statement
/// says everything.
fn pg_begin_statement(params: &BeginParams) -> String {
    let mut statement = String::from("BEGIN");

    if let Some(level) = isolation_level(params.isolation_level) {
        statement.push_str(" ISOLATION LEVEL ");
        statement.push_str(level);
    }

    if params.read_only {
        statement.push_str(" READ ONLY");
    }

    statement
}

/// MySQL takes the access mode in `START TRANSACTION` but not the isolation
/// level: that one needs a separate `SET TRANSACTION` issued on the same
/// connection *before* the transaction starts, and sqlx hands out a transaction
/// that owns its pooled connection, so there is no moment at which this code
/// holds that connection and has not yet begun.
///
/// It is refused rather than applied wrongly, the way the AMQP driver refuses a
/// prefetch size it cannot put on the wire (see docs/amqp.md). Silently ignoring
/// it would be worse: a caller who asked for SERIALIZABLE and got REPEATABLE
/// READ has no way to find out.
fn mysql_begin_statement(params: &BeginParams) -> Result<String, String> {
    if isolation_level(params.isolation_level).is_some() {
        return Err(
            "isolationLevel is not supported on MySQL; set it on the session or the server"
                .to_string(),
        );
    }

    Ok(if params.read_only {
        "START TRANSACTION READ ONLY".to_string()
    } else {
        "START TRANSACTION".to_string()
    })
}

/// Maps the isolation levels the PHP side sends, which are the database/sql
/// constants the API was first written against and are part of its contract.
/// Levels with no portable statement (Snapshot, Linearizable, WriteCommitted)
/// fall through to the server default rather than guessing.
fn isolation_level(level: i64) -> Option<&'static str> {
    match level {
        1 => Some("READ UNCOMMITTED"),
        2 => Some("READ COMMITTED"),
        4 => Some("REPEATABLE READ"),
        6 => Some("SERIALIZABLE"),
        _ => None,
    }
}

