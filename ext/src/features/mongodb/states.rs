//! Mirrors ext-go-legacy/internal/features/mongodb/states: the find and aggregate cursors
//! PHP pulls one batch at a time through `next()`.
//!
//! The driver's cursor is an owned value, so unlike the SQL rows it can simply
//! live in the state — no producer task, no channel. A one-document look-ahead
//! decides whether a batch is the last one, exactly as on the Go side.

use mongodb::bson::Document;
use mongodb::Cursor;
use std::sync::Arc;
use std::time::Instant;

use crate::dto::{Message, Result};
use crate::errs::Factory;
use crate::helpers::calc_execution_ms;
use crate::states::{StateContract, StateFuture};

use super::serializer::documents_to_msgpack;

pub struct CursorState {
    message: Arc<Message>,
    batch_size: i64,
    err_factory: &'static Factory,
    start_time: Instant,
    /// Shared so `close` can hand the drop to the runtime instead of racing a
    /// batch for the lock.
    inner: Arc<tokio::sync::Mutex<CursorInner>>,
}

struct CursorInner {
    cursor: Option<Cursor<Document>>,
    /// The document read past the end of the previous batch.
    pending: Option<Document>,
    exhausted: bool,
}

impl CursorState {
    pub fn new(
        message: Arc<Message>,
        cursor: Cursor<Document>,
        batch_size: i64,
        err_factory: &'static Factory,
    ) -> Self {
        CursorState {
            message,
            batch_size,
            err_factory,
            start_time: Instant::now(),
            inner: Arc::new(tokio::sync::Mutex::new(CursorInner {
                cursor: Some(cursor),
                pending: None,
                exhausted: false,
            })),
        }
    }


    fn batch(&self, documents: &[Document], has_next: bool) -> Result {
        match documents_to_msgpack(documents) {
            Ok(payload) => {
                if has_next {
                    Result::success_with_next(
                        &self.message,
                        payload,
                        calc_execution_ms(self.start_time),
                    )
                } else {
                    Result::success(&self.message, payload, calc_execution_ms(self.start_time))
                }
            }
            Err(error) => Result::error(
                &self.message,
                self.err_factory.by_text(&format!("marshal batch: {error}")),
            ),
        }
    }
}

impl StateContract for CursorState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            let mut inner = self.inner.lock().await;

            let mut documents: Vec<Document> = Vec::new();

            if let Some(pending) = inner.pending.take() {
                documents.push(pending);
            }

            // A batch size of zero means "everything in one batch", which is how
            // Go reads a non-positive batchSize.
            let limit = if self.batch_size > 0 {
                self.batch_size as usize
            } else {
                usize::MAX
            };

            loop {
                if documents.len() >= limit {
                    // Look one document ahead: if there is more, this batch is
                    // non-final and the peeked document is stashed.
                    match advance(&mut inner).await {
                        Ok(Some(document)) => {
                            inner.pending = Some(document);

                            return self.batch(&documents, true);
                        }
                        Ok(None) => return self.batch(&documents, false),
                        Err(error) => {
                            return Result::error(
                                &self.message,
                                self.err_factory.by_text(&format!("cursor error: {error}")),
                            );
                        }
                    }
                }

                match advance(&mut inner).await {
                    Ok(Some(document)) => documents.push(document),
                    Ok(None) => return self.batch(&documents, false),
                    Err(error) => {
                        return Result::error(
                            &self.message,
                            self.err_factory.by_text(&format!("cursor error: {error}")),
                        );
                    }
                }
            }
        })
    }

    fn close(&self) -> crate::states::StateCloseFuture<'_> {
        Box::pin(async move {
            // Dropping the cursor is what closes it server-side: the driver
            // sends killCursors from the cursor's own drop. Awaited rather than
            // spawned, so an abandoned cursor is gone before PHP asks the server
            // how many are open.
            let mut inner = self.inner.lock().await;

            inner.cursor = None;
            inner.exhausted = true;
        })
    }
}

async fn advance(inner: &mut CursorInner) -> std::result::Result<Option<Document>, String> {
    if inner.exhausted {
        return Ok(None);
    }

    let Some(cursor) = inner.cursor.as_mut() else {
        return Ok(None);
    };

    match cursor.advance().await {
        Ok(true) => cursor
            .deserialize_current()
            .map(Some)
            .map_err(|error| error.to_string()),
        Ok(false) => {
            inner.exhausted = true;
            inner.cursor = None;

            Ok(None)
        }
        Err(error) => {
            inner.exhausted = true;
            inner.cursor = None;

            Err(error.to_string())
        }
    }
}
