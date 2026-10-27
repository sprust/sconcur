//! The process metrics every snapshot carries, read from /proc rather than from
//! any runtime API — so they measure the whole worker, PHP included, and not
//! just this half of it.

use std::time::{Duration, Instant};

use super::Memory;

/// USER_HZ — the unit of the utime/stime fields in /proc/self/stat. Fixed at 100
/// on virtually every Linux build (the kernel default), so it is used directly
/// rather than reaching for `sysconf`.
const CLOCK_TICKS_PER_SECOND: f64 = 100.0;

/// How often a worker reads its memory and CPU. A snapshot goes out every push
/// interval (a second by default), because the workload counters and the
/// collector's hung check live on it; these two readings are repeated in between.
/// They describe a process over minutes, and the proportional share is the one
/// reading that costs: the kernel walks the page tables to answer it.
pub const SAMPLE_INTERVAL: Duration = Duration::from_secs(5);

/// The memory and CPU a snapshot carries, read every `SAMPLE_INTERVAL` and
/// repeated by the pushes in between. The CPU percentage is the rate over the
/// time since the previous reading, so it covers the whole interval rather than
/// the last push's second.
pub struct ProcessSample {
    pub memory: Memory,
    pub cpu_percent: f64,
    cpu: CpuSampler,
    sampled_at: Option<Instant>,
}

impl ProcessSample {
    pub fn new() -> Self {
        ProcessSample {
            memory: Memory::default(),
            cpu_percent: 0.0,
            cpu: CpuSampler::new(),
            sampled_at: None,
        }
    }

    /// Reads memory and CPU afresh when a reading is due at `now`. Memory is read
    /// on the blocking pool (see `read_memory`); a failed read (the closure
    /// panicked) reports zeros rather than dropping the snapshot.
    pub async fn refresh_if_due(&mut self, now: Instant, push_interval: Duration) {
        if !self.is_due(now, push_interval) {
            return;
        }

        self.memory = tokio::task::spawn_blocking(read_memory)
            .await
            .unwrap_or_default();
        self.cpu_percent = self.cpu.sample(now);
        self.sampled_at = Some(now);
    }

    /// Whether a push at `now`, made every `push_interval`, reads afresh. Half a
    /// push interval of slack, so a tick landing a hair before the mark does not
    /// put the reading off to the next tick and stretch 5 s into 6.
    fn is_due(&self, now: Instant, push_interval: Duration) -> bool {
        let Some(sampled_at) = self.sampled_at else {
            return true;
        };

        now.saturating_duration_since(sampled_at) + push_interval / 2 >= SAMPLE_INTERVAL
    }
}

/// The worker's memory, PHP and extension together: the resident set size, and
/// its proportional share.
///
/// There is deliberately no split between the two. Attributing a resident page
/// to one side would need a tracking global allocator, which is an atomic on
/// every allocation this core makes; the fields that used to carry the split
/// reported a zero and a copy of the total, which is worse than not reporting
/// it.
///
/// Reading the proportional share walks the process's page tables under its
/// mmap lock: 0.2 ms for a 60 MB worker, a few milliseconds for a 500 MB one.
/// `ProcessSample` runs this on the blocking pool for that reason, so the
/// runtime's only worker thread does not stand still for it.
pub fn read_memory() -> Memory {
    let rollup = std::fs::read_to_string("/proc/self/smaps_rollup").unwrap_or_default();

    Memory {
        rss_bytes: read_rss_bytes(),
        pss_bytes: kib_field_bytes(&rollup, "Pss:"),
        swap_pss_bytes: kib_field_bytes(&rollup, "SwapPss:"),
    }
}

/// Reads the resident set size from /proc/self/status (the VmRSS line, in kB).
/// Answers 0 when it cannot be read.
fn read_rss_bytes() -> i64 {
    let Ok(contents) = std::fs::read_to_string("/proc/self/status") else {
        return 0;
    };

    kib_field_bytes(&contents, "VmRSS:")
}

/// The value of the `<prefix> <n> kB` line of a /proc file, in bytes. Answers 0
/// when the line is missing or does not parse.
///
/// The prefix carries its colon, so `Pss:` does not take the `Pss_Anon:` line of
/// smaps_rollup.
fn kib_field_bytes(contents: &str, prefix: &str) -> i64 {
    for line in contents.lines() {
        let Some(rest) = line.strip_prefix(prefix) else {
            continue;
        };

        return rest
            .split_whitespace()
            .next()
            .and_then(|value| value.parse::<i64>().ok())
            .map(|kilobytes| kilobytes * 1024)
            .unwrap_or(0);
    }

    0
}

