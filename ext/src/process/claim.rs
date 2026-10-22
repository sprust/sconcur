//! A pipe a parent hands a process it starts, under a number it names in an
//! environment variable: an exec'd worker's heartbeat (`heartbeat`), a fork
//! server's events pipe (`events`). Both are claimed as the module starts, before
//! PHP runs a line of the script and before any thread exists.
//!
//! Claimed here rather than opened by PHP: php://fd opens its stream on a copy
//! made by dup(2), which leaves close-on-exec behind, so a stream on the pipe was
//! inherited by everything the process ran.

use std::os::fd::RawFd;

use libc::c_int;

/// The descriptor the parent named in `variable`, made close-on-exec — and
/// non-blocking when `non_blocking` — or None.
///
/// The variable leaves the environment whatever it says: it was addressed to
/// this process, and a program it starts must not find it. Only a pipe open for
/// writing above stderr is taken. Anything else — a number nothing is open on,
/// a file, a socket, a read end — did not come from the parent, and what would
/// be written there would land in whatever it is.
pub(super) fn writable_pipe(variable: &str, non_blocking: bool) -> Option<RawFd> {
    let value = std::env::var_os(variable)?;

    // Safety: unsetenv is unsafe beside another thread reading the environment.
    // The module start calls this before any thread exists; past it, the
    // variable is gone and this line is not reached again.
    unsafe { std::env::remove_var(variable) };

    let descriptor: RawFd = value.to_str()?.parse().ok()?;

    if descriptor <= 2 || !is_writable_pipe(descriptor) {
        return None;
    }

    // Close-on-exec, so nothing this process starts inherits the pipe.
    if !add_flag(descriptor, libc::F_GETFD, libc::F_SETFD, libc::FD_CLOEXEC) {
        return None;
    }

    if non_blocking && !add_flag(descriptor, libc::F_GETFL, libc::F_SETFL, libc::O_NONBLOCK) {
        return None;
    }

    Some(descriptor)
}

fn is_writable_pipe(descriptor: RawFd) -> bool {
    let mut status: libc::stat = unsafe { std::mem::zeroed() };

    if unsafe { libc::fstat(descriptor, &mut status) } != 0 || status.st_mode & libc::S_IFMT != libc::S_IFIFO {
        return false;
    }

    let flags = unsafe { libc::fcntl(descriptor, libc::F_GETFL) };

    flags >= 0 && matches!(flags & libc::O_ACCMODE, libc::O_WRONLY | libc::O_RDWR)
}

/// Adds a flag through the fcntl pair that reads and sets it.
pub(super) fn add_flag(descriptor: RawFd, get: c_int, set: c_int, flag: c_int) -> bool {
    let flags = unsafe { libc::fcntl(descriptor, get) };

    flags >= 0 && unsafe { libc::fcntl(descriptor, set, flags | flag) } == 0
}

/// Pipes and their flags, for the tests of the modules that claim one.
#[cfg(test)]
pub(super) mod test_pipes {
    use std::os::fd::RawFd;

    use libc::c_int;

    /// A pipe without close-on-exec or O_NONBLOCK, as proc_open hands a child the
    /// descriptors it places under a number of their own.
    pub fn plain_pipe() -> (RawFd, RawFd) {
        let mut ends: [c_int; 2] = [-1, -1];

        assert_eq!(unsafe { libc::pipe(ends.as_mut_ptr()) }, 0);

        (ends[0], ends[1])
    }

    pub fn has_flag(descriptor: RawFd, get: c_int, flag: c_int) -> bool {
        unsafe { libc::fcntl(descriptor, get) & flag != 0 }
    }

    pub fn read_available(descriptor: RawFd) -> Vec<u8> {
        let mut buffer = [0_u8; 256];

        let read = unsafe { libc::read(descriptor, buffer.as_mut_ptr().cast(), buffer.len()) };

        buffer[..read.max(0) as usize].to_vec()
    }

    pub fn close(descriptors: &[RawFd]) {
        for &descriptor in descriptors {
            unsafe { libc::close(descriptor) };
        }
    }

    /// Claims through `variable`, and checks it is gone from the environment
    /// whatever it named. Each test uses a variable of its own: the tests run on
    /// threads of one process, and share its environment.
    pub fn claim_named(variable: &str, value: &str, non_blocking: bool) -> Option<RawFd> {
        unsafe { std::env::set_var(variable, value) };

        let claimed = super::writable_pipe(variable, non_blocking);

        assert!(std::env::var_os(variable).is_none(), "the variable stays for whatever {value} is");

        claimed
    }
}

#[cfg(test)]
mod tests {
    use super::test_pipes::*;
    use super::*;

    /// A pipe claimed blocking keeps blocking — a fork server's event must reach
    /// its master whole — and is close-on-exec all the same.
    #[test]
    fn a_pipe_claimed_blocking_is_only_made_close_on_exec() {
        let (read, write) = plain_pipe();

        let claimed = claim_named("SCONCUR_TEST_CLAIM_BLOCKING", &write.to_string(), false);

        assert_eq!(claimed, Some(write));
        assert!(has_flag(write, libc::F_GETFD, libc::FD_CLOEXEC), "a program the process starts inherits it");
        assert!(!has_flag(write, libc::F_GETFL, libc::O_NONBLOCK), "a blocking pipe was made non-blocking");

        close(&[read, write]);
    }

    /// Only a pipe open for writing above stderr is taken; the variable goes
    /// whatever it named.
    #[test]
    fn only_a_pipe_open_for_writing_is_claimed() {
        let (read, write) = plain_pipe();

        let file = std::fs::OpenOptions::new().write(true).open("/dev/null").unwrap();
        let file_descriptor = std::os::fd::AsRawFd::as_raw_fd(&file);

        for (value, what) in [
            (read.to_string(), "the read end"),
            (file_descriptor.to_string(), "a file open for writing"),
            (c_int::MAX.to_string(), "a number nothing is open on"),
            ("2".to_string(), "stderr"),
            ("three".to_string(), "not a number"),
        ] {
            assert_eq!(claim_named("SCONCUR_TEST_CLAIM_REFUSED", &value, true), None, "{what} was claimed");
        }

        assert!(!has_flag(read, libc::F_GETFD, libc::FD_CLOEXEC), "a refused descriptor was changed");

        close(&[read, write]);
    }
}
