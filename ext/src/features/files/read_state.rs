//! Reading a file in batches: raw chunks, or lines.
//!
//! One state serves both, because they are the same loop with a different way
//! of cutting the buffer. What they share is the point of existing: a one-shot
//! read holds the file twice, once here and once in PHP, and this holds one
//! buffer whatever the size.
//!
//! Lines are cut here rather than in PHP for the reason fgets() in a loop is the
//! worst pattern in log processing: every one of those calls is a crossing and a
//! pause of the thread, and here a batch of them is one.

use std::sync::Arc;
use std::time::Instant;

use tokio::io::AsyncReadExt;
use tokio::sync::Mutex;

use crate::dto::{Message, Result};
use crate::helpers::calc_execution_ms;
use crate::states::{StateCloseFuture, StateContract, StateFuture};

use super::errors::{io_message, message as fail, Kind};

/// How a batch is cut.
pub enum Mode {
    /// One buffer per batch, handed over as it was read.
    Chunks,
    /// Up to `batch_size` lines per batch, separators removed.
    Lines,
}

struct Reader {
    file: tokio::fs::File,
    /// In line mode, the tail of the buffer that has no newline yet — the half
    /// of a line the next read completes. This is the whole reason a line
    /// cannot be broken by a batch boundary.
    pending: Vec<u8>,
    /// Whether the file has answered with zero bytes.
    drained: bool,
}

pub struct ReadState {
    path: String,
    mode: Mode,
    buffer_size_bytes: usize,
    batch_size: usize,
    /// The cap on one line in line mode. A file with no newline in it would
    /// otherwise grow `pending` to the size of the file, which is the memory
    /// this whole state exists to not spend.
    max_line_bytes: usize,
    message: Arc<Message>,
    reader: Mutex<Option<Reader>>,
    start_time: Instant,
}

impl ReadState {
    pub fn new(
        path: String,
        mode: Mode,
        buffer_size_bytes: usize,
        batch_size: usize,
        max_line_bytes: usize,
        message: Arc<Message>,
        file: tokio::fs::File,
    ) -> Self {
        ReadState {
            path,
            mode,
            buffer_size_bytes,
            batch_size,
            max_line_bytes,
            message,
            reader: Mutex::new(Some(Reader {
                file,
                pending: Vec::new(),
                drained: false,
            })),
            start_time: Instant::now(),
        }
    }

    /// One raw batch: a single buffer's worth, or nothing left.
    async fn next_chunk(&self, reader: &mut Reader) -> std::result::Result<Option<Vec<u8>>, String> {
        let mut buffer = vec![0_u8; self.buffer_size_bytes];

        let read = reader
            .file
            .read(&mut buffer)
            .await
            .map_err(|error| io_message("read", &self.path, &error))?;

        if read == 0 {
            reader.drained = true;

            return Ok(None);
        }

        buffer.truncate(read);

        Ok(Some(buffer))
    }

    /// One batch of lines, refilling from the file until the batch is full or
    /// the file ends.
    async fn next_lines(&self, reader: &mut Reader) -> std::result::Result<Vec<Vec<u8>>, String> {
        let mut lines = Vec::new();
        let mut buffer = vec![0_u8; self.buffer_size_bytes];

        while lines.len() < self.batch_size {
            // Everything already buffered, before asking the file for more.
            while lines.len() < self.batch_size {
                let Some(index) = reader.pending.iter().position(|byte| *byte == b'\n') else {
                    break;
                };

                let mut line: Vec<u8> = reader.pending.drain(..=index).collect();

                line.pop();

                // A file written on Windows ends its lines with \r\n, and a
                // caller comparing against "value" should not have to know that.
                if line.last() == Some(&b'\r') {
                    line.pop();
                }

                lines.push(line);
            }

            if lines.len() >= self.batch_size || reader.drained {
                break;
            }

            let read = reader
                .file
                .read(&mut buffer)
                .await
                .map_err(|error| io_message("read", &self.path, &error))?;

            if read == 0 {
                reader.drained = true;

                // Whatever is left without a newline is the last line — a file
                // whose final line has no terminator still has that line.
                if !reader.pending.is_empty() {
                    let mut line = std::mem::take(&mut reader.pending);

                    if line.last() == Some(&b'\r') {
                        line.pop();
                    }

                    lines.push(line);
                }

                break;
            }

            reader.pending.extend_from_slice(&buffer[..read]);

            if reader.pending.len() > self.max_line_bytes {
                return Err(fail(
                    Kind::TooLarge,
                    &format!(
                        "read {}: a line longer than the limit of {} bytes",
                        self.path, self.max_line_bytes
                    ),
                ));
            }
        }

        Ok(lines)
    }
}

