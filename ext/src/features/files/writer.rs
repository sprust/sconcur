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

use super::content::bytes_of;
use super::errors::{io_message, message as fail, Kind};
use super::payloads;
use super::{bounded, creates_the_file, params, permission_bits, std_write_options};

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
#[derive(Debug)]
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
    /// Whether a chunk left the file in a state no total describes — cut off by
    /// a deadline or a stop, or failed part-way through with an error. Either
    /// way the file holds bytes nobody counted, so every later call fails rather
    /// than letting a retry double them or a close report a total the file does
    /// not have.
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
                    "the writer for {} was left inconsistent by a failed or interrupted chunk",
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
        // Both of these happen before the lock is asked for, and the order is
        // load-bearing.
        //
        // The poison is checked first because a writer cut off mid-chunk is the
        // one whose file must still go, so `closing` must not be raised for it.
        // And `closing` is raised before the await, because waiting for the lock
        // is an await: a close parked behind another coroutine's write used to
        // let the flow's cleanup run with the flag still down and delete a file
        // whose every chunk had been acknowledged.
        if self.poisoned.load(Ordering::Acquire) {
            return Err(Failure::terminal(fail(
                Kind::State,
                &format!(
                    "the writer for {} was left inconsistent by a failed or interrupted chunk",
                    self.path
                ),
            )));
        }

        self.closing.store(true, Ordering::Release);

        let mut guard = self.file.lock().await;

        let Some(file) = guard.as_mut() else {
            return Err(Failure::terminal(fail(
                Kind::State,
                &format!("the writer for {} is closed", self.path),
            )));
        };

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
    /// Ids claimed by an open that is still in flight. Separate from the
    /// sessions, because the claim has to exist before there is a session.
    opening: Mutex<std::collections::HashSet<String>>,
}

