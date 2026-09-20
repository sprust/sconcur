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
    /// Directories found and not yet walked, taken from the end — so the walk
    /// goes depth-first and reaches leaves early rather than reading every
    /// directory of a level before descending.
    ///
    /// It is not a bound on memory: every subdirectory of each directory read
    /// is pushed, so a directory with a hundred thousand of them puts a hundred
    /// thousand paths here. What bounds the memory is that entries are buffered
    /// one directory at a time, not one tree at a time.
    remaining: Vec<String>,
}

pub struct WalkState {
    pattern: String,
    with_metadata: bool,
    batch_size: usize,
    /// How many entries one batch may examine before answering with what it
    /// has. See the module comment: without it a pattern that matches nothing
    /// turns one batch into a walk of the whole tree.
    scan_budget: usize,
    timeout_ms: i64,
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
        scan_budget: usize,
        timeout_ms: i64,
        message: Arc<Message>,
    ) -> Self {
        WalkState {
            pattern,
            with_metadata,
            batch_size,
            scan_budget,
            timeout_ms,
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
        let mut examined = 0;

        while entries.len() < self.batch_size {
            if let Some(entry) = frontier.buffered.pop_front() {
                entries.push(entry);

                continue;
            }

            // Out of budget with nothing matched: answer an empty batch that
            // says there is more. The iterator pulls again, the deadline gets
            // its chance, and the lock this holds is released in between.
            if examined >= self.scan_budget {
                break;
            }

            let Some(path) = frontier.remaining.pop() else {
                break;
            };

            // Read without the pattern: a directory joins the frontier whether
            // or not its own name matches, because the filter picks what is
            // reported, not where the walk goes. The filter is applied below.
            //
            // A directory that cannot be opened is skipped rather than ending
            // the walk: on a real tree one unreadable subdirectory is ordinary,
            // and refusing the whole walk over it would make this useless
            // anywhere but a directory the process owns outright.
            let read = match read_directory(path, String::new(), self.with_metadata).await {
                Ok(read) => read,
                Err(text) if skippable(&text) => continue,
                Err(text) => return Err(text),
            };

            examined += read.len();

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

/// Whether a directory failure is one the walk steps over rather than reports.
/// Both cases are ordinary on a live tree: an entry removed since it was listed,
/// and a subdirectory this process may not read.
fn skippable(text: &str) -> bool {
    text.starts_with("files[nf]:") || text.starts_with("files[pd]:")
}

impl StateContract for WalkState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            let Some(result) = super::bounded_state(self.timeout_ms, self.batch()).await else {
                return Result::error(
                    &self.message,
                    super::errors::message(
                        super::errors::Kind::Timeout,
                        &format!("walk: deadline of {} ms exceeded", self.timeout_ms),
                    ),
                );
            };

            result
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

impl WalkState {
    async fn batch(&self) -> Result {
        {
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
        }
    }
}
