//! A fire-and-forget asynchronous log sink.
//!
//! Callers compose a line — the access-log line a server emits per request or
//! per connection, or a diagnostic — and hand it over; the write happens off the
//! caller's thread. That is what keeps PHP's single-threaded cooperative loop from
//! ever blocking on log I/O.
//!
//! Access lines go where `SCONCUR_ACCESS_LOG` says: to stdout when it is unset or
//! empty, nowhere when it is `off`, and otherwise to a file of the day named after
//! it — `<value>-YYYY-MM-DD.log`, opened for appending and reopened when the date
//! changes. The worker master sets it, so a worker of a busy pool writes its access
//! lines to disk itself instead of through the pipe its supervisor reads: that pipe
//! is read by one PHP process for the whole group, and fell behind. Every other
//! line goes to stdout.
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
use std::time::{Duration, Instant};

/// Bounds memory: if logging outpaces the writer, new lines are dropped and
/// counted rather than blocking the producer.
const QUEUE_SIZE: usize = 4096;

/// Bounds how long a line can sit buffered before it is visible. Access logs do
/// not need per-line flushing.
const FLUSH_INTERVAL: Duration = Duration::from_millis(100);

/// How much a burst gathers before it is written.
const BURST_BYTES: usize = 16 * 1024;

/// How often lines dropped since the last report are reported, at most.
const DROP_REPORT_INTERVAL: Duration = Duration::from_secs(60);

/// How long a flush waits for room in the queue, and then for the writer.
const FLUSH_WAIT: Duration = Duration::from_secs(1);

/// The variable naming where access lines go.
const ACCESS_LOG_VARIABLE: &str = "SCONCUR_ACCESS_LOG";

static DROPPED: AtomicU64 = AtomicU64::new(0);

enum Message {
    Line(String),
    Access(String),
    Flush(crossbeam_channel::Sender<()>),
}

pub struct Logger {
    /// The writer's queue, set by the first line. None inside once the writer
    /// thread could not be started: every line after is dropped and counted, and
    /// a flush has no writer to wait for.
    sender: OnceLock<Option<crossbeam_channel::Sender<Message>>>,
}

impl Logger {
    pub fn new() -> Self {
        Logger {
            sender: OnceLock::new(),
        }
    }

    fn sender(&self) -> Option<&crossbeam_channel::Sender<Message>> {
        self.sender
            .get_or_init(|| {
                let (sender, receiver) = crossbeam_channel::bounded::<Message>(QUEUE_SIZE);

                // Read when the sink starts, on the PHP thread: by then the worker master's
                // variables are in this process's environment (ForkedWorker puts them there).
                let access = AccessTarget::from_environment();

                // A plain OS thread, not a runtime task: the sink must keep working
                // while the runtime is busy.
                let started = std::thread::Builder::new()
                    .name("sconcur-log".to_string())
                    .spawn(move || {
                        let output = standard_descriptor(1);
                        let errors = standard_descriptor(2);

                        run(
                            receiver,
                            Writer::new(&*output, &*errors, access, today),
                        );
                    });

                match started {
                    Ok(_) => Some(sender),
                    Err(error) => {
                        report_unstarted(&error);

                        None
                    }
                }
            })
            .as_ref()
    }

    /// Queues one pre-formatted line for stdout. Never blocks: past the queue the
    /// line is dropped and counted, because a server must not stall on its own
    /// logging.
    pub fn write(&self, line: String) {
        self.queue(Message::Line(line));
    }

    /// Queues one access line, for wherever access lines go (see the module).
    pub fn access(&self, line: String) {
        self.queue(Message::Access(line));
    }

    fn queue(&self, message: Message) {
        let queued = self
            .sender()
            .is_some_and(|sender| sender.try_send(message).is_ok());

        if !queued {
            DROPPED.fetch_add(1, Ordering::Relaxed);
        }
    }

    /// Flushes what is buffered and waits for it. Called from destroy(), and by
    /// the process export's exit right before _exit, so a process that exits
    /// right after still shows its last lines.
    ///
    /// Unlike a line, the flush waits for room in a full queue: dropped, it would
    /// release its caller to exit with every line queued ahead of it unwritten.
    /// Both waits are bounded by FLUSH_WAIT, for a writer that is gone.
    ///
    /// A sink that never took a line has nothing to flush, and is not started
    /// just to be flushed: that would put a thread into a process that may be
    /// about to fork.
    pub fn flush(&self) {
        let Some(Some(sender)) = self.sender.get() else {
            return;
        };

        let (done, wait) = crossbeam_channel::bounded(1);

        if sender.send_timeout(Message::Flush(done), FLUSH_WAIT).is_ok() {
            let _ = wait.recv_timeout(FLUSH_WAIT);
        }
    }
}

