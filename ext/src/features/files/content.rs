//! The single-shot operations on a file's contents: read, write, truncate,
//! copy, move, delete.
//!
//! "Single-shot" means one payload in and one result out. Reading a file bigger
//! than the process wants to hold is what the streaming states are for; here
//! max_read_bytes refuses it instead.

use std::os::unix::fs::PermissionsExt;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::Instant;

use tokio::io::{AsyncReadExt, AsyncSeekExt, AsyncWriteExt};

use crate::dto::Result;
use crate::helpers::calc_execution_ms;
use crate::tasks::Task;

use super::errors::{io_message, message as fail, Kind};
use super::{
    bounded, bounded_size, creates_the_file, open_bounded, params, permission_bits,
    std_write_options, Budget,
};
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

    let created_by_us = creates_the_file(&parameters.mode);
    let budget = Budget::new(envelope.timeout_ms);

    // The open owns the deadline path: a file the pool creates after the caller
    // has given up is removed by open_bounded's own detached cleanup, which is
    // the only place that can wait for the open to land.
    let opened = open_bounded(
        task,
        budget.remaining_ms(),
        options,
        parameters.path.clone(),
        created_by_us,
    )
    .await;

    let mut file = match opened {
        Some(Ok(file)) => file,
        Some(Err(text)) => {
            task.add_result(Result::error(message, text)).await;

            return;
        }
        None => return,
    };

    let path = parameters.path.clone();

    let work = async move {
        file.write_all(&contents)
            .await
            .map_err(|error| io_message("write", &path, &error))?;

        file.flush()
            .await
            .map_err(|error| io_message("flush", &path, &error))?;

        Ok::<u64, String>(contents.len() as u64)
    };

    match bounded(task, budget.remaining_ms(), work).await {
        Some(Ok(count)) => {
            task.add_result(Result::success(
                message,
                encode_count(count),
                calc_execution_ms(start_time),
            ))
            .await;
        }
        // The file is open by now, so the question drop_partial asks about the
        // open is settled — what is left is the mode rule.
        Some(Err(text)) => {
            remove_if_created(&parameters.path, created_by_us).await;

            task.add_result(Result::error(message, text)).await;
        }
        None => remove_if_created(&parameters.path, created_by_us).await,
    }
}

