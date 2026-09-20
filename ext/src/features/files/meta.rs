//! What a path is, and the small operations that change it: stat, chmod, touch,
//! realPath and the temporary file.
//!
//! stat is the one worth naming: PHP answers the same questions with
//! file_exists, is_dir, is_file, filesize and filemtime, each its own syscall
//! and each its own pause of the PHP thread. Here they are one crossing and one
//! stat(2).

use std::os::unix::fs::{MetadataExt, PermissionsExt};
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{Instant, SystemTime, UNIX_EPOCH};

use crate::dto::Result;
use crate::helpers::calc_execution_ms;
use crate::tasks::Task;

use super::errors::{io_message, message as fail, Kind};
use super::payloads;
use super::{bounded, params};

/// How many names a temporary file tries before giving up. A collision needs
/// two callers to draw the same counter value in the same millisecond, so the
/// second attempt is already unlikely; ten is there for the pathological case,
/// not the expected one.
const TEMPORARY_NAME_ATTEMPTS: usize = 10;

static TEMPORARY_COUNTER: AtomicU64 = AtomicU64::new(0);

/// Milliseconds since the epoch, or 0 for a time the filesystem does not carry.
/// Signed, because a file can be stamped before 1970 and a wrapped negative
/// would read as the far future.
fn epoch_ms(time: std::io::Result<SystemTime>) -> i64 {
    let Ok(time) = time else {
        return 0;
    };

    match time.duration_since(UNIX_EPOCH) {
        Ok(duration) => duration.as_millis() as i64,
        Err(error) => -(error.duration().as_millis() as i64),
    }
}

/// Everything one stat(2) knows, as the map PHP builds its FileStat from.
fn encode_stat(metadata: Option<&std::fs::Metadata>) -> Vec<u8> {
    let mut buffer = Vec::new();

    let Some(metadata) = metadata else {
        let _ = rmp::encode::write_map_len(&mut buffer, 1);
        let _ = rmp::encode::write_str(&mut buffer, "ex");
        let _ = rmp::encode::write_bool(&mut buffer, false);

        return buffer;
    };

    let _ = rmp::encode::write_map_len(&mut buffer, 11);

    let _ = rmp::encode::write_str(&mut buffer, "ex");
    let _ = rmp::encode::write_bool(&mut buffer, true);

    let _ = rmp::encode::write_str(&mut buffer, "isf");
    let _ = rmp::encode::write_bool(&mut buffer, metadata.is_file());

    let _ = rmp::encode::write_str(&mut buffer, "isd");
    let _ = rmp::encode::write_bool(&mut buffer, metadata.is_dir());

    let _ = rmp::encode::write_str(&mut buffer, "isl");
    let _ = rmp::encode::write_bool(&mut buffer, metadata.is_symlink());

    let _ = rmp::encode::write_str(&mut buffer, "sz");
    let _ = rmp::encode::write_uint(&mut buffer, metadata.len());

    let _ = rmp::encode::write_str(&mut buffer, "mt");
    let _ = rmp::encode::write_sint(&mut buffer, epoch_ms(metadata.modified()));

    let _ = rmp::encode::write_str(&mut buffer, "at");
    let _ = rmp::encode::write_sint(&mut buffer, epoch_ms(metadata.accessed()));

    let _ = rmp::encode::write_str(&mut buffer, "ct");
    let _ = rmp::encode::write_sint(&mut buffer, epoch_ms(metadata.created()));

    // The permission bits alone: the type bits above them are already answered
    // by isf/isd/isl, and a caller comparing against 0644 should not have to
    // mask them off.
    let _ = rmp::encode::write_str(&mut buffer, "pm");
    let _ = rmp::encode::write_uint(&mut buffer, (metadata.permissions().mode() & 0o7777) as u64);

    let _ = rmp::encode::write_str(&mut buffer, "ui");
    let _ = rmp::encode::write_uint(&mut buffer, metadata.uid() as u64);

    let _ = rmp::encode::write_str(&mut buffer, "gi");
    let _ = rmp::encode::write_uint(&mut buffer, metadata.gid() as u64);

    buffer
}

/// A single-value answer, as a map so PHP reads every structured result the
/// same way.
pub fn encode_text(key: &str, value: &str) -> Vec<u8> {
    let mut buffer = Vec::new();

    let _ = rmp::encode::write_map_len(&mut buffer, 1);
    let _ = rmp::encode::write_str(&mut buffer, key);
    let _ = rmp::encode::write_str(&mut buffer, value);

    buffer
}

