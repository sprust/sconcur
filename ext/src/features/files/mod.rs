//! The files feature: PHP's blocking file functions, done on the runtime.
//!
//! Every syscall here goes through tokio::fs, which is not a style choice. The
//! core builds its runtime with one worker thread by default (core.rs), so a
//! synchronous read would stand in front of everything else the process is
//! doing — the HTTP server of the same worker included. tokio::fs hands each
//! call to the blocking pool instead, which is what leaves the runtime free.
//!
//! There must be no blocking filesystem call on a runtime thread here: either
//! tokio::fs, or the standard library inside a spawn_blocking, and nothing
//! else.

pub mod content;
pub mod dirs;
pub mod errors;
pub mod hash;
pub mod meta;
pub mod pattern;
pub mod payloads;
pub mod read_state;
pub mod streams;
pub mod walk_state;
pub mod writer;

use std::future::Future;
use std::time::{Duration, Instant};

use crate::dto::Result;
use crate::features::{BoxFuture, Feature};
use crate::tasks::Task;

use errors::{message as fail, Kind};

pub struct FilesFeature;

static INSTANCE: FilesFeature = FilesFeature;

pub fn get() -> &'static FilesFeature {
    &INSTANCE
}

impl Feature for FilesFeature {
    fn handle(&self, task: Task) -> BoxFuture {
        Box::pin(async move {
            let message = task.message();

            let mut envelope: payloads::Envelope = match rmp_serde::from_slice(&message.payload) {
                Ok(envelope) => envelope,
                Err(error) => {
                    task.add_result(Result::error(
                        message,
                        fail(Kind::Argument, &format!("parse envelope: {error}")),
                    ))
                    .await;

                    return;
                }
            };

            match envelope.command.as_str() {
                "rd" => content::read(&task, &mut envelope).await,
                "wr" => content::write(&task, &mut envelope).await,
                "wra" => content::write_atomic(&task, &mut envelope).await,
                "tr" => content::truncate(&task, &mut envelope).await,
                "cp" => content::copy(&task, &mut envelope).await,
                "mv" => content::move_file(&task, &mut envelope).await,
                "dl" => content::delete(&task, &mut envelope).await,
                "st" => meta::stat(&task, &mut envelope).await,
                "chm" => meta::chmod(&task, &mut envelope).await,
                "tch" => meta::touch(&task, &mut envelope).await,
                "rp" => meta::real_path(&task, &mut envelope).await,
                "tmp" => meta::temporary_file(&task, &mut envelope).await,
                "mkd" => dirs::make_directory(&task, &mut envelope).await,
                "rmd" => dirs::remove_directory(&task, &mut envelope).await,
                "ls" => dirs::list(&task, &mut envelope).await,
                "hsh" => hash::hash_file(&task, &mut envelope).await,
                "rdc" => streams::read_chunks(&task, &mut envelope).await,
                "rdl" => streams::read_lines(&task, &mut envelope).await,
                "wlk" => streams::walk(&task, &mut envelope).await,
                "wro" => writer::open(&task, &mut envelope).await,
                "wrc" => writer::chunk(&task, &mut envelope).await,
                "wrx" => writer::close(&task, &mut envelope).await,
                other => {
                    task.add_result(Result::error(
                        message,
                        fail(Kind::Argument, &format!("unknown command {other}")),
                    ))
                    .await
                }
            }
        })
    }
}

/// Clamps a size a caller chose: `0` or less means the default, and nothing is
/// ever taken at face value.
///
/// The cap is not defensive decoration. A buffer size arrives as an i64 from
/// PHP and is turned into a `Vec::with_capacity`; without a ceiling,
/// `bufferSizeBytes: PHP_INT_MAX` reaches the allocator, which aborts the
/// process rather than panicking — so `catch_unwind` around the task does not
/// save the worker. Every size a payload carries goes through here.
pub fn bounded_size(value: i64, default: usize, maximum: usize) -> usize {
    if value <= 0 {
        return default;
    }

    (value as usize).min(maximum)
}

