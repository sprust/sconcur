//! A fire-and-forget asynchronous log sink.
//!
//! Callers compose a line — the access-log line a server emits per request or
//! per connection — and hand it over; the write happens off the caller's thread.
//! That is what keeps PHP's single-threaded cooperative loop from ever blocking
//! on log I/O.
//!
//! The sink belongs to the process's Core, so a fork discards it with everything
//! else: its writer is a thread, and a child forked after the parent logged has no
//! thread behind the channel it inherited. The child's first line starts a sink of
//! its own.

use std::io::Write;
use std::mem::ManuallyDrop;
use std::os::fd::FromRawFd;
use std::sync::OnceLock;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::Duration;

/// Bounds memory: if logging outpaces the writer, new lines are dropped and
/// counted rather than blocking the producer.
const QUEUE_SIZE: usize = 4096;

/// Bounds how long a line can sit buffered before it is visible. Access logs do
/// not need per-line flushing.
const FLUSH_INTERVAL: Duration = Duration::from_millis(100);

/// How much a burst gathers before it is written.
const BURST_BYTES: usize = 16 * 1024;

static DROPPED: AtomicU64 = AtomicU64::new(0);

enum Message {
    Line(String),
    Flush(crossbeam_channel::Sender<()>),
}

pub struct Logger {
    sender: OnceLock<crossbeam_channel::Sender<Message>>,
}

impl Logger {
    pub fn new() -> Self {
        Logger {
            sender: OnceLock::new(),
        }
    }

    fn sender(&self) -> &crossbeam_channel::Sender<Message> {
        self.sender.get_or_init(|| {
            let (sender, receiver) = crossbeam_channel::bounded::<Message>(QUEUE_SIZE);

            // A plain OS thread, not a runtime task: the sink must keep working
            // while the runtime is busy.
            let _ = std::thread::Builder::new()
                .name("sconcur-log".to_string())
                .spawn(move || {
                    let output = standard_output();

                    run(receiver, &*output);
                });

            sender
        })
    }

    /// Queues one pre-formatted line. Never blocks: past the queue the line is
    /// dropped and counted, because a server must not stall on its own logging.
    pub fn write(&self, line: String) {
        if self.sender().try_send(Message::Line(line)).is_err() {
            DROPPED.fetch_add(1, Ordering::Relaxed);
        }
    }

    /// Flushes what is buffered and waits for it. Called from destroy(), so a
    /// process that exits right after still shows its last lines.
    ///
    /// A sink that never took a line has nothing to flush, and is not started
    /// just to be flushed: that would put a thread into a process that may be
    /// about to fork.
    pub fn flush(&self) {
        let Some(sender) = self.sender.get() else {
            return;
        };

        let (done, wait) = crossbeam_channel::bounded(1);

        if sender.try_send(Message::Flush(done)).is_ok() {
            let _ = wait.recv_timeout(Duration::from_secs(1));
        }
    }
}

/// Queues one pre-formatted line on this process's sink.
pub fn write(line: String) {
    crate::core::get().logger().write(line);
}

/// Standard output through a handle of its own rather than `std::io::stdout()`,
/// which is a process-wide lock: a fork that lands while the parent's writer
/// holds it leaves the child's writer waiting on it forever. The sink buffers
/// itself, so the line buffering that handle adds is not missed.
///
/// ManuallyDrop, because the descriptor is the process's fd 1 and must outlive
/// the sink.
fn standard_output() -> ManuallyDrop<std::fs::File> {
    ManuallyDrop::new(unsafe { std::fs::File::from_raw_fd(1) })
}