impl Registries {
    pub fn new() -> Self {
        Registries {
            sessions: Mutex::new(HashMap::new()),
            opening: Mutex::new(std::collections::HashSet::new()),
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

    /// Claims an id for an open that has not finished yet. False when it is
    /// already claimed or already open.
    fn reserve(&self, id: String) -> bool {
        let mut opening = self.opening.lock().unwrap();

        if self.sessions.lock().unwrap().contains_key(&id) || opening.contains(&id) {
            return false;
        }

        opening.insert(id);

        true
    }

    /// Gives a claim back, whether or not the open got as far as a session.
    fn release(&self, id: &str) {
        self.opening.lock().unwrap().remove(id);
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

    let Some(options) = std_write_options(&parameters.mode, permissions) else {
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

    // Reserved before the file is touched, and atomically — a check followed by
    // an open lets two opens with the same id both reach open(2), and in Replace
    // mode the loser truncates the winner's file before reporting the collision.
    if !registries().reserve(parameters.id.clone()) {
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

    let created_by_us = creates_the_file(&parameters.mode);

    // open_bounded owns the deadline path: a file the pool creates after the
    // caller has given up is removed by a detached task that waits for the open
    // to land. Doing it here is what cannot work — see its docblock.
    let opened = super::open_bounded(
        task,
        envelope.timeout_ms,
        options,
        parameters.path.clone(),
        created_by_us,
    )
    .await;

    let file = match opened {
        Some(Ok(file)) => file,
        Some(Err(text)) => {
            registries().release(&parameters.id);

            task.add_result(Result::error(message, text)).await;

            return;
        }
        None => {
            registries().release(&parameters.id);

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

        remove_if_created(&parameters.path, created_by_us).await;

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
        registries().release(&parameters.id);

        remove_if_created(&parameters.path, created_by_us).await;

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
    registries().release(&parameters.id);

    task.add_result(Result::success_with_next(
        message,
        Vec::new(),
        calc_execution_ms(start_time),
    ))
    .await;
}

/// Removes a file this call created and could not hand over. Unlike
/// drop_partial there is no "did the open succeed" question here: these paths
/// are reached only once it has.
async fn remove_if_created(path: &str, created_by_us: bool) {
    if !created_by_us {
        return;
    }

    let _ = tokio::fs::remove_file(path).await;
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

#[cfg(test)]
mod tests {
    use super::*;

    static FIXTURE_COUNTER: AtomicU64 = AtomicU64::new(0);

    async fn session(path: &str, created_by_us: bool) -> Arc<Session> {
        let file = tokio::fs::OpenOptions::new()
            .write(true)
            .create(true)
            .truncate(true)
            .open(path)
            .await
            .unwrap();

        Arc::new(Session {
            file: tokio::sync::Mutex::new(Some(file)),
            path: path.to_string(),
            created_by_us,
            written: AtomicU64::new(0),
            completed: AtomicBool::new(false),
            closing: AtomicBool::new(false),
            poisoned: AtomicBool::new(false),
        })
    }

    async fn fixture_path() -> String {
        let path = std::env::temp_dir().join(format!(
            "sconcur-writer-{}-{}",
            std::process::id(),
            FIXTURE_COUNTER.fetch_add(1, Ordering::Relaxed)
        ));

        path.to_string_lossy().to_string()
    }

    #[tokio::test]
    async fn a_finished_writer_keeps_its_file_and_counts_what_it_wrote() {
        let path = fixture_path().await;
        let session = session(&path, true).await;

        assert_eq!(session.write(b"one").await.unwrap(), 3);
        assert_eq!(session.write(b"two").await.unwrap(), 6);
        assert_eq!(session.finish().await.unwrap(), 6);

        // A close that succeeded must survive the cleanup the flow runs after it.
        session.abandon().await;

        assert_eq!(tokio::fs::read(&path).await.unwrap(), b"onetwo".to_vec());

        tokio::fs::remove_file(&path).await.unwrap();
    }

    /// The rule the whole feature keeps: only a writer that created the file may
    /// remove it. Undo `created_by_us` and a Replace writer's abandon deletes a
    /// file it only truncated.
    #[tokio::test]
    async fn only_a_writer_that_created_the_file_removes_it_when_abandoned() {
        let created = fixture_path().await;
        let taken = fixture_path().await;

        let mine = session(&created, true).await;
        let theirs = session(&taken, false).await;

        mine.write(b"half").await.unwrap();
        theirs.write(b"half").await.unwrap();

        mine.abandon().await;
        theirs.abandon().await;

        assert!(tokio::fs::metadata(&created).await.is_err());
        assert!(tokio::fs::metadata(&taken).await.is_ok());

        tokio::fs::remove_file(&taken).await.unwrap();
    }

    /// Undo the poison latch and this passes: a writer whose chunk was
    /// interrupted would go on accepting bytes and close reporting a total the
    /// file does not have.
    #[tokio::test]
    async fn an_interrupted_chunk_makes_every_later_call_fail() {
        let path = fixture_path().await;
        let session = session(&path, true).await;

        session.write(b"good").await.unwrap();

        // What a dropped write leaves behind, without needing to race one.
        session.poisoned.store(true, Ordering::Release);

        let write = session.write(b"more").await.unwrap_err();

        assert!(write.contains("files[state]"), "{write}");

        let close = session.finish().await.unwrap_err();

        assert!(close.terminal, "a poisoned writer cannot be closed later");
        assert!(close.text.contains("files[state]"), "{}", close.text);

        // And its file is still the one the abandon rule removes: the close must
        // not have raised `closing`.
        session.abandon().await;

        assert!(tokio::fs::metadata(&path).await.is_err());
    }

    /// A close that was started must keep the file even though it never
    /// completed — every chunk had been acknowledged, and only the flush was in
    /// doubt.
    #[tokio::test]
    async fn a_started_close_keeps_the_file_even_when_it_does_not_finish() {
        let path = fixture_path().await;
        let session = session(&path, true).await;

        session.write(b"contents").await.unwrap();

        // What a close that lost to its deadline leaves behind.
        session.closing.store(true, Ordering::Release);

        session.abandon().await;

        assert_eq!(tokio::fs::read(&path).await.unwrap(), b"contents".to_vec());

        tokio::fs::remove_file(&path).await.unwrap();
    }

    /// PHP reads terminality off the exception class, which is chosen by the
    /// kind — so every terminal failure has to carry Kind::State or the two
    /// sides silently disagree about whether a retry is worth trying.
    #[tokio::test]
    async fn every_terminal_close_failure_carries_the_state_kind() {
        let poisoned = fixture_path().await;
        let closed = fixture_path().await;

        let poisoned_session = session(&poisoned, true).await;

        poisoned_session.poisoned.store(true, Ordering::Release);

        let closed_session = session(&closed, true).await;

        closed_session.finish().await.unwrap();

        for failure in [
            poisoned_session.finish().await.unwrap_err(),
            closed_session.finish().await.unwrap_err(),
        ] {
            assert!(failure.terminal);
            assert!(failure.text.starts_with("files[state]:"), "{}", failure.text);
        }

        let _ = tokio::fs::remove_file(&poisoned).await;
        let _ = tokio::fs::remove_file(&closed).await;
    }

    #[test]
    fn a_writers_state_key_cannot_be_mistaken_for_a_task_key() {
        // Task keys are "<flow>:<n>"; a writer's id is a string the caller chose.
        assert_eq!(state_key("fw_abc"), "files-writer:fw_abc");
        assert_ne!(state_key("flow:1"), "flow:1");
    }

    #[test]
    fn an_id_is_claimed_once() {
        let registries = Registries::new();

        assert!(registries.reserve("one".to_string()));
        assert!(!registries.reserve("one".to_string()));

        registries.release("one");

        assert!(registries.reserve("one".to_string()));
    }
}