/// Says on stderr that the writer thread did not start, once: the writer is
/// what reports dropped lines, so nothing else would ever say it.
fn report_unstarted(error: &std::io::Error) {
    let _ = writeln!(
        &*standard_descriptor(2),
        "log: the writer thread cannot start ({error}); every line of this process is dropped"
    );
}

/// Queues one pre-formatted line for stdout on this process's sink.
pub fn write(line: String) {
    crate::core::get().logger().write(line);
}

/// Queues one access line on this process's sink.
pub fn access(line: String) {
    crate::core::get().logger().access(line);
}

/// A standard descriptor through a handle of its own rather than `std::io::stdout()`
/// or `stderr()`, which are process-wide locks: a fork that lands while the parent's
/// writer holds one leaves the child's writer waiting on it forever. The sink
/// buffers itself, so the line buffering those handles add is not missed.
///
/// ManuallyDrop, because the descriptor is the process's own and must outlive the
/// sink.
fn standard_descriptor(descriptor: i32) -> ManuallyDrop<std::fs::File> {
    ManuallyDrop::new(unsafe { std::fs::File::from_raw_fd(descriptor) })
}

/// Today in the clock the access lines are stamped with, as their file names it.
fn today() -> String {
    chrono::Local::now().format("%Y-%m-%d").to_string()
}

/// Where access lines go.
enum AccessTarget {
    Stdout,
    Off,
    /// A file per day, `<prefix>-YYYY-MM-DD.log`. `label` ends each line, since the
    /// workers of a pool share the file: the worker's name in its pool and its pid.
    Daily { prefix: String, label: String },
}

impl AccessTarget {
    fn from_environment() -> Self {
        let name = std::env::var("SCONCUR_SERVER_NAME").unwrap_or_default();

        Self::parse(
            &std::env::var(ACCESS_LOG_VARIABLE).unwrap_or_default(),
            &name,
            std::process::id(),
        )
    }

    /// The master names a worker `<group>:<slot>`; the line says `<group>#<slot>`, as
    /// the journal does.
    fn parse(value: &str, server_name: &str, pid: u32) -> Self {
        match value {
            "" => AccessTarget::Stdout,
            "off" => AccessTarget::Off,
            prefix => {
                let name = match server_name.rfind(':') {
                    Some(at) => format!("{}#{}", &server_name[..at], &server_name[at + 1..]),
                    None => server_name.to_string(),
                };

                AccessTarget::Daily {
                    prefix: prefix.to_string(),
                    label: format!(" {name} pid={pid}"),
                }
            }
        }
    }
}

/// What the writer thread writes to, and what it holds between bursts.
struct Writer<O: Write, E: Write, D: Fn() -> String> {
    output: O,
    errors: E,
    access: AccessTarget,
    today: D,
    output_buffer: Vec<u8>,
    access_buffer: Vec<u8>,
    /// The file of `date`, when access lines go to a file and it could be opened.
    file: Option<std::fs::File>,
    /// The date `file` belongs to — or the date opening it failed on, so the failure
    /// is reported once a day rather than once a burst.
    date: String,
    reported_drops: u64,
    reported_at: Instant,
}

impl<O: Write, E: Write, D: Fn() -> String> Writer<O, E, D> {
    fn new(output: O, errors: E, access: AccessTarget, today: D) -> Self {
        Writer {
            output,
            errors,
            access,
            today,
            output_buffer: Vec::with_capacity(BURST_BYTES),
            access_buffer: Vec::with_capacity(BURST_BYTES),
            file: None,
            date: String::new(),
            reported_drops: DROPPED.load(Ordering::Relaxed),
            reported_at: Instant::now(),
        }
    }

    fn take(&mut self, message: Message) -> Option<crossbeam_channel::Sender<()>> {
        match message {
            Message::Line(line) => self.output_buffer.extend_from_slice(line.as_bytes()),
            Message::Access(line) => match &self.access {
                AccessTarget::Stdout => self.output_buffer.extend_from_slice(line.as_bytes()),
                AccessTarget::Off => {}
                AccessTarget::Daily { label, .. } => {
                    self.access_buffer
                        .extend_from_slice(line.strip_suffix('\n').unwrap_or(&line).as_bytes());
                    self.access_buffer.extend_from_slice(label.as_bytes());
                    self.access_buffer.push(b'\n');
                }
            },
            Message::Flush(done) => return Some(done),
        }

        None
    }