fn run(receiver: crossbeam_channel::Receiver<Message>, mut output: impl Write) {
    let mut buffer: Vec<u8> = Vec::with_capacity(BURST_BYTES);

    loop {
        match receiver.recv_timeout(FLUSH_INTERVAL) {
            Ok(Message::Line(line)) => {
                buffer.extend_from_slice(line.as_bytes());

                // A flush that arrives in the middle of a burst is answered once
                // the burst is written, not dropped: dropping it would release
                // its caller before the lines it waits for reach the fd.
                let mut flushed: Option<crossbeam_channel::Sender<()>> = None;

                // Drain whatever else is queued before touching the fd, so a
                // burst costs one write rather than one per line.
                while let Ok(message) = receiver.try_recv() {
                    match message {
                        Message::Line(line) => {
                            buffer.extend_from_slice(line.as_bytes());

                            if buffer.len() >= BURST_BYTES {
                                break;
                            }
                        }
                        Message::Flush(done) => {
                            flushed = Some(done);

                            break;
                        }
                    }
                }

                let _ = output.write_all(&buffer);
                let _ = output.flush();

                buffer.clear();

                if let Some(done) = flushed {
                    let _ = done.send(());
                }
            }
            Ok(Message::Flush(done)) => {
                if !buffer.is_empty() {
                    let _ = output.write_all(&buffer);

                    buffer.clear();
                }

                let _ = output.flush();
                let _ = done.send(());
            }
            Err(crossbeam_channel::RecvTimeoutError::Timeout) => {
                if !buffer.is_empty() {
                    let _ = output.write_all(&buffer);
                    let _ = output.flush();

                    buffer.clear();
                }
            }
            Err(crossbeam_channel::RecvTimeoutError::Disconnected) => return,
        }
    }
}

/// Renders the timestamp prefix every access line starts with:
/// `2026-01-02T15:04:05.000000`.
pub fn timestamp(at: std::time::SystemTime) -> String {
    let stamp: chrono::DateTime<chrono::Local> = at.into();

    stamp.format("%Y-%m-%dT%H:%M:%S%.6f").to_string()
}

/// Escapes control characters so a crafted path or method cannot forge a log
/// line by embedding a newline.
pub fn sanitize(value: &str) -> String {
    if !value.chars().any(|char| (char as u32) < 0x20 || char as u32 == 0x7F) {
        return value.to_string();
    }

    let mut escaped = String::with_capacity(value.len());

    for byte in value.bytes() {
        if byte < 0x20 || byte == 0x7F {
            escaped.push_str(&format!("\\x{byte:02X}"));
        } else {
            escaped.push(byte as char);
        }
    }

    escaped
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::{Arc, Mutex};

    /// What a writer under test wrote, readable while it still runs.
    #[derive(Clone, Default)]
    struct Captured(Arc<Mutex<Vec<u8>>>);

    impl Write for Captured {
        fn write(&mut self, bytes: &[u8]) -> std::io::Result<usize> {
            self.0.lock().unwrap().extend_from_slice(bytes);

            Ok(bytes.len())
        }

        fn flush(&mut self) -> std::io::Result<()> {
            Ok(())
        }
    }

    /// The flush is queued before the writer starts, so it is taken while a
    /// burst is drained rather than on a pass of its own — the one place it used
    /// to be dropped, which released the caller before its lines were written.
    #[test]
    fn a_flush_queued_behind_a_burst_is_answered_after_the_burst_is_written() {
        let (sender, receiver) = crossbeam_channel::bounded::<Message>(QUEUE_SIZE);
        let (done, wait) = crossbeam_channel::bounded(1);

        sender.send(Message::Line("one\n".to_string())).unwrap();
        sender.send(Message::Line("two\n".to_string())).unwrap();
        sender.send(Message::Flush(done)).unwrap();

        let captured = Captured::default();
        let output = captured.clone();
        let writer = std::thread::spawn(move || run(receiver, output));

        assert!(
            wait.recv_timeout(Duration::from_secs(5)).is_ok(),
            "the flush was dropped instead of answered"
        );
        assert_eq!(*captured.0.lock().unwrap(), b"one\ntwo\n".to_vec());

        drop(sender);

        writer.join().unwrap();
    }

    /// destroy() flushes, and a process that never logged must come out of it
    /// with no thread of the sink's: that process may be a master about to fork.
    #[test]
    fn a_flush_starts_no_writer_on_a_sink_that_took_no_line() {
        let logger = Logger::new();

        logger.flush();

        assert!(logger.sender.get().is_none());
    }
}
