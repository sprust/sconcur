//! The single-shot operations on a file's contents: read, write, truncate,
//! copy, move, delete.
//!
//! "Single-shot" means one payload in and one result out. Reading a file bigger
//! than the process wants to hold is what the streaming states are for; here
//! max_read_bytes refuses it instead.

use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::Arc;
use std::time::Instant;

use tokio::io::{AsyncReadExt, AsyncSeekExt, AsyncWriteExt};

use crate::dto::Result;
use crate::helpers::calc_execution_ms;
use crate::tasks::Task;

use super::errors::{io_message, message as fail, Kind};
use super::{bounded, params, write_options};
use super::payloads;

/// The copy granularity when the caller names none (64 KiB, as HttpClient's
/// download uses).
const DEFAULT_COPY_BUFFER_BYTES: usize = 65_536;

/// Distinguishes the temporary files of two atomic writes racing on the same
/// path from the same process.
static ATOMIC_WRITE_COUNTER: AtomicU64 = AtomicU64::new(0);

/// The bytes of a value PHP packed as a string.
pub fn bytes_of(value: &rmpv::Value) -> std::result::Result<Vec<u8>, String> {
    match value {
        rmpv::Value::Binary(bytes) => Ok(bytes.clone()),
        rmpv::Value::String(text) => Ok(text.as_bytes().to_vec()),
        other => Err(format!("contents must be a string, got {other}")),
    }
}

/// Answers with the number of bytes the operation moved, as a map so PHP reads
/// it the way it reads every other structured result.
pub fn encode_count(count: u64) -> Vec<u8> {
    let mut buffer = Vec::new();

    let _ = rmp::encode::write_map_len(&mut buffer, 1);
    let _ = rmp::encode::write_str(&mut buffer, "n");
    let _ = rmp::encode::write_uint(&mut buffer, count);

    buffer
}

