//! The process metrics every snapshot carries, read from /proc rather than from
//! any runtime API — so they measure the whole worker, PHP included, and not
//! just this half of it.

use std::time::Instant;

use super::Memory;

/// USER_HZ — the unit of the utime/stime fields in /proc/self/stat. Fixed at 100
/// on virtually every Linux build (the kernel default), so it is used directly
/// rather than reaching for `sysconf`.
const CLOCK_TICKS_PER_SECOND: f64 = 100.0;

/// The worker's resident set size, PHP and extension together.
///
/// There is deliberately no split between the two. Attributing a resident page
/// to one side would need a tracking global allocator, which is an atomic on
/// every allocation this core makes; the fields that used to carry the split
/// reported a zero and a copy of the total, which is worse than not reporting
/// it.
pub fn read_memory() -> Memory {
    Memory {
        rss_bytes: read_rss_bytes(),
    }
}

/// Reads the resident set size from /proc/self/status (the VmRSS line, in kB).
/// Answers 0 when it cannot be read.
fn read_rss_bytes() -> i64 {
    let Ok(contents) = std::fs::read_to_string("/proc/self/status") else {
        return 0;
    };

    for line in contents.lines() {
        let Some(rest) = line.strip_prefix("VmRSS:") else {
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