/// Describes a path. A path that is not there is not a failure — it answers
/// exists: false, which is what makes one command serve both stat() and
/// exists().
pub async fn stat(task: &Task, envelope: &mut payloads::Envelope) {
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::StatParams>(task, envelope, "stat").await else {
        return;
    };

    let path = parameters.path.clone();
    let follow = parameters.follow_symlinks;

    let work = async move {
        let metadata = if follow {
            tokio::fs::metadata(&path).await
        } else {
            tokio::fs::symlink_metadata(&path).await
        };

        match metadata {
            Ok(metadata) => Ok::<Vec<u8>, String>(encode_stat(Some(&metadata))),
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => {
                Ok(encode_stat(None))
            }
            Err(error) => Err(io_message("stat", &path, &error)),
        }
    };

    publish(task, envelope, start_time, work).await;
}

/// Changes a path's permission bits.
pub async fn chmod(task: &Task, envelope: &mut payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::ChmodParams>(task, envelope, "chmod").await else {
        return;
    };

    // Exact, not defaulted: chmod's permissions are a required argument on the
    // PHP side, so 0 there is a caller asking for 0000 rather than declining to
    // choose. Routing it through the "0 means the default" helper made chmod 000
    // silently mean chmod 644.
    let permissions = match super::exact_permission_bits(parameters.permissions) {
        Ok(permissions) => permissions,
        Err(text) => {
            task.add_result(Result::error(message, text)).await;

            return;
        }
    };

    let path = parameters.path.clone();

    let work = async move {
        tokio::fs::set_permissions(&path, std::fs::Permissions::from_mode(permissions))
            .await
            .map_err(|error| io_message("chmod", &path, &error))?;

        // Nothing to answer with: the caller knows the path it named, and a map
        // holding it back is a field no reader ever looks at.
        Ok::<Vec<u8>, String>(Vec::new())
    };

    publish(task, envelope, start_time, work).await;
}

/// Creates a file, or moves the modification time of one that is already there
/// — the two halves of touch(1), in the order it does them.
pub async fn touch(task: &Task, envelope: &mut payloads::Envelope) {
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::TouchParams>(task, envelope, "touch").await else {
        return;
    };

    let path = parameters.path.clone();
    let modified_at_ms = parameters.modified_at_ms;

    let permissions = match super::permission_bits(parameters.permissions, 0o644) {
        Ok(permissions) => permissions,
        Err(text) => {
            task.add_result(Result::error(task.message(), text)).await;

            return;
        }
    };

    let work = async move {
        let mut options = tokio::fs::OpenOptions::new();

        options.write(true).create(true).truncate(false);
        options.mode(permissions);

        let file = options
            .open(&path)
            .await
            .map_err(|error| io_message("open", &path, &error))?;

        // Negative stamps are kept rather than refused: stat goes out of its way
        // to report a pre-1970 mtime as a negative number, so touch has to be
        // able to take one back or the two disagree about the same field. Only
        // exactly 0 means "now" — which costs the epoch second itself, and that
        // is the trade the parameter's documentation names.
        let time = match modified_at_ms {
            0 => SystemTime::now(),
            positive if positive > 0 => {
                SystemTime::UNIX_EPOCH + std::time::Duration::from_millis(positive as u64)
            }
            negative => {
                SystemTime::UNIX_EPOCH - std::time::Duration::from_millis(negative.unsigned_abs())
            }
        };

        // Only the modification time is set. The access time is the filesystem's
        // own bookkeeping, and a mount with relatime would ignore it anyway.
        let times = std::fs::FileTimes::new().set_modified(time);

        // tokio's File has no set_times of its own, so the handle goes back to
        // the standard library for this one call — and onto the blocking pool
        // with it, which is the rule the whole module keeps.
        let standard = file.into_std().await;

        let outcome = tokio::task::spawn_blocking(move || standard.set_times(times))
            .await
            .map_err(|error| {
                fail(Kind::Io, &format!("set times on {path}: {error}"))
            })?;

        outcome.map_err(|error| io_message("set times on", &path, &error))?;

        Ok::<Vec<u8>, String>(Vec::new())
    };

    publish(task, envelope, start_time, work).await;
}

/// Canonicalizes a path: symlinks resolved, `.` and `..` removed. The path must
/// exist, as it must for PHP's realpath().
pub async fn real_path(task: &Task, envelope: &mut payloads::Envelope) {
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::RealPathParams>(task, envelope, "realPath").await
    else {
        return;
    };

    let path = parameters.path.clone();

    let work = async move {
        let resolved = tokio::fs::canonicalize(&path)
            .await
            .map_err(|error| io_message("resolve", &path, &error))?;

        Ok::<Vec<u8>, String>(encode_text("p", &resolved.to_string_lossy()))
    };

    publish(task, envelope, start_time, work).await;
}