/// A batch of lines, as a MessagePack array of binary strings. Binary, not text:
/// a log file is bytes, and declaring them UTF-8 breaks on the first line that
/// is not.
fn encode_lines(lines: &[Vec<u8>]) -> Vec<u8> {
    let mut buffer = Vec::new();

    let _ = rmp::encode::write_array_len(&mut buffer, lines.len() as u32);

    for line in lines {
        let _ = rmp::encode::write_bin(&mut buffer, line);
    }

    buffer
}

impl StateContract for ReadState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            let mut guard = self.reader.lock().await;

            let Some(reader) = guard.as_mut() else {
                return Result::error(
                    &self.message,
                    fail(Kind::State, &format!("the stream of {} is closed", self.path)),
                );
            };

            match self.mode {
                Mode::Chunks => match self.next_chunk(reader).await {
                    Ok(Some(chunk)) => Result::success_with_next(
                        &self.message,
                        chunk,
                        calc_execution_ms(self.start_time),
                    ),
                    // The empty last batch is what says the file ended. It costs
                    // one extra crossing and buys a rule with no special case in
                    // it: every batch but the last carries data.
                    Ok(None) => Result::success(
                        &self.message,
                        Vec::new(),
                        calc_execution_ms(self.start_time),
                    ),
                    Err(text) => Result::error(&self.message, text),
                },
                Mode::Lines => match self.next_lines(reader).await {
                    Ok(lines) => {
                        let drained = reader.drained && reader.pending.is_empty();
                        let body = encode_lines(&lines);

                        if drained {
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
                },
            }
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            // Dropping the handle is the whole of it; a read holds nothing on
            // the other side of it to release.
            let taken = self.reader.lock().await.take();

            drop(taken);
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The tests run in parallel, so each one needs a file of its own. Named by a
    /// counter rather than by the contents: two of these fixtures happened to be
    /// the same length, and sharing a path made them clobber each other.
    static FIXTURE_COUNTER: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(0);

    async fn state(contents: &[u8], mode: Mode, buffer_size_bytes: usize, batch_size: usize) -> ReadState {
        let path = std::env::temp_dir().join(format!(
            "sconcur-read-state-{}-{}",
            std::process::id(),
            FIXTURE_COUNTER.fetch_add(1, std::sync::atomic::Ordering::Relaxed)
        ));
        let path = path.to_string_lossy().to_string();

        tokio::fs::write(&path, contents).await.unwrap();

        let file = tokio::fs::File::open(&path).await.unwrap();

        ReadState::new(
            path,
            mode,
            buffer_size_bytes,
            batch_size,
            1_048_576,
            Arc::new(Message {
                flow_key: "flow".to_string(),
                method: crate::types::method::Method::Files,
                task_key: "flow:1".to_string(),
                payload: Vec::new(),
                is_next: false,
                owner_id: 1,
            }),
            file,
        )
    }

    async fn all_lines(state: &ReadState) -> Vec<String> {
        let mut collected = Vec::new();

        loop {
            let mut guard = state.reader.lock().await;
            let reader = guard.as_mut().unwrap();

            let lines = state.next_lines(reader).await.unwrap();
            let drained = reader.drained && reader.pending.is_empty();

            drop(guard);

            for line in lines {
                collected.push(String::from_utf8(line).unwrap());
            }

            if drained {
                break;
            }
        }

        collected
    }

    /// The bug this whole `pending` field exists to prevent: undo it and a line
    /// that straddles two reads comes back as two lines.
    #[tokio::test]
    async fn a_line_split_across_two_reads_stays_one_line() {
        // A buffer of 4 cuts "hello" in the middle, twice over.
        let state = state(b"hello world\nsecond line\n", Mode::Lines, 4, 100).await;

        assert_eq!(all_lines(&state).await, vec!["hello world", "second line"]);

        let _ = tokio::fs::remove_file(&state.path).await;
    }

    #[tokio::test]
    async fn a_last_line_without_a_terminator_is_still_a_line() {
        let state = state(b"one\ntwo", Mode::Lines, 64, 100).await;

        assert_eq!(all_lines(&state).await, vec!["one", "two"]);

        let _ = tokio::fs::remove_file(&state.path).await;
    }

    #[tokio::test]
    async fn carriage_returns_are_stripped_with_the_newline() {
        let state = state(b"one\r\ntwo\r\n", Mode::Lines, 3, 100).await;

        assert_eq!(all_lines(&state).await, vec!["one", "two"]);

        let _ = tokio::fs::remove_file(&state.path).await;
    }

    #[tokio::test]
    async fn an_empty_line_is_kept() {
        let state = state(b"one\n\ntwo\n", Mode::Lines, 64, 100).await;

        assert_eq!(all_lines(&state).await, vec!["one", "", "two"]);

        let _ = tokio::fs::remove_file(&state.path).await;
    }

    #[tokio::test]
    async fn an_empty_file_yields_no_lines() {
        let state = state(b"", Mode::Lines, 64, 100).await;

        assert!(all_lines(&state).await.is_empty());

        let _ = tokio::fs::remove_file(&state.path).await;
    }

    #[tokio::test]
    async fn a_batch_is_cut_at_the_batch_size() {
        let state = state(b"a\nb\nc\nd\ne\n", Mode::Lines, 64, 2).await;

        let mut guard = state.reader.lock().await;
        let reader = guard.as_mut().unwrap();

        assert_eq!(state.next_lines(reader).await.unwrap().len(), 2);
        assert_eq!(state.next_lines(reader).await.unwrap().len(), 2);
        assert_eq!(state.next_lines(reader).await.unwrap().len(), 1);

        drop(guard);

        let _ = tokio::fs::remove_file(&state.path).await;
    }

    #[tokio::test]
    async fn chunks_come_back_as_they_were_read() {
        let state = state(&[b'x'; 10], Mode::Chunks, 4, 1).await;

        let mut guard = state.reader.lock().await;
        let reader = guard.as_mut().unwrap();

        assert_eq!(state.next_chunk(reader).await.unwrap().unwrap().len(), 4);
        assert_eq!(state.next_chunk(reader).await.unwrap().unwrap().len(), 4);
        assert_eq!(state.next_chunk(reader).await.unwrap().unwrap().len(), 2);
        assert!(state.next_chunk(reader).await.unwrap().is_none());

        drop(guard);

        let _ = tokio::fs::remove_file(&state.path).await;
    }

    #[test]
    fn lines_encode_as_an_array_of_binaries() {
        let encoded = encode_lines(&[b"one".to_vec(), vec![0xff, 0x00]]);
        let decoded: rmpv::Value = rmp_serde::from_slice(&encoded).unwrap();

        let array = decoded.as_array().unwrap();

        assert_eq!(array.len(), 2);
        assert_eq!(array[0].as_slice().unwrap(), b"one");
        assert_eq!(array[1].as_slice().unwrap(), &[0xff, 0x00]);
    }
}
