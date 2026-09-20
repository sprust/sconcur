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
use super::{bounded, bounded_size, creates_the_file, params, permission_bits, write_options};
use super::payloads;

/// The copy granularity when the caller names none (64 KiB, as HttpClient's
/// download uses), and the ceiling a caller may raise it to.
const DEFAULT_COPY_BUFFER_BYTES: usize = 65_536;
const MAX_COPY_BUFFER_BYTES: usize = 8 * 1024 * 1024;

/// How much a one-shot read reserves up front before it starts growing as it
/// goes. A file's reported size is not this process's decision, and a sparse or
/// misreported one would otherwise be handed straight to the allocator.
const READ_PREALLOCATION_CAP_BYTES: u64 = 64 * 1024 * 1024;

/// Distinguishes the temporary files of two atomic writes racing on the same
/// path from the same process.
static ATOMIC_WRITE_COUNTER: AtomicU64 = AtomicU64::new(0);

/// The bytes of a value PHP packed as a string.
///
/// Takes the value by move and unwraps the Vec out of it rather than cloning:
/// for a write these bytes are the file's whole contents, and a clone here is
/// the difference between holding a 100 MiB payload twice and holding it once.
pub fn bytes_of(value: rmpv::Value) -> std::result::Result<Vec<u8>, String> {
    match value {
        rmpv::Value::Binary(bytes) => Ok(bytes),
        rmpv::Value::String(text) => match text.into_str() {
            Some(text) => Ok(text.into_bytes()),
            None => Err("contents are not valid text".to_string()),
        },
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
pub async fn read(task: &Task, envelope: &mut payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::ReadParams>(task, envelope, "read").await else {
        return;
    };

    if parameters.offset_bytes < 0 || parameters.length_bytes < 0 || parameters.max_read_bytes < 0 {
        task.add_result(Result::error(
            message,
            fail(
                Kind::Argument,
                "offsetBytes, lengthBytes and maxReadBytes must not be negative",
            ),
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
    // than a doubling ladder — but capped, because reserving what a stat
    // reported is still reserving a number this process did not choose. Past the
    // cap the Vec grows as it reads, which costs a few reallocations and cannot
    // abort the process on a sparse or lying size.
    let mut contents = Vec::with_capacity(wanted.min(READ_PREALLOCATION_CAP_BYTES) as usize);

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
    }

    // Checked again on what is actually in hand, not only on what the stat
    // promised. Two files answer more than the stat said they would: one under
    // /proc, which reports zero and reads anyway, and one that grew between the
    // stat and the read. The memory is spent by the time this fires, but the
    // caller is told rather than handed more than it asked to be handed.
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

    Ok(contents)
}

/// Writes, appends or creates a file in one shot.
pub async fn write(task: &Task, envelope: &mut payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::WriteParams>(task, envelope, "write").await else {
        return;
    };

    let contents = match bytes_of(parameters.contents) {
        Ok(contents) => contents,
        Err(error) => {
            task.add_result(Result::error(message, fail(Kind::Argument, &error)))
                .await;

            return;
        }
    };

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

    let path = parameters.path.clone();
    let created_by_us = creates_the_file(&parameters.mode);
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
            drop_partial(&parameters.path, created_by_us, &opened).await;

            task.add_result(Result::error(message, text)).await;
        }
        None => drop_partial(&parameters.path, created_by_us, &opened).await,
    }
}

/// Writes through a temporary file in the same directory and a rename, so a
/// concurrent reader sees either the old contents or the new ones and never a
/// half-write.
///
/// The temporary file is a sibling on purpose: rename is atomic only within one
/// filesystem, and a path under /tmp would not be one.
///
/// The rename replaces the destination's inode, so the result carries the
/// temporary's identity: its permissions are inherited from the old file below,
/// but its owner is whoever this process runs as and its ACLs are gone. Writing
/// atomically over a file owned by someone else changes who owns it.
pub async fn write_atomic(task: &Task, envelope: &mut payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) =
        params::<payloads::WriteAtomicParams>(task, envelope, "writeAtomic").await
    else {
        return;
    };

    let contents = match bytes_of(parameters.contents) {
        Ok(contents) => contents,
        Err(error) => {
            task.add_result(Result::error(message, fail(Kind::Argument, &error)))
                .await;

            return;
        }
    };

    let path = parameters.path.clone();
    let temporary_path = temporary_sibling(&path);

    let permissions = match permission_bits(parameters.permissions, 0) {
        Ok(permissions) => permissions,
        Err(text) => {
            task.add_result(Result::error(message, text)).await;

            return;
        }
    };

    let work = {
        let temporary_path = temporary_path.clone();

        async move {
            // A rename replaces the destination's inode, so the new file's
            // permissions are whatever the temporary was created with. Without
            // this, an atomic write over a 0600 secret would leave it 0644 —
            // the operation would quietly widen the rights on the file it exists
            // to update safely. The caller's own bits win when it names any.
            let existing = tokio::fs::metadata(&path).await.ok();

            let permissions = match (permissions, &existing) {
                (0, Some(metadata)) => {
                    std::os::unix::fs::PermissionsExt::mode(&metadata.permissions()) & 0o7777
                }
                (0, None) => 0o644,
                (chosen, _) => chosen,
            };

            let mut options = tokio::fs::OpenOptions::new();

            options.write(true).create_new(true);
            options.mode(permissions);

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
pub async fn truncate(task: &Task, envelope: &mut payloads::Envelope) {
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
        Some(Ok(_)) => {
            task.add_result(Result::success(
                message,
                Vec::new(),
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
pub async fn copy(task: &Task, envelope: &mut payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::CopyParams>(task, envelope, "copy").await else {
        return;
    };

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

    let source = parameters.source.clone();
    let destination = parameters.destination.clone();
    let created_by_us = creates_the_file(&parameters.mode);
    let opened = Arc::new(AtomicBool::new(false));

    // Clamped, not taken as given: this number becomes two Vec::with_capacity
    // calls below, and an unclamped one reaches the allocator, which aborts the
    // process instead of panicking.
    let buffer_size = bounded_size(
        parameters.buffer_size_bytes,
        DEFAULT_COPY_BUFFER_BYTES,
        MAX_COPY_BUFFER_BYTES,
    );

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
            drop_partial(&parameters.destination, created_by_us, &opened).await;

            task.add_result(Result::error(message, text)).await;
        }
        // Cancelled or out of time: the future is dropped by now, so the
        // half-written destination is cleaned up here rather than inside it.
        None => drop_partial(&parameters.destination, created_by_us, &opened).await,
    }
}

/// Renames a file, falling back to a copy and a delete when the two paths are
/// on different filesystems — where rename(2) answers EXDEV and PHP's own
/// rename() does the same fallback.
pub async fn move_file(task: &Task, envelope: &mut payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::MoveParams>(task, envelope, "move").await else {
        return;
    };

    let source = parameters.source.clone();
    let destination = parameters.destination.clone();

    // A cross-device move copies through a sibling of the destination and
    // renames it into place, rather than copying onto the destination itself.
    //
    // Copying onto it would truncate a file the move has not yet earned the
    // right to replace, and cleaning up afterwards would unlink a file this
    // call never created — the rule creates_the_file exists to enforce. Through
    // a temporary, a failure at any point leaves the destination exactly as it
    // was, and the final rename is the same atomic swap a same-device move is.
    let temporary_path = temporary_sibling(&destination);

    let work = {
        let temporary_path = temporary_path.clone();

        async move {
            match tokio::fs::rename(&source, &destination).await {
                Ok(()) => Ok::<u64, String>(0),
                // Matched on the raw code rather than ErrorKind::CrossesDevices:
                // the mapping of that kind is the standard library's business
                // and this is the one case the fallback exists for.
                Err(error) if error.raw_os_error() == Some(libc::EXDEV) => {
                    let count = tokio::fs::copy(&source, &temporary_path)
                        .await
                        .map_err(|error| io_message("copy to", &temporary_path, &error))?;

                    tokio::fs::rename(&temporary_path, &destination)
                        .await
                        .map_err(|error| io_message("rename", &destination, &error))?;

                    tokio::fs::remove_file(&source)
                        .await
                        .map_err(|error| io_message("remove", &source, &error))?;

                    Ok(count)
                }
                Err(error) => Err(io_message("rename", &source, &error)),
            }
        }
    };

    let outcome = bounded(task, envelope.timeout_ms, work).await;

    // The temporary is this call's own, so it goes whatever happened — on the
    // success path it has already been renamed away and this is a no-op.
    let _ = tokio::fs::remove_file(&temporary_path).await;

    match outcome {
        Some(Ok(_)) => {
            task.add_result(Result::success(
                message,
                Vec::new(),
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
pub async fn delete(task: &Task, envelope: &mut payloads::Envelope) {
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

/// Removes a file a failed or cancelled write left half-finished — and only
/// ever a file this call brought into existence.
///
/// Two conditions, and both of them guard data the call had no right to take.
/// The write must have got as far as opening its destination, or a Create
/// refused because the path was taken would delete the very file whose
/// existence caused the refusal. And the mode must be the one that creates
/// (see mod.rs, creates_the_file): a Replace over an existing file has
/// truncated it, which is what was asked for, but removing it on top of that
/// destroys the inode, its permissions and its ownership — and, when the path
/// is a symlink, unlinks the link while leaving its target at length zero.
///
/// So a failed Replace leaves a partial file where a whole one used to be. That
/// is the lesser evil, and it is the caller's own file either way.
pub async fn drop_partial(path: &str, created_by_us: bool, opened: &AtomicBool) {
    if !created_by_us || !opened.load(Ordering::Relaxed) {
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

    /// A path of this test's own. The tests run in parallel, so a name shared
    /// between two of them makes them clobber each other.
    async fn fixture_path(name: &str) -> String {
        let path = std::env::temp_dir().join(format!(
            "sconcur-{name}-{}-{}",
            std::process::id(),
            ATOMIC_WRITE_COUNTER.fetch_add(1, Ordering::Relaxed)
        ));

        path.to_string_lossy().to_string()
    }

    #[test]
    fn contents_are_read_from_either_msgpack_string_form() {
        assert_eq!(
            bytes_of(rmpv::Value::Binary(vec![1, 2, 3])).unwrap(),
            vec![1, 2, 3]
        );
        assert_eq!(
            bytes_of(rmpv::Value::from("text")).unwrap(),
            b"text".to_vec()
        );
    }

    #[test]
    fn contents_that_are_not_a_string_are_refused() {
        let error = bytes_of(rmpv::Value::from(7)).unwrap_err();

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
        let path = fixture_path("drop-partial").await;

        tokio::fs::write(&path, b"already here").await.unwrap();

        let never_opened = AtomicBool::new(false);

        drop_partial(&path, true, &never_opened).await;

        assert_eq!(
            tokio::fs::read(&path).await.unwrap(),
            b"already here".to_vec()
        );

        // What the guard does let through: a destination this write created and
        // did open.
        drop_partial(&path, true, &AtomicBool::new(true)).await;

        assert!(tokio::fs::metadata(&path).await.is_err());
    }

    /// The bug this rule was rewritten for. Undo `created_by_us` and a Replace
    /// write that runs out of disk deletes the file it was replacing — the
    /// caller loses the old contents as well as the new.
    #[tokio::test]
    async fn a_replace_over_an_existing_file_is_never_removed() {
        let path = fixture_path("drop-replace").await;

        tokio::fs::write(&path, b"the previous version").await.unwrap();

        // Replace opened it, so `opened` is true — and the file still must stay,
        // because this call did not create it.
        drop_partial(&path, creates_the_file("rpl"), &AtomicBool::new(true)).await;

        assert!(tokio::fs::metadata(&path).await.is_ok());

        tokio::fs::remove_file(&path).await.unwrap();
    }

    #[tokio::test]
    async fn an_append_is_never_dropped_even_when_it_opened_the_file() {
        let path = fixture_path("drop-append").await;

        tokio::fs::write(&path, b"log line").await.unwrap();

        drop_partial(&path, creates_the_file("app"), &AtomicBool::new(true)).await;

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
