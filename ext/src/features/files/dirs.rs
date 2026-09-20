//! Directories: create, remove, list.
//!
//! list is where the feature earns most: scandir() followed by filesize() and
//! filemtime() on every entry is one syscall plus one per entry, each of them a
//! pause of the PHP thread. Here the whole walk happens inside the extension and
//! one list crosses the boundary — and the pattern filters before it does, so a
//! directory of a hundred thousand entries does not travel just to be thrown
//! away in PHP.

use std::time::{Instant, SystemTime, UNIX_EPOCH};

use crate::tasks::Task;

use super::errors::io_message;
use super::meta::publish;
use super::pattern;
use super::payloads;
use super::params;

/// One entry of a listing, in the order the fields are written.
pub struct Entry {
    pub name: String,
    pub path: String,
    pub is_directory: bool,
    pub is_symlink: bool,
    /// Absent without metadata: the caller asked not to pay a stat per entry.
    pub size_bytes: Option<u64>,
    pub modified_at_ms: Option<i64>,
}

/// Writes a batch of entries as the map PHP turns into DirectoryEntry objects.
pub fn encode_entries(entries: &[Entry]) -> Vec<u8> {
    let mut buffer = Vec::new();

    let _ = rmp::encode::write_map_len(&mut buffer, 1);
    let _ = rmp::encode::write_str(&mut buffer, "e");
    let _ = rmp::encode::write_array_len(&mut buffer, entries.len() as u32);

    for entry in entries {
        let with_metadata = entry.size_bytes.is_some() || entry.modified_at_ms.is_some();

        let _ = rmp::encode::write_map_len(&mut buffer, if with_metadata { 6 } else { 4 });

        let _ = rmp::encode::write_str(&mut buffer, "n");
        let _ = rmp::encode::write_str(&mut buffer, &entry.name);

        let _ = rmp::encode::write_str(&mut buffer, "p");
        let _ = rmp::encode::write_str(&mut buffer, &entry.path);

        let _ = rmp::encode::write_str(&mut buffer, "d");
        let _ = rmp::encode::write_bool(&mut buffer, entry.is_directory);

        let _ = rmp::encode::write_str(&mut buffer, "l");
        let _ = rmp::encode::write_bool(&mut buffer, entry.is_symlink);

        if with_metadata {
            let _ = rmp::encode::write_str(&mut buffer, "sz");
            let _ = rmp::encode::write_uint(&mut buffer, entry.size_bytes.unwrap_or(0));

            let _ = rmp::encode::write_str(&mut buffer, "mt");
            let _ = rmp::encode::write_sint(&mut buffer, entry.modified_at_ms.unwrap_or(0));
        }
    }

    buffer
}

/// Reads one directory whole — names, types and, if asked, a stat each — in a
/// single trip to the blocking pool.
///
/// One trip, not one per entry, and that is the whole point of this function.
/// tokio::fs sends every call to the pool on its own, so the obvious version of
/// this loop hands 20 000 syscalls across a thread boundary for a directory of
/// 10 000 files. Measured against scandir() + filesize() + filemtime(), that
/// version was eleven times slower than the native code it exists to beat; this
/// one pays for the hand-over once.
///
/// The entries come back sorted by name. read_dir gives whatever order the
/// filesystem keeps, which differs between filesystems and between runs, and a
/// listing nobody can predict is one no test can check.
pub async fn read_directory(
    path: String,
    pattern_text: String,
    with_metadata: bool,
) -> std::result::Result<Vec<Entry>, String> {
    let outcome = tokio::task::spawn_blocking(move || {
        let directory = std::fs::read_dir(&path)
            .map_err(|error| io_message("open directory", &path, &error))?;

        let mut entries = Vec::new();

        for entry in directory {
            let entry = entry.map_err(|error| io_message("read directory", &path, &error))?;

            let name = entry.file_name().to_string_lossy().to_string();

            if !pattern::matches(&pattern_text, &name) {
                continue;
            }

            // Comes from the directory read itself on the filesystems that carry
            // the type, so it costs nothing there and a stat only where it must.
            let file_type = entry
                .file_type()
                .map_err(|error| io_message("read entry type in", &path, &error))?;

            let (size_bytes, modified_at_ms) = if with_metadata {
                let metadata = entry
                    .metadata()
                    .map_err(|error| io_message("stat entry in", &path, &error))?;

                (
                    Some(metadata.len()),
                    Some(metadata.modified().map(epoch_ms).unwrap_or(0)),
                )
            } else {
                (None, None)
            };

            entries.push(Entry {
                name,
                path: entry.path().to_string_lossy().to_string(),
                is_directory: file_type.is_dir(),
                is_symlink: file_type.is_symlink(),
                size_bytes,
                modified_at_ms,
            });
        }

        entries.sort_by(|left, right| left.name.cmp(&right.name));

        Ok(entries)
    })
    .await;

    match outcome {
        Ok(entries) => entries,
        // The pool dropped the job, which means the runtime is going away.
        Err(error) => Err(super::errors::message(
            super::errors::Kind::Io,
            &format!("read directory: {error}"),
        )),
    }
}

