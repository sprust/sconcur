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
//! to the flow, so a coroutine that dies mid-write has its file closed — and
//! removed only when this writer is the thing that created it, which means only
//! in Create mode. The rule is the one every write in this feature keeps; see
//! mod.rs, creates_the_file.

use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Instant;

use tokio::io::AsyncWriteExt;

use crate::dto::Result;
use crate::helpers::calc_execution_ms;
use crate::states::{self, StateCloseFuture, StateContract, StateFuture};
use crate::tasks::Task;

use super::content::{bytes_of, drop_partial};
use super::errors::{io_message, message as fail, Kind};
use super::payloads;
use super::{bounded, creates_the_file, params, permission_bits, write_options};

/// What a writer's state is registered under. The registry is keyed by task key
/// and shared with every stream in the process, so a writer id — which is a
/// string the caller chose — is prefixed rather than used raw: a caller cannot
/// name its writer after somebody's task and take that stream away.
const STATE_KEY_PREFIX: &str = "files-writer:";

/// Long enough for any id worth drawing, short enough that the registry's keys
/// stay a fixed cost.
const MAX_WRITER_ID_LENGTH: usize = 64;

fn state_key(id: &str) -> String {
    format!("{STATE_KEY_PREFIX}{id}")
}

/// A close that did not happen, and whether trying again could change that.
struct Failure {
    text: String,
    terminal: bool,
}

impl Failure {
    fn terminal(text: String) -> Self {
        Failure {
            text,
            terminal: true,
        }
    }

    fn retryable(text: String) -> Self {
        Failure {
            text,
            terminal: false,
        }
    }
}

/// One open writer.
pub struct Session {
    /// None once the writer has been closed, or once its flow took it away.
    file: tokio::sync::Mutex<Option<tokio::fs::File>>,
    path: String,
    /// Whether this writer is the thing that brought the file into existence,
    /// and so the only case in which giving up may remove it. Same rule as the
    /// single-shot writes keep — see mod.rs, creates_the_file.
    created_by_us: bool,
    written: AtomicU64,
    /// Whether close() ran and the bytes are on their way to the disk. What
    /// tells an abandoned writer — whose partial file goes — from a finished
    /// one, whose file stays.
    completed: AtomicBool,
    /// Whether a close was started, whatever became of it. A close that ran out
    /// of time still handed every chunk over, so its file is not the half-thing
    /// an abandoned writer leaves and must not be removed.
    closing: AtomicBool,
    /// Whether a chunk was cut off mid-write. The file then holds a partial
    /// chunk nobody counted, so every later call fails rather than letting a
    /// retry double the bytes or a close report a total the file does not have.
    poisoned: AtomicBool,
}

impl Session {
    /// Writes one chunk, answering only once it is written.
    ///
    /// The poison flag is raised before the write and lowered after it, so a
    /// chunk cut off half-way leaves it raised: the future is dropped mid-write
    /// and nothing runs to lower it. Every later call then fails, because the
    /// file holds bytes no total accounts for.
    async fn write(&self, chunk: &[u8]) -> std::result::Result<u64, String> {
        let mut guard = self.file.lock().await;

        if self.poisoned.load(Ordering::Acquire) {
            return Err(fail(
                Kind::State,
                &format!(
                    "the writer for {} was cut off mid-chunk and cannot be used further",
                    self.path
                ),
            ));
        }

        let Some(file) = guard.as_mut() else {
            return Err(fail(
                Kind::State,
                &format!("the writer for {} is closed", self.path),
            ));
        };

        self.poisoned.store(true, Ordering::Release);

        // Lowered only on success. write_all can have handed part of the chunk
        // to the pool before the error surfaces, and the total below is never
        // advanced for a failed write — so a session that stayed usable after
        // one would go on to report a total smaller than the file.
        let written = file.write_all(chunk).await;

        if let Err(error) = written {
            return Err(io_message("write", &self.path, &error));
        }

        self.poisoned.store(false, Ordering::Release);

        Ok(self.written.fetch_add(chunk.len() as u64, Ordering::Relaxed) + chunk.len() as u64)
    }