/// Creates a file nobody else holds and answers with its path.
///
/// The name is drawn and then created with create_new, rather than checked for
/// and then created: the check-then-create version has a window in which another
/// process takes the name, and a temporary file whose whole purpose is to be
/// exclusively yours cannot have one.
pub async fn temporary_file(task: &Task, envelope: &mut payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) =
        params::<payloads::TemporaryFileParams>(task, envelope, "temporaryFile").await
    else {
        return;
    };

    if parameters.prefix.contains('/') || parameters.suffix.contains('/') {
        task.add_result(Result::error(
            message,
            fail(
                Kind::Argument,
                "the prefix and the suffix of a temporary file must not contain a separator",
            ),
        ))
        .await;

        return;
    }

    let directory = if parameters.directory.is_empty() {
        std::env::temp_dir().to_string_lossy().to_string()
    } else {
        parameters.directory.clone()
    };

    let prefix = parameters.prefix.clone();
    let suffix = parameters.suffix.clone();
    let permissions = match super::permission_bits(parameters.permissions, 0o600) {
        Ok(permissions) => permissions,
        Err(text) => {
            task.add_result(Result::error(task.message(), text)).await;

            return;
        }
    };

    // Spawned rather than awaited inline, for the reason open_bounded spells
    // out: a blocking task is never cancelled, so a file created after the
    // deadline has to be cleaned up by something that can still wait for it.
    let mut handle = tokio::task::spawn_blocking(move || {
        let mut last_error = None;

        for _ in 0..TEMPORARY_NAME_ATTEMPTS {
            let path = format!(
                "{}/{}{}-{}{}",
                directory.trim_end_matches('/'),
                prefix,
                std::process::id(),
                TEMPORARY_COUNTER.fetch_add(1, Ordering::Relaxed),
                suffix,
            );

            let mut options = std::fs::OpenOptions::new();

            options.write(true).create_new(true);

            std::os::unix::fs::OpenOptionsExt::mode(&mut options, permissions);

            match options.open(&path) {
                Ok(file) => {
                    // Set explicitly as well: the mode an open carries is
                    // narrowed by the process umask, and a temporary file asked
                    // for at 0600 must be 0600.
                    let _ =
                        std::fs::set_permissions(&path, std::fs::Permissions::from_mode(permissions));

                    drop(file);

                    return Ok(path);
                }
                Err(error) if error.kind() == std::io::ErrorKind::AlreadyExists => {
                    last_error = Some(error);
                }
                Err(error) => return Err(io_message("create", &path, &error)),
            }
        }

        Err(fail(
            Kind::Io,
            &format!(
                "create a temporary file in {directory}: {} names were taken ({})",
                TEMPORARY_NAME_ATTEMPTS,
                last_error
                    .map(|error| error.to_string())
                    .unwrap_or_else(|| "no reason recorded".to_string()),
            ),
        ))
    });

    let outcome = bounded(task, envelope.timeout_ms, &mut handle).await;

    match outcome {
        Some(Ok(Ok(path))) => {
            task.add_result(Result::success(
                task.message(),
                encode_text("p", &path),
                calc_execution_ms(start_time),
            ))
            .await;
        }
        Some(Ok(Err(text))) => {
            task.add_result(Result::error(task.message(), text)).await;
        }
        Some(Err(error)) => {
            task.add_result(Result::error(
                task.message(),
                fail(Kind::Io, &format!("create a temporary file: {error}")),
            ))
            .await;
        }
        // Nobody will ever learn the name, so nobody else can remove it.
        None => {
            tokio::spawn(async move {
                if let Ok(Ok(path)) = handle.await {
                    let _ = tokio::fs::remove_file(&path).await;
                }
            });
        }
    }
}

/// Runs the work under the deadline and answers with whatever it built. The
/// shape every operation in this module shares, written once.
pub async fn publish<F>(task: &Task, envelope: &payloads::Envelope, start_time: Instant, work: F)
where
    F: std::future::Future<Output = std::result::Result<Vec<u8>, String>>,
{
    let message = task.message();

    match bounded(task, envelope.timeout_ms, work).await {
        Some(Ok(body)) => {
            task.add_result(Result::success(
                message,
                body,
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_missing_path_encodes_as_not_existing() {
        let encoded = encode_stat(None);
        let decoded: rmpv::Value = rmp_serde::from_slice(&encoded).unwrap();

        let map = decoded.as_map().unwrap();

        assert_eq!(map.len(), 1);
        assert_eq!(map[0].0.as_str().unwrap(), "ex");
        assert_eq!(map[0].1.as_bool().unwrap(), false);
    }

    #[test]
    fn a_time_before_the_epoch_stays_negative() {
        let before = UNIX_EPOCH - std::time::Duration::from_millis(1500);

        assert_eq!(epoch_ms(Ok(before)), -1500);
    }

    #[test]
    fn a_time_the_filesystem_does_not_carry_is_zero() {
        assert_eq!(
            epoch_ms(Err(std::io::Error::from(std::io::ErrorKind::Unsupported))),
            0
        );
    }
}