/// The permission bits a payload carries, or the default when it carries none.
///
/// Refused rather than masked when they are outside the range: a value that
/// does not fit was meant as something else, and silently turning
/// `0x1_0000_0180` into `0600` is how a file ends up with rights nobody asked
/// for.
///
/// Every command whose `pm` is optional goes through here. chmod does not: its
/// permissions are a required argument, so `0` there is a caller asking for
/// 0000 rather than declining to choose, and it takes exact_permission_bits.
pub fn permission_bits(value: i64, default: u32) -> std::result::Result<u32, String> {
    if value == 0 {
        return Ok(default);
    }

    exact_permission_bits(value)
}

/// The same range check without the "0 means the default" reading, for the one
/// command whose permissions the caller must name: chmod.
pub fn exact_permission_bits(value: i64) -> std::result::Result<u32, String> {
    if !(0..=0o7777).contains(&value) {
        return Err(fail(
            Kind::Argument,
            &format!("permissions {value} are outside 0..0o7777"),
        ));
    }

    Ok(value as u32)
}

/// Decodes a sub-operation's body, reporting what could not be read rather than
/// leaving the caller with "parse error".
///
/// Takes the body out of the envelope instead of cloning it: for a write the
/// body IS the file's contents, and a clone means the bytes exist twice on this
/// side alone before `bytes_of` makes a third.
pub async fn params<T>(task: &Task, envelope: &mut payloads::Envelope, operation: &str) -> Option<T>
where
    T: serde::de::DeserializeOwned,
{
    let data = std::mem::replace(&mut envelope.data, rmpv::Value::Nil);

    match rmpv::ext::from_value(data) {
        Ok(parameters) => Some(parameters),
        Err(error) => {
            task.add_result(Result::error(
                task.message(),
                fail(
                    Kind::Argument,
                    &format!("parse {operation} params: {error}"),
                ),
            ))
            .await;

            None
        }
    }
}

/// Bounds one batch of a stream by the deadline its payload carried and by the
/// state's own cancellation token.
///
/// A state's next() is not routed through `bounded`: the registry
/// (`states::next`) calls it directly, and a task there has no `Task` to publish
/// an error on. So both bounds are applied here and the caller turns a None into
/// its own result.
///
/// The token is not optional, and `timeoutMs: 0` is why. A stream may legally
/// carry no deadline, and without a token a batch on a hung mount would be
/// unbounded AND uncancellable: the PHP coroutine would never get a result, and
/// the flow's cleanup hook would park for ever on the mutex the batch holds.
/// The state's close() cancels this first and only then reaches for that mutex —
/// the same order redis::scan_state keeps, and for the same reason.
pub async fn bounded_state<F, T>(
    timeout_ms: i64,
    cancel: &tokio_util::sync::CancellationToken,
    work: F,
) -> Option<T>
where
    F: std::future::Future<Output = T>,
{
    tokio::pin!(work);

    if timeout_ms <= 0 {
        return tokio::select! {
            // Biased towards the work: when a batch and a cancellation land in
            // the same poll, the batch that is already finished is the better
            // answer than throwing it away.
            biased;
            value = &mut work => Some(value),
            _ = cancel.cancelled() => None,
        };
    }

    let deadline = tokio::time::sleep(Duration::from_millis(timeout_ms as u64));

    tokio::pin!(deadline);

    tokio::select! {
        biased;
        value = &mut work => Some(value),
        _ = cancel.cancelled() => None,
        _ = &mut deadline => None,
    }
}