    /// Flushes and closes, answering with the total written.
    ///
    /// The error says whether the failure is terminal — whether there is any
    /// point in the caller trying again. A flush that ran out of time is not:
    /// the file and the session are still here and a second close can finish
    /// them. A writer whose chunk was cut off is, because the file holds bytes
    /// no total accounts for and no further call can make that true again.
    async fn finish(&self) -> std::result::Result<u64, Failure> {
        let mut guard = self.file.lock().await;

        // Checked before `closing` is raised, not after. Raising it first would
        // tell the cleanup that this file was meant to be kept — and a writer
        // cut off mid-chunk is exactly the one whose file must still go.
        if self.poisoned.load(Ordering::Acquire) {
            return Err(Failure::terminal(fail(
                Kind::State,
                &format!(
                    "the writer for {} was cut off mid-chunk and cannot be closed cleanly",
                    self.path
                ),
            )));
        }

        let Some(file) = guard.as_mut() else {
            return Err(Failure::terminal(fail(
                Kind::State,
                &format!("the writer for {} is closed", self.path),
            )));
        };

        // From here on every chunk has been handed over and only the flush is in
        // doubt, so the file is one the caller meant to keep whatever happens.
        self.closing.store(true, Ordering::Release);

        // The handle is given up only once the flush has succeeded. Taking it
        // first — which this used to do — meant a flush that ran out of time
        // left the session alive but empty, so the retry it was supposed to
        // allow answered "is closed" instead.
        if let Err(error) = file.flush().await {
            return Err(Failure::retryable(io_message(
                "flush",
                &self.path,
                &error,
            )));
        }

        let _ = guard.take();

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

        if self.completed.load(Ordering::Acquire)
            || self.closing.load(Ordering::Acquire)
            || !self.created_by_us
        {
            return;
        }

        // What is left is a writer nobody ever tried to close, on a file this
        // call created. Only then is removing it the caller's own intent
        // finished rather than their data taken.
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

    fn contains(&self, id: &str) -> bool {
        self.sessions.lock().unwrap().contains_key(id)
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
pub async fn open(task: &Task, envelope: &mut payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::WriteOpenParams>(task, envelope, "openWriter").await
    else {
        return;
    };

    if parameters.id.is_empty() || parameters.id.len() > MAX_WRITER_ID_LENGTH {
        task.add_result(Result::error(
            message,
            fail(
                Kind::Argument,
                "a writer id must be between 1 and 64 characters",
            ),
        ))
        .await;

        return;
    }

    let permissions = match permission_bits(parameters.permissions, 0o644) {
        Ok(permissions) => permissions,
        Err(text) => {
            task.add_result(Result::error(message, text)).await;

            return;
        }
    };

    let Some(options) = write_options(&parameters.mode, permissions) else {
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

    // Reserved before the file is touched. Opening first and checking the id
    // afterwards meant a Replace open under an id already in use truncated the
    // live writer's file and only then reported the collision.
    if registries().contains(&parameters.id) {
        task.add_result(Result::error(
            message,
            fail(
                Kind::State,
                &format!("a writer with the id {} is already open", parameters.id),
            ),
        ))
        .await;

        return;
    }

    let path = parameters.path.clone();
    let opened_flag = Arc::new(AtomicBool::new(false));

    let opened = bounded(task, envelope.timeout_ms, {
        let opened_flag = Arc::clone(&opened_flag);

        async move {
            let file = options
                .open(&path)
                .await
                .map_err(|error| io_message("open", &path, &error))?;

            opened_flag.store(true, Ordering::Release);

            Ok::<tokio::fs::File, String>(file)
        }
    })
    .await;

    let created_by_us = creates_the_file(&parameters.mode);

    let file = match opened {
        Some(Ok(file)) => file,
        Some(Err(text)) => {
            task.add_result(Result::error(message, text)).await;

            return;
        }
        // Cancelled or out of time. The open may still have completed in the
        // blocking pool, leaving a file nothing will ever close — so the same
        // cleanup the single-shot writes do runs here too, under the same rule.
        //
        // `opened` is not optional. Without it this removes a file the call
        // never created: a Create open on a path that is already taken can only
        // fail, but if the deadline wins that race the branch runs anyway and
        // unlinks the file whose existence was the refusal.
        None => {
            drop_partial(&parameters.path, created_by_us, &opened_flag).await;

            return;
        }
    };

    let session = Arc::new(Session {
        file: tokio::sync::Mutex::new(Some(file)),
        path: parameters.path.clone(),
        created_by_us,
        written: AtomicU64::new(0),
        completed: AtomicBool::new(false),
        closing: AtomicBool::new(false),
        poisoned: AtomicBool::new(false),
    });

    if let Err(error) = registries().insert(parameters.id.clone(), session.clone()) {
        drop(session);

        drop_partial(&parameters.path, created_by_us, &opened_flag).await;

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
    // its own, and the id is the only name all three commands share. Prefixed,
    // so a caller-chosen name cannot land on a task key.
    if let Err(error) = states::get().register_with_flow(
        task.context().clone(),
        state_key(&parameters.id),
        state,
        || {},
    ) {
        registries().remove(&parameters.id);

        drop_partial(&parameters.path, created_by_us, &opened_flag).await;

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
        Vec::new(),
        calc_execution_ms(start_time),
    ))
    .await;
}

/// Writes one chunk. The answer waits for the write, which is what stops a fast
/// producer from running ahead of the disk.
pub async fn chunk(task: &Task, envelope: &mut payloads::Envelope) {
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

    let chunk = match bytes_of(parameters.chunk) {
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
pub async fn close(task: &Task, envelope: &mut payloads::Envelope) {
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

    // The session is taken away only when nothing more can be done with it: a
    // close that succeeded, or one that failed in a way a second attempt cannot
    // mend. A flush that ran out of time or hit a transient error leaves the
    // session and its file handle in place, which is the whole of what makes
    // the retry the PHP side offers real rather than a promise.
    //
    // A deadline (None) never deletes either: the flush may still be running in
    // the blocking pool, and running the cleanup under it is the one ordering
    // that could take a file out from beneath a write.
    let terminal = match &outcome {
        Some(Ok(_)) => true,
        Some(Err(failure)) => failure.terminal,
        None => false,
    };

    if terminal {
        states::get().delete_state(&state_key(&parameters.id)).await;
    }

    match outcome {
        Some(Ok(total)) => {
            task.add_result(Result::success(
                message,
                super::content::encode_count(total),
                calc_execution_ms(start_time),
            ))
            .await;
        }
        Some(Err(failure)) => {
            task.add_result(Result::error(message, failure.text)).await;
        }
        None => {}
    }
}
