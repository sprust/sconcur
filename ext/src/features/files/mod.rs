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
use std::time::Duration;

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

            let envelope: payloads::Envelope = match rmp_serde::from_slice(&message.payload) {
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
                "rd" => content::read(&task, &envelope).await,
                "wr" => content::write(&task, &envelope).await,
                "wra" => content::write_atomic(&task, &envelope).await,
                "tr" => content::truncate(&task, &envelope).await,
                "cp" => content::copy(&task, &envelope).await,
                "mv" => content::move_file(&task, &envelope).await,
                "dl" => content::delete(&task, &envelope).await,
                "st" => meta::stat(&task, &envelope).await,
                "chm" => meta::chmod(&task, &envelope).await,
                "tch" => meta::touch(&task, &envelope).await,
                "rp" => meta::real_path(&task, &envelope).await,
                "tmp" => meta::temporary_file(&task, &envelope).await,
                "mkd" => dirs::make_directory(&task, &envelope).await,
                "rmd" => dirs::remove_directory(&task, &envelope).await,
                "ls" => dirs::list(&task, &envelope).await,
                "hsh" => hash::hash_file(&task, &envelope).await,
                "rdc" => streams::read_chunks(&task, &envelope).await,
                "rdl" => streams::read_lines(&task, &envelope).await,
                "lst" => streams::walk(&task, &envelope).await,
                "wro" => writer::open(&task, &envelope).await,
                "wrc" => writer::chunk(&task, &envelope).await,
                "wrx" => writer::close(&task, &envelope).await,
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

/// Decodes a sub-operation's body, reporting what could not be read rather than
/// leaving the caller with "parse error".
pub async fn params<T>(task: &Task, envelope: &payloads::Envelope, operation: &str) -> Option<T>
where
    T: serde::de::DeserializeOwned,
{
    match rmpv::ext::from_value(envelope.data.clone()) {
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
            _ = task.context().cancelled() => {
                task.add_result(Result::error(
                    message,
                    fail(Kind::Stopped, "closed by task stop"),
                ))
                .await;

                None
            }
            value = &mut work => Some(value),
        };
    }

    let deadline = tokio::time::sleep(Duration::from_millis(timeout_ms as u64));

    tokio::pin!(deadline);

    tokio::select! {
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
        value = &mut work => Some(value),
    }
}

/// Maps a PHP FileWriteMode to open options — the single place those flags
/// live, as HttpClient's sink_options is for its own download modes. The wire
/// values are the same three, and deliberately so; the two enums stay separate
/// because neither feature should depend on the other's.
///
/// The permission bits apply only when the file is created, which is what
/// open(2) does with them.
pub fn write_options(mode: &str, permissions: i64) -> Option<tokio::fs::OpenOptions> {
    let mut options = tokio::fs::OpenOptions::new();

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

    options.mode(if permissions > 0 {
        permissions as u32
    } else {
        0o644
    });

    Some(options)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn an_unknown_write_mode_is_refused() {
        assert!(write_options("rpl", 0).is_some());
        assert!(write_options("crt", 0).is_some());
        assert!(write_options("app", 0).is_some());
        assert!(write_options("", 0).is_none());
        assert!(write_options("w", 0).is_none());
    }
}
