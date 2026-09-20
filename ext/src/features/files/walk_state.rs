//! Walking a directory tree, a batch of entries at a time.
//!
//! list() answers one directory in one crossing, which is the right shape until
//! the tree is big enough that holding it whole is the problem. This is the same
//! walk with the holding taken out: the frontier lives here and PHP pulls a
//! batch when it wants one, so a break after the first match costs the first
//! directory and nothing more.
//!
//! A directory is read whole, in one trip to the blocking pool — see
//! dirs::read_directory for why that matters — and then served to PHP in
//! batches. So the memory this holds is one directory, not one tree.
//!
//! Directory symlinks are listed but never descended into. That is not a
//! limitation to work around later: a tree with a link back into itself has no
//! end, and following one would make this loop for ever.

use std::sync::Arc;
use std::time::Instant;

use tokio::sync::Mutex;

use crate::dto::{Message, Result};
use crate::helpers::calc_execution_ms;
use crate::states::{StateCloseFuture, StateContract, StateFuture};

use super::dirs::{encode_entries, read_directory, Entry};

struct Frontier {
    /// The entries of the directory read last, not yet handed to PHP.
    buffered: std::collections::VecDeque<Entry>,
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
                buffered: std::collections::VecDeque::new(),
                remaining: vec![root],
            }),
            start_time: Instant::now(),
        }
    }

    /// Collects up to batch_size entries, reading directories as the walk
    /// reaches them.
    async fn collect(&self, frontier: &mut Frontier) -> std::result::Result<Vec<Entry>, String> {
        let mut entries = Vec::new();

        while entries.len() < self.batch_size {
            if let Some(entry) = frontier.buffered.pop_front() {
                entries.push(entry);

                continue;
            }

            let Some(path) = frontier.remaining.pop() else {
                break;
            };

            // Read without the pattern: a directory joins the frontier whether
            // or not its own name matches, because the filter picks what is
            // reported, not where the walk goes. The filter is applied below.
            let read = read_directory(path, String::new(), self.with_metadata).await?;

            for entry in read {
                // Only a real directory is descended into, never a symlink to
                // one — that is what keeps a link back into an ancestor from
                // making this endless.
                if entry.is_directory {
                    frontier.remaining.push(entry.path.clone());
                }

                if super::pattern::matches(&self.pattern, &entry.name) {
                    frontier.buffered.push_back(entry);
                }
            }
        }

        Ok(entries)
    }

    fn finished(frontier: &Frontier) -> bool {
        frontier.buffered.is_empty() && frontier.remaining.is_empty()
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

            // An abandoned walk of a deep tree should keep neither the entries
            // it had read nor the list of directories it had found.
            frontier.buffered.clear();
            frontier.remaining.clear();
        })
    }
}
