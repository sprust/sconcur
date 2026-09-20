//! The streamed writer: a file PHP fills chunk by chunk instead of handing over
//! whole.
//!
//! Three commands make one session. WriteOpen opens the file and registers it
//! under the id PHP drew; WriteChunk writes one chunk and does not answer until
//! the write is done, which is the whole of the backpressure — a coroutine
//! cannot outrun the disk because its next push is what waits; WriteClose
//! flushes, closes and answers with the total.
//!
//! The session is a file behind a lock rather than a channel and a draining
//! task, because unlike HttpClient's upload — where reqwest demands a body
//! stream — nothing here needs the writing to happen somewhere else.
//!
//! A session nobody closes is not a leak: the state registry hooks its cleanup
//! to the flow, so a coroutine that dies mid-write has its file closed and, if
//! the write never finished, removed.

use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Instant;

use tokio::io::AsyncWriteExt;

use crate::dto::Result;
use crate::helpers::calc_execution_ms;
use crate::states::{self, StateCloseFuture, StateContract, StateFuture};
use crate::tasks::Task;

use super::content::bytes_of;
use super::errors::{io_message, message as fail, Kind};
use super::payloads;
use super::{bounded, params, write_options};

/// One open writer.
pub struct Session {
    /// None once the writer has been closed, or once its flow took it away.
    file: tokio::sync::Mutex<Option<tokio::fs::File>>,
    path: String,
    append: bool,
    written: AtomicU64,
    /// Whether close() ran and the bytes are on their way to the disk. What
    /// tells an abandoned writer — whose partial file goes — from a finished
    /// one, whose file stays.
    completed: AtomicBool,
}

impl Session {
    /// Writes one chunk, answering only once it is written.
    async fn write(&self, chunk: &[u8]) -> std::result::Result<u64, String> {
        let mut guard = self.file.lock().await;

        let Some(file) = guard.as_mut() else {
            return Err(fail(
                Kind::State,
                &format!("the writer for {} is closed", self.path),
            ));
        };

        file.write_all(chunk)
            .await
            .map_err(|error| io_message("write", &self.path, &error))?;

        Ok(self.written.fetch_add(chunk.len() as u64, Ordering::Relaxed) + chunk.len() as u64)
    }

    /// Flushes and closes, answering with the total written.
    async fn finish(&self) -> std::result::Result<u64, String> {
        let mut guard = self.file.lock().await;

        let Some(mut file) = guard.take() else {
            return Err(fail(
                Kind::State,
                &format!("the writer for {} is closed", self.path),
            ));
        };

        file.flush()
            .await
            .map_err(|error| io_message("flush", &self.path, &error))?;

        // Marked before the answer, so a flow ending in the same breath as the
        // close does not read this as an abandoned writer and remove the file
        // that was just finished.
        self.completed.store(true, Ordering::Release);

        Ok(self.written.load(Ordering::Relaxed))
    }

    /// Gives the file up without finishing it. Called when the flow ends under
    /// a writer nobody closed.
    async fn abandon(&self) {
        let taken = self.file.lock().await.take();

        drop(taken);

        if self.completed.load(Ordering::Acquire) || self.append {
            return;
        }

        // The same rule the single-shot writes keep: what this call created and
        // did not finish goes, what it only appended to stays.
        let _ = tokio::fs::remove_file(&self.path).await;
    }
}

/// The open writers of the process, owned by the Core so a fork does not
/// inherit file handles it has no business holding.
pub struct Registries {
    sessions: Mutex<HashMap<String, Arc<Session>>>,
}

impl Registries {
    pub fn new() -> Self {
        Registries {
            sessions: Mutex::new(HashMap::new()),
        }
    }

    fn insert(&self, id: String, session: Arc<Session>) -> std::result::Result<(), String> {
        let mut sessions = self.sessions.lock().unwrap();

        if sessions.contains_key(&id) {
            return Err(format!("a writer with the id {id} is already open"));
        }

        sessions.insert(id, session);

        Ok(())
    }

    fn get(&self, id: &str) -> Option<Arc<Session>> {
        self.sessions.lock().unwrap().get(id).cloned()
    }

    fn remove(&self, id: &str) -> Option<Arc<Session>> {
        self.sessions.lock().unwrap().remove(id)
    }
}

fn registries() -> &'static Registries {
    crate::core::get().files()
}

/// What the state registry holds for an open writer.
///
/// It never answers a next(): PHP pushes chunks, it does not pull them. The
/// state is registered anyway because that is what ties the writer's lifetime
/// to the flow — see states::register_with_flow, which exists for exactly this
/// shape of stream.
struct WriterState {
    id: String,
    session: Arc<Session>,
    /// Kept only so the unreachable next() has a frame to answer in.
    message: Arc<crate::dto::Message>,
}

impl StateContract for WriterState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            // Unreachable through the public API: nothing on the PHP side calls
            // next() on a writer. Answered rather than panicked, because a
            // wrong task key must not take the process down.
            crate::dto::Result::error(
                &self.message,
                fail(
                    Kind::State,
                    &format!("the writer for {} is written to, not read", self.session.path),
                ),
            )
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            registries().remove(&self.id);

            self.session.abandon().await;
        })
    }
}