pub fn epoch_ms(time: SystemTime) -> i64 {
    match time.duration_since(UNIX_EPOCH) {
        Ok(duration) => duration.as_millis() as i64,
        Err(error) => -(error.duration().as_millis() as i64),
    }
}

/// Creates a directory. Recursive creates the parents too, and then an existing
/// directory is a success rather than a failure — which is the mkdir -p
/// behaviour every caller of the recursive form is actually after.
pub async fn make_directory(task: &Task, envelope: &payloads::Envelope) {
    let start_time = Instant::now();

    let Some(parameters) =
        params::<payloads::MakeDirectoryParams>(task, envelope, "makeDirectory").await
    else {
        return;
    };

    let path = parameters.path.clone();
    let recursive = parameters.recursive;
    let permissions = if parameters.permissions > 0 {
        parameters.permissions as u32
    } else {
        0o755
    };

    let work = async move {
        let mut builder = tokio::fs::DirBuilder::new();

        builder.recursive(recursive);

        // Applied to every directory the recursive form creates, and subject to
        // the process umask exactly as mkdir(2) is.
        builder.mode(permissions);

        builder
            .create(&path)
            .await
            .map_err(|error| io_message("create directory", &path, &error))?;

        Ok::<Vec<u8>, String>(super::meta::encode_text("p", &path))
    };

    publish(task, envelope, start_time, work).await;
}

/// Removes a directory: empty by default, with everything under it when
/// recursive.
pub async fn remove_directory(task: &Task, envelope: &payloads::Envelope) {
    let start_time = Instant::now();

    let Some(parameters) =
        params::<payloads::RemoveDirectoryParams>(task, envelope, "removeDirectory").await
    else {
        return;
    };

    let path = parameters.path.clone();
    let recursive = parameters.recursive;
    let missing_ok = parameters.missing_ok;

    let work = async move {
        let removed = if recursive {
            tokio::fs::remove_dir_all(&path).await
        } else {
            tokio::fs::remove_dir(&path).await
        };

        match removed {
            Ok(()) => Ok::<Vec<u8>, String>(super::meta::encode_text("p", &path)),
            Err(error) if missing_ok && error.kind() == std::io::ErrorKind::NotFound => {
                Ok(super::meta::encode_text("p", ""))
            }
            Err(error) => Err(io_message("remove directory", &path, &error)),
        }
    };

    publish(task, envelope, start_time, work).await;
}

/// Lists one directory, filtered by the pattern, optionally with a stat per
/// entry.
///
/// Not recursive: a tree is walk(), which streams, because a listing that has
/// to be held whole before it can be answered is exactly the shape this feature
/// exists to avoid.
pub async fn list(task: &Task, envelope: &payloads::Envelope) {
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::ListParams>(task, envelope, "list").await else {
        return;
    };

    let path = parameters.path.clone();
    let pattern_text = parameters.pattern.clone();
    let with_metadata = parameters.with_metadata;

    let work = async move {
        read_directory(path, pattern_text, with_metadata)
            .await
            .map(|entries| encode_entries(&entries))
    };

    publish(task, envelope, start_time, work).await;
}

#[cfg(test)]
mod tests {
    use super::*;

    fn decoded(entries: &[Entry]) -> rmpv::Value {
        rmp_serde::from_slice(&encode_entries(entries)).unwrap()
    }

    fn entry(name: &str, with_metadata: bool) -> Entry {
        Entry {
            name: name.to_string(),
            path: format!("/tmp/{name}"),
            is_directory: false,
            is_symlink: false,
            size_bytes: with_metadata.then_some(42),
            modified_at_ms: with_metadata.then_some(1_700_000_000_000),
        }
    }

    #[test]
    fn an_entry_without_metadata_carries_only_the_four_free_fields() {
        let value = decoded(&[entry("a.txt", false)]);
        let list = value.as_map().unwrap()[0].1.as_array().unwrap();

        assert_eq!(list[0].as_map().unwrap().len(), 4);
    }

    #[test]
    fn an_entry_with_metadata_carries_the_size_and_the_time() {
        let value = decoded(&[entry("a.txt", true)]);
        let list = value.as_map().unwrap()[0].1.as_array().unwrap();
        let fields = list[0].as_map().unwrap();

        assert_eq!(fields.len(), 6);

        let size = fields
            .iter()
            .find(|(key, _)| key.as_str() == Some("sz"))
            .unwrap();

        assert_eq!(size.1.as_u64().unwrap(), 42);
    }

    #[test]
    fn an_empty_listing_is_an_empty_array_not_a_missing_key() {
        let value = decoded(&[]);
        let map = value.as_map().unwrap();

        assert_eq!(map[0].0.as_str().unwrap(), "e");
        assert!(map[0].1.as_array().unwrap().is_empty());
    }
}
