//! Mirrors ext/internal/logger: a fire-and-forget asynchronous log sink.
//!
//! Callers compose a line — the access-log line a server emits per request or
//! per connection — and hand it over; the write happens off the caller's thread.
//! That is what keeps PHP's single-threaded cooperative loop from ever blocking
//! on log I/O.

use std::io::Write;
use std::sync::OnceLock;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::Duration;

/// Bounds memory: if logging outpaces the writer, new lines are dropped and
/// counted rather than blocking the producer.
const QUEUE_SIZE: usize = 4096;

/// Bounds how long a line can sit buffered before it is visible. Access logs do
/// not need per-line flushing.
const FLUSH_INTERVAL: Duration = Duration::from_millis(100);

static DROPPED: AtomicU64 = AtomicU64::new(0);

fn sender() -> &'static crossbeam_channel::Sender<Message> {
    static SENDER: OnceLock<crossbeam_channel::Sender<Message>> = OnceLock::new();

    SENDER.get_or_init(|| {
        let (sender, receiver) = crossbeam_channel::bounded::<Message>(QUEUE_SIZE);

        // A plain OS thread, not a runtime task: the sink must work before the
        // runtime exists and keep working while it is busy.
        let _ = std::thread::Builder::new()
            .name("sconcur-log".to_string())
            .spawn(move || run(receiver));

        sender
    })
}

enum Message {
    Line(String),
    Flush(crossbeam_channel::Sender<()>),
}

fn run(receiver: crossbeam_channel::Receiver<Message>) {
    let mut buffer: Vec<u8> = Vec::with_capacity(16 * 1024);
    let mut stdout = std::io::stdout();

    loop {
        match receiver.recv_timeout(FLUSH_INTERVAL) {
            Ok(Message::Line(line)) => {
                buffer.extend_from_slice(line.as_bytes());

                // Drain whatever else is queued before touching the fd, so a
                // burst costs one write rather than one per line.
                while let Ok(Message::Line(line)) = receiver.try_recv() {
                    buffer.extend_from_slice(line.as_bytes());

                    if buffer.len() >= 16 * 1024 {
                        break;
                    }
                }

                let _ = stdout.write_all(&buffer);
                let _ = stdout.flush();

                buffer.clear();
            }
            Ok(Message::Flush(done)) => {
                if !buffer.is_empty() {
                    let _ = stdout.write_all(&buffer);

                    buffer.clear();
                }

                let _ = stdout.flush();
                let _ = done.send(());
            }
            Err(crossbeam_channel::RecvTimeoutError::Timeout) => {
                if !buffer.is_empty() {
                    let _ = stdout.write_all(&buffer);
                    let _ = stdout.flush();

                    buffer.clear();
                }
            }
            Err(crossbeam_channel::RecvTimeoutError::Disconnected) => return,
        }
    }
}

/// Queues one pre-formatted line. Never blocks: past the queue the line is
/// dropped and counted, because a server must not stall on its own logging.
pub fn write(line: String) {
    if sender().try_send(Message::Line(line)).is_err() {
        DROPPED.fetch_add(1, Ordering::Relaxed);
    }
}

/// Flushes what is buffered and waits for it. Called from destroy(), so a
/// process that exits right after still shows its last lines.
pub fn flush() {
    let (done, wait) = crossbeam_channel::bounded(1);

    if sender().try_send(Message::Flush(done)).is_ok() {
        let _ = wait.recv_timeout(Duration::from_secs(1));
    }
}

/// Renders the timestamp prefix every access line starts with, in the format the
/// Go side writes: `2006-01-02T15:04:05.000000`.
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