/// Turns the monotonically growing CPU time of the process into a rolling
/// percentage: each sample diffs the consumed CPU ticks against wall time since
/// the previous one. The first sample only seeds the baseline and answers 0.
pub struct CpuSampler {
    previous: Option<(f64, Instant)>,
}

impl CpuSampler {
    pub fn new() -> Self {
        CpuSampler { previous: None }
    }

    /// `now` is passed in so one clock reading covers both the CPU percentage and
    /// the snapshot's own timestamp.
    pub fn sample(&mut self, now: Instant) -> f64 {
        let ticks = read_process_cpu_ticks();

        let Some((previous_ticks, previous_wall)) = self.previous.replace((ticks, now)) else {
            return 0.0;
        };

        let wall_seconds = now.duration_since(previous_wall).as_secs_f64();

        if wall_seconds <= 0.0 {
            return 0.0;
        }

        (ticks - previous_ticks) / CLOCK_TICKS_PER_SECOND / wall_seconds * 100.0
    }
}

/// Reads utime+stime (in clock ticks) from /proc/self/stat.
///
/// The comm field (the 2nd) may hold spaces and parentheses, so the fields are
/// counted from after the last ')': state is then field 3, which puts utime at
/// field 14 (index 11) and stime at field 15 (index 12). Answers 0 on any parse
/// failure.
fn read_process_cpu_ticks() -> f64 {
    let Ok(contents) = std::fs::read_to_string("/proc/self/stat") else {
        return 0.0;
    };

    let Some(last_paren) = contents.rfind(')') else {
        return 0.0;
    };

    let Some(tail) = contents.get(last_paren + 1..) else {
        return 0.0;
    };

    let fields: Vec<&str> = tail.split_whitespace().collect();

    if fields.len() < 13 {
        return 0.0;
    }

    // The state field (the 3rd of the line) is index 0 of this tail, so utime and
    // stime — fields 14 and 15 — land at indexes 11 and 12.
    let utime = fields[11].parse::<f64>();
    let stime = fields[12].parse::<f64>();

    match (utime, stime) {
        (Ok(utime), Ok(stime)) => utime + stime,
        _ => 0.0,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const SMAPS_ROLLUP: &str = "\
5895d9c1f000-7ffce2128000 ---p 00000000 00:00 0                          [rollup]
Rss:                7588 kB
Pss:                2791 kB
Pss_Dirty:          1384 kB
Pss_Anon:           1384 kB
Pss_File:           1407 kB
Swap:                512 kB
SwapPss:             256 kB
Locked:                0 kB
";

    #[test]
    fn reads_the_proportional_share_from_smaps_rollup() {
        assert_eq!(2791 * 1024, kib_field_bytes(SMAPS_ROLLUP, "Pss:"));
        assert_eq!(256 * 1024, kib_field_bytes(SMAPS_ROLLUP, "SwapPss:"));
    }

    #[test]
    fn answers_zero_for_a_missing_or_unreadable_line() {
        assert_eq!(0, kib_field_bytes(SMAPS_ROLLUP, "VmRSS:"));
        assert_eq!(0, kib_field_bytes("Pss: lots kB\n", "Pss:"));
        assert_eq!(0, kib_field_bytes("", "Pss:"));
    }

    #[test]
    fn reads_afresh_every_sample_interval() {
        let start = Instant::now();
        let second = Duration::from_secs(1);
        let mut sample = ProcessSample::new();

        assert!(sample.is_due(start, second));

        sample.sampled_at = Some(start);

        assert!(!sample.is_due(start + Duration::from_secs(4), second));
        // A tick a millisecond early still reads, instead of waiting a whole second.
        assert!(sample.is_due(start + Duration::from_millis(4999), second));
        assert!(sample.is_due(start + Duration::from_secs(5), second));
    }

    /// A push interval longer than the sample interval reads on every push.
    #[test]
    fn reads_on_every_push_slower_than_the_sample_interval() {
        let start = Instant::now();
        let mut sample = ProcessSample::new();

        sample.sampled_at = Some(start);

        assert!(sample.is_due(start + Duration::from_secs(10), Duration::from_secs(10)));
    }

    /// The process's own reading: a running process has resident pages, so both
    /// its resident set and its share of it are above zero.
    #[test]
    fn reads_the_own_process() {
        let memory = read_memory();

        assert!(memory.rss_bytes > 0);
        assert!(memory.pss_bytes > 0);
        assert!(memory.swap_pss_bytes >= 0);
    }
}