/// Opens a file under the deadline, and takes responsibility for a file this
/// call creates but never gets to hand over.
///
/// The obvious version — race `tokio::fs::OpenOptions::open` against the
/// deadline, and clean up in the losing branch — cannot work, and the way it
/// fails is worth spelling out because it looked right twice.
///
/// A blocking task is never cancelled: when the deadline wins, `open(2)` runs
/// to completion in the pool anyway and the file appears on disk afterwards.
/// Any flag the racing future sets is therefore still unset at the moment the
/// losing branch reads it — the flag is true exactly when the branch is not
/// taken — so the cleanup there is dead code, and the file is orphaned.
///
/// So the open is spawned rather than awaited inline, and on the deadline path
/// the handle is handed to a detached task that waits for the open to land and
/// cleans up after it. Nothing waits on the caller's side; the coroutine gets
/// its timeout immediately.
pub async fn open_bounded(
    task: &Task,
    timeout_ms: i64,
    options: std::fs::OpenOptions,
    path: String,
    created_by_us: bool,
) -> Option<std::result::Result<tokio::fs::File, String>> {
    let opening = path.clone();

    let mut handle = tokio::task::spawn_blocking(move || options.open(&opening));

    let outcome = bounded(task, timeout_ms, &mut handle).await;

    match outcome {
        Some(Ok(Ok(file))) => Some(Ok(tokio::fs::File::from_std(file))),
        Some(Ok(Err(error))) => Some(Err(errors::io_message("open", &path, &error))),
        // The pool dropped the job, which means the runtime is going away.
        Some(Err(error)) => Some(Err(fail(Kind::Io, &format!("open {path}: {error}")))),
        None => {
            tokio::spawn(async move {
                let Ok(Ok(file)) = handle.await else {
                    return;
                };

                drop(file);

                if created_by_us {
                    let _ = tokio::fs::remove_file(&path).await;
                }
            });

            None
        }
    }
}

/// One deadline for a whole command, rather than a fresh one per syscall.
///
/// A stream's open used to spend `timeoutMs` on the open, `timeoutMs` again on
/// the fstat and `timeoutMs` a third time on the first batch, so a call asking
/// for one second could legitimately take three.
pub struct Budget {
    start: Instant,
    timeout_ms: i64,
}

impl Budget {
    pub fn new(timeout_ms: i64) -> Self {
        Budget {
            start: Instant::now(),
            timeout_ms,
        }
    }

    /// What is left of it. `0` keeps meaning "no deadline"; a budget that is
    /// spent answers 1 ms rather than 0, because 0 would silently turn the rest
    /// of the command into an unbounded one.
    pub fn remaining_ms(&self) -> i64 {
        if self.timeout_ms <= 0 {
            return 0;
        }

        let spent = self.start.elapsed().as_millis() as i64;

        (self.timeout_ms - spent).max(1)
    }
}

/// Runs an operation under the flow's cancellation token and the payload's
/// deadline, which every feature is required to honour.
///
/// None means the operation did not finish and the failure has been published
/// already; the caller's only remaining job is cleanup, and it is done after
/// this returns, where the abandoned future is already dropped.
///
/// What a deadline can and cannot do here is worth being plain about: the work
/// stops being awaited, but a syscall already running in the blocking pool runs
/// to its end. Cancelling between two chunks of a copy is real; cancelling a
/// single read of a 2 GiB file is not.
pub async fn bounded<F, T>(task: &Task, timeout_ms: i64, work: F) -> Option<T>
where
    F: Future<Output = T>,
{
    let message = task.message();

    tokio::pin!(work);

    if timeout_ms <= 0 {
        return tokio::select! {
            // Biased towards the work. select! picks at random among branches
            // ready in the same poll, so an unbiased version reported a write
            // that had finished as a timeout — and then removed the file it had
            // just written.
            biased;
            value = &mut work => Some(value),
            _ = task.context().cancelled() => {
                task.add_result(Result::error(
                    message,
                    fail(Kind::Stopped, "closed by task stop"),
                ))
                .await;

                None
            }
        };
    }

    let deadline = tokio::time::sleep(Duration::from_millis(timeout_ms as u64));

    tokio::pin!(deadline);

    tokio::select! {
        biased;
        value = &mut work => Some(value),
        _ = task.context().cancelled() => {
            task.add_result(Result::error(
                message,
                fail(Kind::Stopped, "closed by task stop"),
            ))
            .await;

            None
        }
        _ = &mut deadline => {
            task.add_result(Result::error(
                message,
                fail(Kind::Timeout, &format!("deadline of {timeout_ms} ms exceeded")),
            ))
            .await;

            None
        }
    }
}