    fn is_full(&self) -> bool {
        self.output_buffer.len() + self.access_buffer.len() >= BURST_BYTES
    }

    fn write_out(&mut self) {
        if !self.access_buffer.is_empty() {
            self.write_access();
        }

        if !self.output_buffer.is_empty() {
            let _ = self.output.write_all(&self.output_buffer);
            let _ = self.output.flush();

            self.output_buffer.clear();
        }

        self.report_drops();
    }

    /// Writes the buffered access lines to the file of the day, opening it — or the
    /// next day's — first. A file that cannot be opened, or stops taking writes — a
    /// full disk, an I/O error — is reported once, and its lines go to stdout for
    /// the rest of the day rather than nowhere.
    fn write_access(&mut self) {
        let today = (self.today)();

        if today != self.date {
            self.date = today;
            self.file = None;

            if let Some(path) = self.daily_path() {
                match std::fs::OpenOptions::new().create(true).append(true).open(&path) {
                    Ok(file) => self.file = Some(file),
                    Err(error) => {
                        let _ = writeln!(
                            self.errors,
                            "access log: cannot open {path}: {error}; access lines go to stdout"
                        );
                    }
                }
            }
        }

        let failure = match &mut self.file {
            Some(file) => file.write_all(&self.access_buffer).err(),
            None => None,
        };

        if let Some(error) = failure {
            let _ = writeln!(
                self.errors,
                "access log: cannot write {}: {error}; access lines go to stdout",
                self.daily_path().unwrap_or_default()
            );

            // The whole burst goes to stdout, since how much of it reached the file
            // is not known: a line twice rather than none.
            self.file = None;
        }

        if self.file.is_none() {
            let _ = self.output.write_all(&self.access_buffer);
        }

        self.access_buffer.clear();
    }

    /// The file of `date`, when access lines go to a file of the day.
    fn daily_path(&self) -> Option<String> {
        match &self.access {
            AccessTarget::Daily { prefix, .. } => Some(format!("{prefix}-{}.log", self.date)),
            _ => None,
        }
    }

    /// Says on stderr — which the worker master journals at ERROR — how many lines
    /// the queue had no room for since the last time it said so: lines lost without
    /// a word are a log that cannot be trusted.
    fn report_drops(&mut self) {
        if self.reported_at.elapsed() < DROP_REPORT_INTERVAL {
            return;
        }

        self.reported_at = Instant::now();

        let dropped = DROPPED.load(Ordering::Relaxed);

        if dropped > self.reported_drops {
            let _ = writeln!(
                self.errors,
                "log: {} line(s) dropped in the last minute; the writer could not keep up",
                dropped - self.reported_drops,
            );
        }

        self.reported_drops = dropped;
    }
}

