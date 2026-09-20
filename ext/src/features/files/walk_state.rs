//! Walking a directory tree, a batch of entries at a time.
//!
//! list() answers one directory in one crossing, which is the right shape until
//! the tree is big enough that holding it whole is the problem. This is the same
//! walk with the holding taken out: the frontier lives here and PHP pulls a
//! batch when it wants one, so a break after the first match costs the first
//! batch and nothing more.
//!
//! Directory symlinks are listed but never descended into. That is not a
//! limitation to work around later — a tree with a link back into itself has no
//! end, and following one would make this loop for ever.

use std::sync::Arc;
use std::time::Instant;

use tokio::sync::Mutex;

use crate::dto::{Message, Result};
use crate::helpers::calc_execution_ms;
use crate::states::{StateCloseFuture, StateContract, StateFuture};

use super::dirs::{encode_entries, epoch_ms, Entry};
use super::errors::io_message;
use super::pattern;

struct Frontier {
    /// The directory being read, if one is open.
    current: Option<(String, tokio::fs::ReadDir)>,
    /// Directories found and not yet walked. Depth-first, because it keeps the
    /// frontier the depth of the tree rather than its width — a directory of a
    /// hundred thousand subdirectories would otherwise all sit here at once.
    remaining: Vec<String>,
}

pub struct WalkState {
    pattern: String,
    with_metadata: bool,
    batch_size: usize,
    message: Arc<Message>,
    frontier: Mutex<Frontier>,
    start_time: Instant,
}

impl WalkState {
    pub fn new(
        root: String,
        pattern: String,
        with_metadata: bool,
        batch_size: usize,
        message: Arc<Message>,
    ) -> Self {
        WalkState {
            pattern,
            with_metadata,
            batch_size,
            message,
            frontier: Mutex::new(Frontier {
                current: None,
                remaining: vec![root],
            }),
            start_time: Instant::now(),
        }
    }

    /// Collects up to batch_size matching entries, opening directories as the
    /// walk reaches them.
    async fn collect(
        &self,
        frontier: &mut Frontier,
    ) -> std::result::Result<Vec<Entry>, String> {
        let mut entries = Vec::new();

        while entries.len() < self.batch_size {
            if frontier.current.is_none() {
                let Some(path) = frontier.remaining.pop() else {
                    break;
                };

                let directory = tokio::fs::read_dir(&path)
                    .await
                    .map_err(|error| io_message("open directory", &path, &error))?;

                frontier.current = Some((path, directory));
            }

            let Some((path, directory)) = frontier.current.as_mut() else {
                break;
            };

            let entry = directory
                .next_entry()
                .await
                .map_err(|error| io_message("read directory", path, &error))?;

            let Some(entry) = entry else {
                frontier.current = None;

                continue;
            };

            let file_type = entry
                .file_type()
                .await
                .map_err(|error| io_message("read entry type in", path, &error))?;

            let entry_path = entry.path().to_string_lossy().to_string();

            // A real directory joins the frontier whether or not its own name
            // matches the pattern: the filter picks what is reported, not where
            // the walk goes.
            if file_type.is_dir() {
                frontier.remaining.push(entry_path.clone());
            }

            let name = entry.file_name().to_string_lossy().to_string();

            if !pattern::matches(&self.pattern, &name) {
                continue;
            }

            let (size_bytes, modified_at_ms) = if self.with_metadata {
                let metadata = entry
                    .metadata()
                    .await
                    .map_err(|error| io_message("stat entry in", path, &error))?;

                (
                    Some(metadata.len()),
                    Some(metadata.modified().map(epoch_ms).unwrap_or(0)),
                )
            } else {
                (None, None)
            };

            entries.push(Entry {
                name,
                path: entry_path,
                is_directory: file_type.is_dir(),
                is_symlink: file_type.is_symlink(),
                size_bytes,
                modified_at_ms,
            });
        }

        Ok(entries)
    }

    fn finished(frontier: &Frontier) -> bool {
        frontier.current.is_none() && frontier.remaining.is_empty()
    }
}

impl StateContract for WalkState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            let mut frontier = self.frontier.lock().await;

            match self.collect(&mut frontier).await {
                Ok(entries) => {
                    let body = encode_entries(&entries);

                    if Self::finished(&frontier) {
                        Result::success(&self.message, body, calc_execution_ms(self.start_time))
                    } else {
                        Result::success_with_next(
                            &self.message,
                            body,
                            calc_execution_ms(self.start_time),
                        )
                    }
                }
                Err(text) => Result::error(&self.message, text),
            }
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            let mut frontier = self.frontier.lock().await;

            // The open directory handle goes, and so does the frontier: an
            // abandoned walk of a deep tree should not keep a list of every
            // directory it had found.
            frontier.current = None;
            frontier.remaining.clear();
        })
    }
}