/// Opens a writer and registers it under the id PHP drew.
pub async fn open(task: &Task, envelope: &payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::WriteOpenParams>(task, envelope, "openWriter").await
    else {
        return;
    };

    if parameters.id.is_empty() {
        task.add_result(Result::error(
            message,
            fail(Kind::Argument, "a writer needs an id"),
        ))
        .await;

        return;
    }

    let Some(options) = write_options(&parameters.mode, parameters.permissions) else {
        task.add_result(Result::error(
            message,
            fail(
                Kind::Argument,
                &format!("unknown write mode {}", parameters.mode),
            ),
        ))
        .await;

        return;
    };

    let path = parameters.path.clone();

    let opened = bounded(task, envelope.timeout_ms, async move {
        options
            .open(&path)
            .await
            .map_err(|error| io_message("open", &path, &error))
    })
    .await;

    let file = match opened {
        Some(Ok(file)) => file,
        Some(Err(text)) => {
            task.add_result(Result::error(message, text)).await;

            return;
        }
        None => return,
    };

    let session = Arc::new(Session {
        file: tokio::sync::Mutex::new(Some(file)),
        path: parameters.path.clone(),
        append: parameters.mode == "app",
        written: AtomicU64::new(0),
        completed: AtomicBool::new(false),
    });

    if let Err(error) = registries().insert(parameters.id.clone(), session.clone()) {
        task.add_result(Result::error(message, fail(Kind::State, &error)))
            .await;

        return;
    }

    let state = Arc::new(WriterState {
        id: parameters.id.clone(),
        session,
        message: task.message_arc(),
    });

    // Keyed by the writer's id rather than by the task key of this push: the
    // chunks that follow arrive as pushes of their own, each with a task key of
    // its own, and the id is the only name all three commands share.
    if let Err(error) = states::get().register_with_flow(
        task.context().clone(),
        parameters.id.clone(),
        state,
        || {},
    ) {
        registries().remove(&parameters.id);

        task.add_result(Result::error(message, fail(Kind::State, &error)))
            .await;

        return;
    }

    // success_with_next, although nothing will ever pull a batch: on the
    // synchronous path FeatureExecutor stops the flow the moment a result says
    // it is the last one, and the flow ending is what releases this writer. A
    // writer that answered "finished" here would be closed and its file removed
    // before the first chunk arrived. PHP releases the flow itself when the
    // writer closes — the same bargain a SQL transaction makes.
    task.add_result(Result::success_with_next(
        message,
        super::meta::encode_text("p", &parameters.path),
        calc_execution_ms(start_time),
    ))
    .await;
}

/// Writes one chunk. The answer waits for the write, which is what stops a fast
/// producer from running ahead of the disk.
pub async fn chunk(task: &Task, envelope: &payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::WriteChunkParams>(task, envelope, "write").await
    else {
        return;
    };

    let Some(session) = registries().get(&parameters.id) else {
        task.add_result(Result::error(
            message,
            fail(
                Kind::State,
                &format!("no open writer with the id {}", parameters.id),
            ),
        ))
        .await;

        return;
    };

    let chunk = match bytes_of(&parameters.chunk) {
        Ok(chunk) => chunk,
        Err(error) => {
            task.add_result(Result::error(message, fail(Kind::Argument, &error)))
                .await;

            return;
        }
    };

    let work = async move { session.write(&chunk).await };

    match bounded(task, envelope.timeout_ms, work).await {
        Some(Ok(total)) => {
            task.add_result(Result::success(
                message,
                super::content::encode_count(total),
                calc_execution_ms(start_time),
            ))
            .await;
        }
        Some(Err(text)) => {
            task.add_result(Result::error(message, text)).await;
        }
        None => {}
    }
}

/// Flushes, closes and answers with the total written.
pub async fn close(task: &Task, envelope: &payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::WriteCloseParams>(task, envelope, "close").await
    else {
        return;
    };

    let Some(session) = registries().get(&parameters.id) else {
        task.add_result(Result::error(
            message,
            fail(
                Kind::State,
                &format!("no open writer with the id {}", parameters.id),
            ),
        ))
        .await;

        return;
    };

    let outcome = bounded(task, envelope.timeout_ms, {
        let session = session.clone();

        async move { session.finish().await }
    })
    .await;

    // The state goes either way: a close that failed leaves nothing worth
    // keeping open, and delete_state is what runs the cleanup and retires the
    // flow hook.
    states::get().delete_state(&parameters.id).await;

    match outcome {
        Some(Ok(total)) => {
            task.add_result(Result::success(
                message,
                super::content::encode_count(total),
                calc_execution_ms(start_time),
            ))
            .await;
        }
        Some(Err(text)) => {
            task.add_result(Result::error(message, text)).await;
        }
        None => {}
    }
}