/// Maps a PHP FileWriteMode to open options — the single place those flags
/// live, as HttpClient's sink_options is for its own download modes. The wire
/// values are the same three, and deliberately so; the two enums stay separate
/// because neither feature should depend on the other's.
///
/// The permission bits apply only when the file is created, which is what
/// open(2) does with them.
pub fn std_write_options(mode: &str, permissions: u32) -> Option<std::fs::OpenOptions> {
    use std::os::unix::fs::OpenOptionsExt;

    let mut options = std::fs::OpenOptions::new();

    options.write(true);

    match mode {
        "rpl" => {
            options.create(true).truncate(true);
        }
        "crt" => {
            options.create_new(true);
        }
        "app" => {
            options.create(true).append(true);
        }
        _ => return None,
    }

    options.mode(permissions);

    Some(options)
}

/// Whether a write in this mode is the thing that brought the file into
/// existence — and therefore the only case in which cleaning up after a failure
/// may remove it.
///
/// Only `crt` qualifies, because only `create_new` fails when the path is
/// already taken. A failed `rpl` over an existing file has truncated it, which
/// is what the caller asked for; removing it on top of that destroys the inode,
/// its permissions and its ownership — data the call never had the right to
/// take. Worse with a symlink: the open follows it and the remove does not, so
/// the link would go and its target would be left empty.
pub fn creates_the_file(mode: &str) -> bool {
    mode == "crt"
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn an_unknown_write_mode_is_refused() {
        assert!(std_write_options("rpl", 0o644).is_some());
        assert!(std_write_options("crt", 0o644).is_some());
        assert!(std_write_options("app", 0o644).is_some());
        assert!(std_write_options("", 0o644).is_none());
        assert!(std_write_options("w", 0o644).is_none());
    }

    #[test]
    fn a_budget_is_one_deadline_for_a_whole_command() {
        assert_eq!(Budget::new(0).remaining_ms(), 0);

        let budget = Budget::new(1_000);

        assert!(budget.remaining_ms() <= 1_000);
        assert!(budget.remaining_ms() >= 1);
    }

    /// Undo this and a failed Replace over an existing file deletes it.
    #[test]
    fn only_a_create_is_treated_as_having_made_the_file() {
        assert!(creates_the_file("crt"));
        assert!(!creates_the_file("rpl"));
        assert!(!creates_the_file("app"));
    }

    #[test]
    fn a_size_is_clamped_rather_than_trusted() {
        assert_eq!(bounded_size(0, 64, 1024), 64);
        assert_eq!(bounded_size(-1, 64, 1024), 64);
        assert_eq!(bounded_size(128, 64, 1024), 128);
        // The one that matters: PHP_INT_MAX would otherwise reach the allocator
        // as a Vec::with_capacity and abort the process.
        assert_eq!(bounded_size(i64::MAX, 64, 1024), 1024);
    }

    #[test]
    fn permissions_outside_the_range_are_refused_not_masked() {
        assert_eq!(permission_bits(0, 0o644).unwrap(), 0o644);
        assert_eq!(permission_bits(0o600, 0o644).unwrap(), 0o600);
        assert!(permission_bits(-1, 0o644).is_err());
        assert!(permission_bits(0o10000, 0o644).is_err());
        // Truncating this cast used to leave 0600.
        assert!(permission_bits(0x1_0000_0180, 0o644).is_err());
    }
}