/// Removes a file this call created and could not finish. The open is known to
/// have succeeded by every path that calls this, so only the mode rule is left:
/// a Replace over an existing file has truncated it, which is what was asked
/// for, but the file itself is not this call's to take.
pub async fn remove_if_created(path: &str, created_by_us: bool) {
    if !created_by_us {
        return;
    }

    let _ = tokio::fs::remove_file(path).await;
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

            // 0600 through the open, and the real bits set explicitly below:
            // the mode an open carries is narrowed by the process umask, so
            // inheriting 0664 from the destination would quietly become 0644
            // under the usual umask 022 — a group-writable file silently
            // narrowed on every atomic update.
            options.mode(0o600);

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

            tokio::fs::set_permissions(&temporary_path, std::fs::Permissions::from_mode(permissions))
                .await
                .map_err(|error| io_message("set permissions on", &temporary_path, &error))?;

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

    let created_by_us = creates_the_file(&parameters.mode);
    let budget = Budget::new(envelope.timeout_ms);

    // Clamped, not taken as given: this number becomes two Vec::with_capacity
    // calls below, and an unclamped one reaches the allocator, which aborts the
    // process instead of panicking.
    let buffer_size = bounded_size(
        parameters.buffer_size_bytes,
        DEFAULT_COPY_BUFFER_BYTES,
        MAX_COPY_BUFFER_BYTES,
    );

    let source = parameters.source.clone();
    let source_path = parameters.source.clone();

    let source_opened = bounded(task, budget.remaining_ms(), tokio::fs::File::open(source)).await;

    let source_file = match source_opened {
        Some(Ok(file)) => file,
        Some(Err(error)) => {
            task.add_result(Result::error(message, io_message("open", &source_path, &error)))
                .await;

            return;
        }
        None => return,
    };

    let opened = open_bounded(
        task,
        budget.remaining_ms(),
        options,
        parameters.destination.clone(),
        created_by_us,
    )
    .await;

    let destination_file = match opened {
        Some(Ok(file)) => file,
        Some(Err(text)) => {
            task.add_result(Result::error(message, text)).await;

            return;
        }
        None => return,
    };

    let destination = parameters.destination.clone();

    let work = async move {
        let mut reader = tokio::io::BufReader::with_capacity(buffer_size, source_file);
        let mut writer = tokio::io::BufWriter::with_capacity(buffer_size, destination_file);

        let count = tokio::io::copy(&mut reader, &mut writer)
            .await
            .map_err(|error| io_message("copy to", &destination, &error))?;

        writer
            .flush()
            .await
            .map_err(|error| io_message("flush", &destination, &error))?;

        Ok::<u64, String>(count)
    };

    match bounded(task, budget.remaining_ms(), work).await {
        Some(Ok(count)) => {
            task.add_result(Result::success(
                message,
                encode_count(count),
                calc_execution_ms(start_time),
            ))
            .await;
        }
        Some(Err(text)) => {
            remove_if_created(&parameters.destination, created_by_us).await;

            task.add_result(Result::error(message, text)).await;
        }
        // Cancelled or out of time: the future is dropped by now, so the
        // half-written destination is cleaned up here rather than inside it.
        None => remove_if_created(&parameters.destination, created_by_us).await,
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

    // A cross-device move copies through a sibling of the destination and
    // renames it into place, rather than copying onto the destination itself.
    //
    // Copying onto it would truncate a file the move has not yet earned the
    // right to replace, and cleaning up afterwards would unlink a file this
    // call never created. Through a temporary, a failure at any point leaves
    // the destination exactly as it was, and the final rename is the same
    // atomic swap a same-device move is.
    let temporary_path = temporary_sibling(&parameters.destination);
    let budget = Budget::new(envelope.timeout_ms);

    let renamed = bounded(
        task,
        budget.remaining_ms(),
        tokio::fs::rename(parameters.source.clone(), parameters.destination.clone()),
    )
    .await;

    match renamed {
        Some(Ok(())) => {
            task.add_result(Result::success(
                message,
                Vec::new(),
                calc_execution_ms(start_time),
            ))
            .await;

            return;
        }
        // Matched on the raw code rather than ErrorKind::CrossesDevices: the
        // mapping of that kind is the standard library's business and this is
        // the one case the fallback exists for.
        Some(Err(error)) if error.raw_os_error() == Some(libc::EXDEV) => {}
        Some(Err(error)) => {
            task.add_result(Result::error(
                message,
                io_message("rename", &parameters.source, &error),
            ))
            .await;

            return;
        }
        None => return,
    }

    // create_new, never a plain copy onto the name: the temporary sits in the
    // destination's directory, which may be one another user can write, and a
    // name without O_EXCL can be pre-created as a symlink to have this process
    // write the moved file through it.
    //
    // Opened before the copy rather than inside it, so the cleanup below always
    // knows the file exists — a create left running in the blocking pool after
    // the deadline is open_bounded's problem, and it owns it.
    let mut options = std::fs::OpenOptions::new();

    options.write(true).create_new(true);

    std::os::unix::fs::OpenOptionsExt::mode(&mut options, 0o600);

    let opened = open_bounded(
        task,
        budget.remaining_ms(),
        options,
        temporary_path.clone(),
        true,
    )
    .await;

    let temporary_file = match opened {
        Some(Ok(file)) => file,
        Some(Err(text)) => {
            task.add_result(Result::error(message, text)).await;

            return;
        }
        None => return,
    };

    let work = {
        let source = parameters.source.clone();
        let destination = parameters.destination.clone();
        let temporary_path = temporary_path.clone();

        async move {
            let source_file = tokio::fs::File::open(&source)
                .await
                .map_err(|error| io_message("open", &source, &error))?;

            let mut reader =
                tokio::io::BufReader::with_capacity(DEFAULT_COPY_BUFFER_BYTES, source_file);
            let mut writer =
                tokio::io::BufWriter::with_capacity(DEFAULT_COPY_BUFFER_BYTES, temporary_file);

            tokio::io::copy(&mut reader, &mut writer)
                .await
                .map_err(|error| io_message("copy to", &destination, &error))?;

            writer
                .flush()
                .await
                .map_err(|error| io_message("flush", &destination, &error))?;

            // The destination keeps its own permissions when it is there, the
            // way write_atomic's rename does; a new one takes the source's.
            // Set explicitly rather than through the open, which the umask
            // narrows.
            let mode = mode_of(&destination)
                .await
                .or(mode_of(&source).await)
                .unwrap_or(0o644);

            let _ =
                tokio::fs::set_permissions(&temporary_path, std::fs::Permissions::from_mode(mode))
                    .await;

            tokio::fs::rename(&temporary_path, &destination)
                .await
                .map_err(|error| io_message("rename", &destination, &error))?;

            tokio::fs::remove_file(&source)
                .await
                .map_err(|error| io_message("remove", &source, &error))?;

            Ok::<(), String>(())
        }
    };

    let outcome = bounded(task, budget.remaining_ms(), work).await;

    // The temporary is this call's own and is known to exist by now, so it goes
    // whatever happened — on the success path the rename has taken it away and
    // this is a no-op.
    let _ = tokio::fs::remove_file(&temporary_path).await;

    match outcome {
        Some(Ok(())) => {
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

/// The permission bits of a path, if it has any.
async fn mode_of(path: &str) -> Option<u32> {
    tokio::fs::metadata(path)
        .await
        .ok()
        .map(|metadata| metadata.permissions().mode() & 0o7777)
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

/// A temporary name beside the destination: same directory, because rename is
/// atomic only within one filesystem.
///
/// Two properties beyond uniqueness. It is unguessable — the nanosecond clock
/// goes into it — because the directory may be one another user can write, and
/// a name that can be predicted can be pre-created as a symlink. And the
/// basename is truncated to leave room for the suffix, so a move of a file with
/// a 250-character name does not fail ENAMETOOLONG where it used to work.
fn temporary_sibling(path: &str) -> String {
    let sequence = ATOMIC_WRITE_COUNTER.fetch_add(1, Ordering::Relaxed);

    let nanos = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|elapsed| elapsed.subsec_nanos())
        .unwrap_or(0);

    let suffix = format!(".{}-{sequence}-{nanos:08x}.sconcur-tmp", std::process::id());

    // NAME_MAX is 255 on every filesystem this runs on; the directory part is
    // not the limit, the basename is.
    let (directory, name) = match path.rsplit_once('/') {
        Some((directory, name)) => (directory, name),
        None => ("", path),
    };

    let room = 255usize.saturating_sub(suffix.len());
    let kept: String = name.chars().take(room).collect();

    if directory.is_empty() {
        format!("{kept}{suffix}")
    } else {
        format!("{directory}/{kept}{suffix}")
    }
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
    fn a_temporary_leaves_room_for_its_suffix() {
        let long = "/tmp/".to_string() + &"n".repeat(250);
        let temporary = temporary_sibling(&long);

        let name = temporary.rsplit_once('/').unwrap().1;

        assert!(name.len() <= 255, "{} characters", name.len());
        assert!(name.ends_with(".sconcur-tmp"));
    }

    #[test]
    fn two_atomic_writes_to_one_path_get_different_temporaries() {
        assert_ne!(
            temporary_sibling("/var/app/state.json"),
            temporary_sibling("/var/app/state.json")
        );
    }

    /// The rule that replaced drop_partial's "did the open succeed" question:
    /// only a write that CREATED the file may remove it. Undo it and a Replace
    /// that runs out of disk deletes the file it was replacing — the caller
    /// loses the old contents as well as the new.
    #[tokio::test]
    async fn a_replace_over_an_existing_file_is_never_removed() {
        let path = fixture_path("drop-replace").await;

        tokio::fs::write(&path, b"the previous version").await.unwrap();

        remove_if_created(&path, creates_the_file("rpl")).await;

        assert!(tokio::fs::metadata(&path).await.is_ok());

        // And the mode that did create it is removed.
        remove_if_created(&path, creates_the_file("crt")).await;

        assert!(tokio::fs::metadata(&path).await.is_err());
    }

    #[tokio::test]
    async fn an_append_is_never_removed() {
        let path = fixture_path("drop-append").await;

        tokio::fs::write(&path, b"log line").await.unwrap();

        remove_if_created(&path, creates_the_file("app")).await;

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
