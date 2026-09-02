//! Mirrors ext/internal/features/sql/rows_state.go.
//!
//! Streams a SELECT to PHP batch by batch, with the one-row look-ahead that
//! decides whether a batch is the last one.
//!
//! Go holds the open `*sql.Rows` in the state and pulls it on each Next. A
//! borrowed stream cannot be parked in a struct here, so the query runs in a
//! task of its own and hands rows over a bounded channel. The observable
//! behaviour is the same, including backpressure: the producer blocks once the
//! channel is full, so a slow PHP consumer still throttles the database read
//! instead of buffering the whole result.

use std::sync::{Arc, Mutex};
use std::time::Instant;
use tokio::sync::mpsc;
use tokio_util::sync::CancellationToken;

use crate::dto::{Message, Result};
use crate::errs::Factory;
use crate::helpers::calc_execution_ms;
use crate::states::{StateCloseFuture, StateContract, StateFuture};

use super::values::{ColumnValue, encode_batch};

/// What the producing task sends over. Columns arrive first so an empty result
/// still carries its shape.
pub enum RowMessage {
    Columns(Vec<String>),
    Row(Vec<ColumnValue>),
    Error(String),
}

pub struct RowsState {
    message: Arc<Message>,
    batch_size: i64,
    err_factory: &'static Factory,
    start_time: Instant,

    receiver: tokio::sync::Mutex<mpsc::Receiver<RowMessage>>,
    columns: Mutex<Vec<String>>,
    /// The row peeked past the end of the previous batch.
    pending: Mutex<Option<Vec<ColumnValue>>>,
    /// Cancels the producing task, and with it the query, on close.
    cancel: CancellationToken,
}

impl RowsState {
    pub fn new(
        message: Arc<Message>,
        batch_size: i64,
        err_factory: &'static Factory,
        receiver: mpsc::Receiver<RowMessage>,
        cancel: CancellationToken,
    ) -> Self {
        RowsState {
            message,
            batch_size,
            err_factory,
            start_time: Instant::now(),
            receiver: tokio::sync::Mutex::new(receiver),
            columns: Mutex::new(Vec::new()),
            pending: Mutex::new(None),
            cancel,
        }
    }

    fn error(&self, text: String) -> Result {
        Result::error(&self.message, text)
    }

    fn batch(&self, rows: &[Vec<ColumnValue>], has_next: bool) -> Result {
        let columns = self.columns.lock().unwrap().clone();
        let payload = encode_batch(&columns, rows);

        if has_next {
            Result::success_with_next(&self.message, payload, calc_execution_ms(self.start_time))
        } else {
            Result::success(&self.message, payload, calc_execution_ms(self.start_time))
        }
    }
}

impl StateContract for RowsState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            let mut receiver = self.receiver.lock().await;

            let mut rows: Vec<Vec<ColumnValue>> = Vec::new();

            if let Some(peeked) = self.pending.lock().unwrap().take() {
                rows.push(peeked);
            }

            loop {
                // A batch size of zero means "everything in one batch", which is
                // how Go reads `batchSize <= 0`.
                if self.batch_size > 0 && rows.len() as usize >= self.batch_size as usize {
                    // Look one row ahead: if there is more, this batch is
                    // non-final and the peeked row is stashed for the next call.
                    match receiver.recv().await {
                        Some(RowMessage::Row(row)) => {
                            *self.pending.lock().unwrap() = Some(row);

                            return self.batch(&rows, true);
                        }
                        Some(RowMessage::Columns(columns)) => {
                            *self.columns.lock().unwrap() = columns;
                        }
                        Some(RowMessage::Error(text)) => return self.error(text),
                        None => return self.batch(&rows, false),
                    }

                    continue;
                }

                match receiver.recv().await {
                    Some(RowMessage::Row(row)) => rows.push(row),
                    Some(RowMessage::Columns(columns)) => {
                        *self.columns.lock().unwrap() = columns;
                    }
                    Some(RowMessage::Error(text)) => {
                        return self.error(self.err_factory.by_text(&text));
                    }
                    None => return self.batch(&rows, false),
                }
            }
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            // Stops the producing task; the pooled connection it holds is
            // released when that task unwinds, which is the counterpart of Go's
            // `release`.
            self.cancel.cancel();
        })
    }
}
