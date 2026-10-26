//! How many threads this process runs: the question a fork has to ask first,
//! because only the forking thread survives into the child, and whatever any
//! other one held at that moment stays held there for good.

/// The thread count, or None where the platform has no cheap way to say. On
/// Linux it is a field of /proc/self/stat; elsewhere the fork's check falls back
/// to asking whether this extension started any.
#[cfg(target_os = "linux")]
pub fn count() -> Option<usize> {
    let stat = std::fs::read_to_string("/proc/self/stat").ok()?;

    parse_stat(&stat)
}

#[cfg(not(target_os = "linux"))]
pub fn count() -> Option<usize> {
    None
}

/// Field 20 of a stat line, `num_threads`. The fields are counted from the last
/// ')', because the second one — the command name, in parentheses — may hold
/// spaces and parentheses of its own.
#[cfg(target_os = "linux")]
fn parse_stat(stat: &str) -> Option<usize> {
    let after_name = &stat[stat.rfind(')')? + 1..];

    // Field 3 (the state) is the first one after the name, so field 20 is the
    // eighteenth.
    after_name.split_whitespace().nth(17)?.parse().ok()
}

#[cfg(all(test, target_os = "linux"))]
mod tests {
    use super::*;

    /// A command name is whatever the process set, and one with a ") 3 (" in it
    /// must not shift the fields read after it.
    #[test]
    fn the_thread_count_is_read_past_a_name_that_holds_parentheses() {
        let stat = "4242 (php ) 3 (x) S 1 4242 4242 0 -1 4194560 1 2 3 4 5 6 7 8 20 0 7 0 99 1000 50";

        assert_eq!(parse_stat(stat), Some(7));
    }

    #[test]
    fn the_test_process_reports_threads_of_its_own() {
        assert!(count().is_some_and(|threads| threads >= 1));
    }
}