/// Reads a file whole, or the byte range the payload names.
///
/// The bytes go back raw, not wrapped in MessagePack: a 100 MiB file would
/// otherwise be copied once more just to be unwrapped on the other side, and
/// the result frame already carries its own length.
pub async fn read(task: &Task, envelope: &payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::ReadParams>(task, envelope, "read").await else {
        return;
    };

    if parameters.offset_bytes < 0 || parameters.length_bytes < 0 {
        task.add_result(Result::error(
            message,
            fail(Kind::Argument, "offsetBytes and lengthBytes must not be negative"),
        ))
        .await;

        return;
    }

    let outcome = bounded(task, envelope.timeout_ms, read_file(&parameters)).await;

    match outcome {
        Some(Ok(contents)) => {
            task.add_result(Result::success(
                message,
                contents,
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

async fn read_file(parameters: &payloads::ReadParams) -> std::result::Result<Vec<u8>, String> {
    let path = parameters.path.as_str();

    let mut file = tokio::fs::File::open(path)
        .await
        .map_err(|error| io_message("open", path, &error))?;

    let metadata = file
        .metadata()
        .await
        .map_err(|error| io_message("stat", path, &error))?;

    if metadata.is_dir() {
        return Err(fail(
            Kind::FileType,
            &format!("read {path}: is a directory"),
        ));
    }

    // What the range asks for, as far as the size is known. It is not always
    // known: a file under /proc reports zero and still reads, which is why a
    // zero size does not short-circuit into an empty answer.
    let size = metadata.len();
    let offset = parameters.offset_bytes as u64;

    let remaining = size.saturating_sub(offset);

    let wanted = if parameters.length_bytes > 0 {
        let length = parameters.length_bytes as u64;

        if size > 0 { length.min(remaining) } else { length }
    } else {
        remaining
    };

    if parameters.max_read_bytes > 0 && wanted > parameters.max_read_bytes as u64 {
        return Err(fail(
            Kind::TooLarge,
            &format!(
                "read {path}: {wanted} bytes exceed the limit of {} bytes",
                parameters.max_read_bytes
            ),
        ));
    }

    if offset > 0 {
        file.seek(std::io::SeekFrom::Start(offset))
            .await
            .map_err(|error| io_message("seek", path, &error))?;
    }

    // with_capacity on the known size, so a large file is one allocation rather
    // than a doubling ladder. An unknown size (0) starts empty and grows.
    let mut contents = Vec::with_capacity(wanted.min(u32::MAX as u64) as usize);

    if parameters.length_bytes > 0 {
        let mut limited = file.take(parameters.length_bytes as u64);

        limited
            .read_to_end(&mut contents)
            .await
            .map_err(|error| io_message("read", path, &error))?;
    } else {
        file.read_to_end(&mut contents)
            .await
            .map_err(|error| io_message("read", path, &error))?;

        // A file whose size the metadata did not know (/proc and friends) is
        // checked once it is in hand — the limit is about the memory, and the
        // memory is spent by now, but the next read of the same path is refused
        // rather than the caller being told nothing.
        if parameters.max_read_bytes > 0 && contents.len() as i64 > parameters.max_read_bytes {
            return Err(fail(
                Kind::TooLarge,
                &format!(
                    "read {path}: {} bytes exceed the limit of {} bytes",
                    contents.len(),
                    parameters.max_read_bytes
                ),
            ));
        }
    }

    Ok(contents)
}

/// Writes, appends or creates a file in one shot.
pub async fn write(task: &Task, envelope: &payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::WriteParams>(task, envelope, "write").await else {
        return;
    };

    let contents = match bytes_of(&parameters.contents) {
        Ok(contents) => contents,
        Err(error) => {
            task.add_result(Result::error(message, fail(Kind::Argument, &error)))
                .await;

            return;
        }
    };

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
    let append = parameters.mode == "app";
    let opened = Arc::new(AtomicBool::new(false));

    let work = {
        let opened = Arc::clone(&opened);

        async move {
            let mut file = options
                .open(&path)
                .await
                .map_err(|error| io_message("open", &path, &error))?;

            opened.store(true, Ordering::Relaxed);

            file.write_all(&contents)
                .await
                .map_err(|error| io_message("write", &path, &error))?;

            file.flush()
                .await
                .map_err(|error| io_message("flush", &path, &error))?;

            Ok::<u64, String>(contents.len() as u64)
        }
    };

    match bounded(task, envelope.timeout_ms, work).await {
        Some(Ok(count)) => {
            task.add_result(Result::success(
                message,
                encode_count(count),
                calc_execution_ms(start_time),
            ))
            .await;
        }
        Some(Err(text)) => {
            drop_partial(&parameters.path, append, &opened).await;

            task.add_result(Result::error(message, text)).await;
        }
        None => drop_partial(&parameters.path, append, &opened).await,
    }
}

/// Writes through a temporary file in the same directory and a rename, so a
/// concurrent reader sees either the old contents or the new ones and never a
/// half-write.
///
/// The temporary file is a sibling on purpose: rename is atomic only within one
/// filesystem, and a path under /tmp would not be one.
pub async fn write_atomic(task: &Task, envelope: &payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) =
        params::<payloads::WriteAtomicParams>(task, envelope, "writeAtomic").await
    else {
        return;
    };

    let contents = match bytes_of(&parameters.contents) {
        Ok(contents) => contents,
        Err(error) => {
            task.add_result(Result::error(message, fail(Kind::Argument, &error)))
                .await;

            return;
        }
    };

    let path = parameters.path.clone();
    let temporary_path = temporary_sibling(&path);
    let permissions = parameters.permissions;

    let work = {
        let temporary_path = temporary_path.clone();

        async move {
            let mut options = tokio::fs::OpenOptions::new();

            options.write(true).create_new(true);
            options.mode(if permissions > 0 {
                permissions as u32
            } else {
                0o644
            });

            let mut file = options
                .open(&temporary_path)
                .await
                .map_err(|error| io_message("open", &temporary_path, &error))?;

            file.write_all(&contents)
                .await
                .map_err(|error| io_message("write", &temporary_path, &error))?;

            // The rename is atomic, but it does not make the bytes durable. A
            // crash between the two leaves the new name pointing at a file whose
            // contents never reached the disk, which is the failure this whole
            // operation exists to prevent.
            file.sync_all()
                .await
                .map_err(|error| io_message("sync", &temporary_path, &error))?;

            drop(file);

            tokio::fs::rename(&temporary_path, &path)
                .await
                .map_err(|error| io_message("rename", &path, &error))?;

            Ok::<u64, String>(contents.len() as u64)
        }
    };

    match bounded(task, envelope.timeout_ms, work).await {
        Some(Ok(count)) => {
            task.add_result(Result::success(
                message,
                encode_count(count),
                calc_execution_ms(start_time),
            ))
            .await;
        }
        Some(Err(text)) => {
            let _ = tokio::fs::remove_file(&temporary_path).await;

            task.add_result(Result::error(message, text)).await;
        }
        None => {
            let _ = tokio::fs::remove_file(&temporary_path).await;
        }
    }
}

/// Cuts a file to the given length, growing it with zeroes when the length is
/// past its end — the same behaviour as ftruncate().
pub async fn truncate(task: &Task, envelope: &payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::TruncateParams>(task, envelope, "truncate").await
    else {
        return;
    };

    if parameters.size_bytes < 0 {
        task.add_result(Result::error(
            message,
            fail(Kind::Argument, "sizeBytes must not be negative"),
        ))
        .await;

        return;
    }

    let path = parameters.path.clone();
    let size = parameters.size_bytes as u64;

    let work = async move {
        let file = tokio::fs::OpenOptions::new()
            .write(true)
            .open(&path)
            .await
            .map_err(|error| io_message("open", &path, &error))?;

        file.set_len(size)
            .await
            .map_err(|error| io_message("truncate", &path, &error))?;

        Ok::<u64, String>(size)
    };

    match bounded(task, envelope.timeout_ms, work).await {
        Some(Ok(count)) => {
            task.add_result(Result::success(
                message,
                encode_count(count),
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

/// Copies a file. The bytes are streamed inside the extension and never cross
/// into PHP, which is what makes this the clearest win the feature has.
pub async fn copy(task: &Task, envelope: &payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::CopyParams>(task, envelope, "copy").await else {
        return;
    };

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

    let source = parameters.source.clone();
    let destination = parameters.destination.clone();
    let append = parameters.mode == "app";
    let opened = Arc::new(AtomicBool::new(false));

    let buffer_size = if parameters.buffer_size_bytes > 0 {
        parameters.buffer_size_bytes as usize
    } else {
        DEFAULT_COPY_BUFFER_BYTES
    };

    let work = {
        let opened = Arc::clone(&opened);

        async move {
            let source_file = tokio::fs::File::open(&source)
                .await
                .map_err(|error| io_message("open", &source, &error))?;

            let mut reader = tokio::io::BufReader::with_capacity(buffer_size, source_file);

            let destination_file = options
                .open(&destination)
                .await
                .map_err(|error| io_message("open", &destination, &error))?;

            opened.store(true, Ordering::Relaxed);

            let mut writer = tokio::io::BufWriter::with_capacity(buffer_size, destination_file);

            let count = tokio::io::copy(&mut reader, &mut writer)
                .await
                .map_err(|error| io_message("copy to", &destination, &error))?;

            writer
                .flush()
                .await
                .map_err(|error| io_message("flush", &destination, &error))?;

            Ok::<u64, String>(count)
        }
    };

    match bounded(task, envelope.timeout_ms, work).await {
        Some(Ok(count)) => {
            task.add_result(Result::success(
                message,
                encode_count(count),
                calc_execution_ms(start_time),
            ))
            .await;
        }
        Some(Err(text)) => {
            drop_partial(&parameters.destination, append, &opened).await;

            task.add_result(Result::error(message, text)).await;
        }
        // Cancelled or out of time: the future is dropped by now, so the
        // half-written destination is cleaned up here rather than inside it.
        None => drop_partial(&parameters.destination, append, &opened).await,
    }
}

/// Renames a file, falling back to a copy and a delete when the two paths are
/// on different filesystems — where rename(2) answers EXDEV and PHP's own
/// rename() does the same fallback.
pub async fn move_file(task: &Task, envelope: &payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::MoveParams>(task, envelope, "move").await else {
        return;
    };

    let source = parameters.source.clone();
    let destination = parameters.destination.clone();

    let work = async move {
        match tokio::fs::rename(&source, &destination).await {
            Ok(()) => Ok::<u64, String>(0),
            // Matched on the raw code rather than ErrorKind::CrossesDevices:
            // the mapping of that kind is the standard library's business and
            // this is the one case the fallback exists for.
            Err(error) if error.raw_os_error() == Some(libc::EXDEV) => {
                let count = tokio::fs::copy(&source, &destination)
                    .await
                    .map_err(|error| io_message("copy to", &destination, &error))?;

                tokio::fs::remove_file(&source)
                    .await
                    .map_err(|error| io_message("remove", &source, &error))?;

                Ok(count)
            }
            Err(error) => Err(io_message("rename", &source, &error)),
        }
    };

    match bounded(task, envelope.timeout_ms, work).await {
        Some(Ok(count)) => {
            task.add_result(Result::success(
                message,
                encode_count(count),
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

/// Removes a file. With missing_ok a path that is not there is a success, which
/// is the check-then-delete race written once here instead of at every call
/// site.
pub async fn delete(task: &Task, envelope: &payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::DeleteParams>(task, envelope, "delete").await else {
        return;
    };

    let path = parameters.path.clone();
    let missing_ok = parameters.missing_ok;

    let work = async move {
        match tokio::fs::remove_file(&path).await {
            Ok(()) => Ok::<u64, String>(1),
            Err(error) if missing_ok && error.kind() == std::io::ErrorKind::NotFound => Ok(0),
            Err(error) => Err(io_message("remove", &path, &error)),
        }
    };

    match bounded(task, envelope.timeout_ms, work).await {
        Some(Ok(count)) => {
            task.add_result(Result::success(
                message,
                encode_count(count),
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

/// Removes a file a failed or cancelled write left half-finished.
///
/// Two things are left alone, and both of them are data the call never touched.
/// Append, for the same reason HttpClient's download leaves it alone: the file
/// held something before the call. And a destination the write never managed to
/// open — a Create refused because the path was already taken is the case that
/// matters, where removing it would delete the very file whose existence caused
/// the refusal.
async fn drop_partial(path: &str, append: bool, opened: &AtomicBool) {
    if append || !opened.load(Ordering::Relaxed) {
        return;
    }

    let _ = tokio::fs::remove_file(path).await;
}

/// A temporary name beside the destination, unique within the process and
/// unlikely to collide outside it.
fn temporary_sibling(path: &str) -> String {
    let sequence = ATOMIC_WRITE_COUNTER.fetch_add(1, Ordering::Relaxed);

    format!("{path}.{}-{sequence}.sconcur-tmp", std::process::id())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn contents_are_read_from_either_msgpack_string_form() {
        assert_eq!(
            bytes_of(&rmpv::Value::Binary(vec![1, 2, 3])).unwrap(),
            vec![1, 2, 3]
        );
        assert_eq!(
            bytes_of(&rmpv::Value::from("text")).unwrap(),
            b"text".to_vec()
        );
    }

    #[test]
    fn contents_that_are_not_a_string_are_refused() {
        let error = bytes_of(&rmpv::Value::from(7)).unwrap_err();

        assert!(error.contains("must be a string"), "{error}");
    }

    #[test]
    fn two_atomic_writes_to_one_path_get_different_temporaries() {
        assert_ne!(
            temporary_sibling("/var/app/state.json"),
            temporary_sibling("/var/app/state.json")
        );
    }

    /// Undo the `!opened` guard in drop_partial and this one fails: a Create
    /// refused because the path was taken would delete the file that caused the
    /// refusal.
    #[tokio::test]
    async fn a_write_that_never_opened_its_destination_leaves_it_alone() {
        let path = std::env::temp_dir().join(format!(
            "sconcur-drop-partial-{}-{}",
            std::process::id(),
            ATOMIC_WRITE_COUNTER.fetch_add(1, Ordering::Relaxed)
        ));
        let path = path.to_string_lossy().to_string();

        tokio::fs::write(&path, b"already here").await.unwrap();

        let never_opened = AtomicBool::new(false);

        drop_partial(&path, false, &never_opened).await;

        assert_eq!(
            tokio::fs::read(&path).await.unwrap(),
            b"already here".to_vec()
        );

        // What the guard does let through: a destination this write did open.
        let opened = AtomicBool::new(true);

        drop_partial(&path, false, &opened).await;

        assert!(tokio::fs::metadata(&path).await.is_err());
    }

    #[tokio::test]
    async fn an_append_is_never_dropped_even_when_it_opened_the_file() {
        let path = std::env::temp_dir().join(format!(
            "sconcur-drop-append-{}-{}",
            std::process::id(),
            ATOMIC_WRITE_COUNTER.fetch_add(1, Ordering::Relaxed)
        ));
        let path = path.to_string_lossy().to_string();

        tokio::fs::write(&path, b"log line").await.unwrap();

        drop_partial(&path, true, &AtomicBool::new(true)).await;

        assert_eq!(tokio::fs::read(&path).await.unwrap(), b"log line".to_vec());

        tokio::fs::remove_file(&path).await.unwrap();
    }

    #[test]
    fn a_temporary_is_a_sibling_of_its_destination() {
        // Same directory, or the rename at the end of an atomic write would
        // cross filesystems and stop being atomic.
        let temporary = temporary_sibling("/var/app/state.json");

        assert!(temporary.starts_with("/var/app/state.json."), "{temporary}");
    }
}