fn run<O: Write, E: Write, D: Fn() -> String>(
    receiver: crossbeam_channel::Receiver<Message>,
    mut writer: Writer<O, E, D>,
) {
    loop {
        match receiver.recv_timeout(FLUSH_INTERVAL) {
            Ok(message) => {
                // A flush that arrives in the middle of a burst is answered once the
                // burst is written, not dropped: dropping it would release its caller
                // before the lines it waits for reach the fd.
                let mut flushed = writer.take(message);

                // Drain whatever else is queued before touching the fd, so a burst
                // costs one write rather than one per line.
                while flushed.is_none() && !writer.is_full() {
                    match receiver.try_recv() {
                        Ok(message) => flushed = writer.take(message),
                        Err(_) => break,
                    }
                }

                writer.write_out();

                if let Some(done) = flushed {
                    let _ = done.send(());
                }
            }
            Err(crossbeam_channel::RecvTimeoutError::Timeout) => writer.write_out(),
            Err(crossbeam_channel::RecvTimeoutError::Disconnected) => {
                writer.write_out();

                return;
            }
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
        let writer = std::thread::spawn(move || {
            run(
                receiver,
                Writer::new(output, Captured::default(), AccessTarget::Stdout, fixed_day),
            )
        });

        assert!(
            wait.recv_timeout(Duration::from_secs(5)).is_ok(),
            "the flush was dropped instead of answered"
        );
        assert_eq!(*captured.0.lock().unwrap(), b"one\ntwo\n".to_vec());

        drop(sender);

        writer.join().unwrap();
    }

    fn fixed_day() -> String {
        "2026-10-20".to_string()
    }

    /// A directory of its own under the system's temporary one, removed with the guard.
    struct TempDir(std::path::PathBuf);

    impl TempDir {
        fn new(name: &str) -> Self {
            let path = std::env::temp_dir()
                .join(format!("sconcur-log-{name}-{}", std::process::id()));

            let _ = std::fs::remove_dir_all(&path);

            std::fs::create_dir_all(&path).unwrap();

            TempDir(path)
        }

        fn prefix(&self) -> String {
            self.0.join("pool-access").to_string_lossy().into_owned()
        }

        fn read(&self, date: &str) -> String {
            std::fs::read_to_string(self.0.join(format!("pool-access-{date}.log")))
                .unwrap_or_default()
        }
    }

    impl Drop for TempDir {
        fn drop(&mut self) {
            let _ = std::fs::remove_dir_all(&self.0);
        }
    }

    /// Writes what the messages ask for, then everything buffered, as the thread does
    /// between bursts.
    fn feed<O: Write, E: Write, D: Fn() -> String>(
        writer: &mut Writer<O, E, D>,
        messages: Vec<Message>,
    ) {
        for message in messages {
            writer.take(message);
        }

        writer.write_out();
    }

    #[test]
    fn access_lines_go_to_the_file_of_the_day_signed_by_their_worker_and_the_rest_to_stdout() {
        let directory = TempDir::new("daily");
        let output = Captured::default();

        let mut writer = Writer::new(
            output.clone(),
            Captured::default(),
            AccessTarget::parse(&directory.prefix(), "http:3", 4711),
            fixed_day,
        );

        feed(
            &mut writer,
            vec![
                Message::Access("2026-10-20T10:00:00.000000 GET / 200 0.10ms\n".to_string()),
                Message::Line("amqp: a diagnostic\n".to_string()),
                Message::Access("2026-10-20T10:00:01.000000 GET /a 404 0.20ms\n".to_string()),
            ],
        );

        assert_eq!(
            directory.read("2026-10-20"),
            "2026-10-20T10:00:00.000000 GET / 200 0.10ms http#3 pid=4711\n\
             2026-10-20T10:00:01.000000 GET /a 404 0.20ms http#3 pid=4711\n",
        );
        assert_eq!(*output.0.lock().unwrap(), b"amqp: a diagnostic\n".to_vec());
    }

    /// The date is asked at every write, so the first burst after midnight opens the
    /// next file, and the previous one is left as it was for the master to prune.
    #[test]
    fn a_new_day_opens_a_new_file() {
        let directory = TempDir::new("rollover");
        let day = Arc::new(Mutex::new("2026-10-20".to_string()));
        let clock = day.clone();

        let mut writer = Writer::new(
            Captured::default(),
            Captured::default(),
            AccessTarget::parse(&directory.prefix(), "http:0", 1),
            move || clock.lock().unwrap().clone(),
        );

        feed(&mut writer, vec![Message::Access("before midnight\n".to_string())]);

        *day.lock().unwrap() = "2026-10-21".to_string();

        feed(&mut writer, vec![Message::Access("after midnight\n".to_string())]);

        assert_eq!(directory.read("2026-10-20"), "before midnight http#0 pid=1\n");
        assert_eq!(directory.read("2026-10-21"), "after midnight http#0 pid=1\n");
    }

    #[test]
    fn off_drops_the_access_lines_and_keeps_the_rest() {
        let output = Captured::default();

        let mut writer = Writer::new(
            output.clone(),
            Captured::default(),
            AccessTarget::parse("off", "", 1),
            fixed_day,
        );

        feed(
            &mut writer,
            vec![
                Message::Access("GET / 200\n".to_string()),
                Message::Line("kept\n".to_string()),
            ],
        );

        assert_eq!(*output.0.lock().unwrap(), b"kept\n".to_vec());
    }

    #[test]
    fn unset_sends_the_access_lines_to_stdout_as_they_are() {
        let output = Captured::default();

        let mut writer = Writer::new(
            output.clone(),
            Captured::default(),
            AccessTarget::parse("", "http:0", 1),
            fixed_day,
        );

        feed(&mut writer, vec![Message::Access("GET / 200\n".to_string())]);

        assert_eq!(*output.0.lock().unwrap(), b"GET / 200\n".to_vec());
    }

    /// A file that cannot be opened loses no line: they go to stdout, and stderr says
    /// why — once for the day, not once a burst.
    #[test]
    fn a_file_that_cannot_be_opened_is_reported_once_and_its_lines_go_to_stdout() {
        let output = Captured::default();
        let errors = Captured::default();

        let mut writer = Writer::new(
            output.clone(),
            errors.clone(),
            AccessTarget::parse("/proc/sconcur-no-such-directory/access", "http:0", 1),
            fixed_day,
        );

        feed(&mut writer, vec![Message::Access("first\n".to_string())]);
        feed(&mut writer, vec![Message::Access("second\n".to_string())]);

        let reported = String::from_utf8(errors.0.lock().unwrap().clone()).unwrap();

        assert_eq!(reported.matches("access log: cannot open").count(), 1, "{reported}");
        assert_eq!(
            *output.0.lock().unwrap(),
            b"first http#0 pid=1\nsecond http#0 pid=1\n".to_vec(),
        );
    }

    /// A file that stops taking lines loses none of them: they go to stdout for the
    /// rest of the day, stderr says why once, and the next day's file is opened
    /// anew. /dev/full is the full disk: every write to it fails with ENOSPC.
    #[test]
    fn a_file_that_cannot_be_written_is_reported_once_and_its_lines_go_to_stdout() {
        let directory = TempDir::new("full");

        let full = directory.0.join("pool-access-2026-10-20.log");

        std::os::unix::fs::symlink("/dev/full", full).unwrap();

        let day = Arc::new(Mutex::new("2026-10-20".to_string()));
        let clock = day.clone();
        let output = Captured::default();
        let errors = Captured::default();

        let mut writer = Writer::new(
            output.clone(),
            errors.clone(),
            AccessTarget::parse(&directory.prefix(), "http:0", 1),
            move || clock.lock().unwrap().clone(),
        );

        feed(&mut writer, vec![Message::Access("first\n".to_string())]);
        feed(&mut writer, vec![Message::Access("second\n".to_string())]);

        *day.lock().unwrap() = "2026-10-21".to_string();

        feed(&mut writer, vec![Message::Access("next day\n".to_string())]);

        let reported = String::from_utf8(errors.0.lock().unwrap().clone()).unwrap();

        assert_eq!(reported.matches("access log: cannot write").count(), 1, "{reported}");
        assert_eq!(
            *output.0.lock().unwrap(),
            b"first http#0 pid=1\nsecond http#0 pid=1\n".to_vec(),
        );
        assert_eq!(directory.read("2026-10-21"), "next day http#0 pid=1\n");
    }

    /// The process export's exit flushes right before _exit, so a flush behind a
    /// full queue must wait for room rather than be dropped, and its caller for the
    /// lines ahead of it. The writer starts late, as one held up by a slow disk
    /// takes the queue.
    #[test]
    fn a_flush_behind_a_full_queue_waits_for_the_lines_ahead_of_it() {
        let (sender, receiver) = crossbeam_channel::bounded::<Message>(QUEUE_SIZE);
        let logger = Logger::new();

        assert!(logger.sender.set(Some(sender)).is_ok());

        // Exactly full: a line past it would be dropped and counted, which another
        // test reads.
        for index in 0..QUEUE_SIZE {
            logger.write(format!("{index}\n"));
        }

        assert!(logger.sender.get().unwrap().as_ref().unwrap().is_full());

        let captured = Captured::default();
        let output = captured.clone();
        let writer = std::thread::spawn(move || {
            std::thread::sleep(Duration::from_millis(200));

            run(
                receiver,
                Writer::new(output, Captured::default(), AccessTarget::Stdout, fixed_day),
            )
        });

        logger.flush();

        let written = captured
            .0
            .lock()
            .unwrap()
            .iter()
            .filter(|&&byte| byte == b'\n')
            .count();

        assert_eq!(
            written, QUEUE_SIZE,
            "the flush returned before the lines ahead of it were written"
        );

        drop(logger);

        writer.join().unwrap();
    }

    #[test]
    fn lines_the_queue_dropped_are_reported_on_stderr() {
        let errors = Captured::default();

        let mut writer = Writer::new(
            Captured::default(),
            errors.clone(),
            AccessTarget::Stdout,
            fixed_day,
        );

        writer.reported_drops = DROPPED.fetch_add(3, Ordering::Relaxed);
        writer.reported_at = Instant::now() - DROP_REPORT_INTERVAL;

        // Taken as the reference: nothing else in the tests drops a line, but should
        // something, the count reported is still against what was last reported.
        let expected = DROPPED.load(Ordering::Relaxed) - writer.reported_drops;

        writer.write_out();

        let reported = String::from_utf8(errors.0.lock().unwrap().clone()).unwrap();

        assert!(
            reported.contains(&format!("log: {expected} line(s) dropped in the last minute")),
            "{reported}"
        );
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
