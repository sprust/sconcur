//! The worker's end of the heartbeat pipe: the one its master reads to learn
//! that the worker's PHP thread is still moving, that the worker is up, and
//! that it is leaving at its own limit — one byte each (`Mark`).
//!
//! Held here rather than by a PHP stream. php://fd opens a copy made by
//! dup(2), which leaves close-on-exec behind, so a stream on the pipe was
//! inherited by everything the worker ran; a SConcur program among them would
//! also find the variable naming it and mark its parent alive, which is the one
//! reading a watchdog must never be handed by anyone else. Here the descriptor
//! keeps the flag, the variable leaves the environment, and PHP only asks
//! whether there is a heartbeat and hands over the marks.
//!
//! Process-level state, like the signals being watched: one descriptor or
//! none. An exec'd worker finds it in its environment as the module starts
//! (`settle`, through `claim`); a forked one is handed the end its fork made
//! (`reset_in_child`) and never keeps the one its parent held.

use std::os::fd::RawFd;
use std::sync::atomic::{AtomicI32, Ordering};

use libc::c_int;

use super::{Error, claim};
use crate::dto::Value;

/// The variable an exec'd worker finds the number of its heartbeat descriptor
/// in. PHP: SConcur\Process\ProcessControl::HEARTBEAT_FD_ENVIRONMENT_NAME.
const ENVIRONMENT_NAME: &str = "SCONCUR_HEARTBEAT_FD";

/// What a worker tells its master, one byte each.
/// PHP: SConcur\Process\HeartbeatMarkEnum.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Mark {
    /// The PHP thread came back to the scheduler.
    Alive = 1,
    /// The worker is up and doing its work.
    Ready = 2,
    /// The worker is stopping because it reached its own limit.
    Recycling = 3,
}

impl Mark {
    fn from_number(number: i64) -> Option<Mark> {
        match number {
            1 => Some(Mark::Alive),
            2 => Some(Mark::Ready),
            3 => Some(Mark::Recycling),
            _ => None,
        }
    }
}

/// Not looked for yet.
const UNSETTLED: c_int = -2;

/// Looked for, and there is none: a process no master started.
const NONE: c_int = -1;

/// The descriptor the marks go to, NONE or UNSETTLED. Only the PHP thread
/// touches it.
static DESCRIPTOR: AtomicI32 = AtomicI32::new(UNSETTLED);

/// The heartbeat of this process: looked for in the environment the first time
/// (see `claim`), and the same answer from then on.
pub fn settle() -> Option<RawFd> {
    let mut descriptor = DESCRIPTOR.load(Ordering::Relaxed);

    if descriptor == UNSETTLED {
        descriptor = claim::writable_pipe(ENVIRONMENT_NAME, true).unwrap_or(NONE);

        DESCRIPTOR.store(descriptor, Ordering::Relaxed);
    }

    (descriptor >= 0).then_some(descriptor)
}

/// Whether this process has a heartbeat: whether a master supervises it.
pub fn present() -> Value {
    Value::Bool(settle().is_some())
}

/// Writes one mark, and answers whether it was written. Not written is not an
/// error: there is no heartbeat, the pipe is full (the master stopped reading,
/// and the next mark will do), the master is gone (EPIPE — PHP CLI ignores
/// SIGPIPE, so the write fails rather than ending the worker) or the descriptor
/// was closed.
pub fn mark(number: i64) -> Result<Value, Error> {
    let mark = Mark::from_number(number).ok_or_else(|| Error::Failed(format!("unknown heartbeat mark {number}")))?;

    let Some(descriptor) = settle() else {
        return Ok(Value::Bool(false));
    };

    write_mark(descriptor, mark).map(Value::Bool)
}

/// In a freshly forked child: the write end its fork made for it, or none.
/// Never what the parent held — that is the parent's own, and was pointed at
/// /dev/null with the parent's other descriptors. False when the end cannot be
/// made non-blocking.
pub fn reset_in_child(descriptor: Option<RawFd>) -> bool {
    let Some(descriptor) = descriptor else {
        DESCRIPTOR.store(NONE, Ordering::Relaxed);

        return true;
    };

    // A master that stopped reading must slow nothing down: a full pipe drops
    // the mark instead.
    if !claim::add_flag(descriptor, libc::F_GETFL, libc::F_SETFL, libc::O_NONBLOCK) {
        return false;
    }

    DESCRIPTOR.store(descriptor, Ordering::Relaxed);

    true
}

fn write_mark(descriptor: RawFd, mark: Mark) -> Result<bool, Error> {
    let byte = mark as u8;

    loop {
        let written = unsafe { libc::write(descriptor, (&byte as *const u8).cast(), 1) };

        if written >= 0 {
            return Ok(written == 1);
        }

        let error = std::io::Error::last_os_error();

        match error.raw_os_error() {
            Some(libc::EINTR) => continue,
            Some(code)
                if code == libc::EAGAIN || code == libc::EWOULDBLOCK || code == libc::EPIPE || code == libc::EBADF =>
            {
                return Ok(false);
            }
            _ => return Err(Error::Failed(format!("heartbeat write: {error}"))),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::super::claim::test_pipes::*;
    use super::*;

    /// What an exec'd worker's master opened is taken: close-on-exec, so nothing
    /// the worker starts inherits it, non-blocking, and out of the environment.
    #[test]
    fn the_pipe_an_exec_d_worker_is_handed_is_claimed_from_its_environment() {
        let (read, write) = plain_pipe();

        let claimed = claim_named("SCONCUR_TEST_HEARTBEAT_CLAIMED", &write.to_string(), true);

        assert_eq!(claimed, Some(write));
        assert!(has_flag(write, libc::F_GETFD, libc::FD_CLOEXEC), "a program the worker starts inherits it");
        assert!(has_flag(write, libc::F_GETFL, libc::O_NONBLOCK), "a full pipe would stall the worker");

        assert_eq!(write_mark(write, Mark::Ready).ok(), Some(true));
        assert_eq!(read_available(read), vec![2]);

        close(&[read, write]);
    }

    /// A pipe the master stopped reading drops the mark rather than block.
    #[test]
    fn a_full_pipe_drops_the_mark() {
        let (read, write) = plain_pipe();

        assert!(claim::add_flag(write, libc::F_GETFL, libc::F_SETFL, libc::O_NONBLOCK));

        let chunk = [0_u8; 4096];

        while unsafe { libc::write(write, chunk.as_ptr().cast(), chunk.len()) } > 0 {}

        assert_eq!(write_mark(write, Mark::Alive).ok(), Some(false));

        close(&[read, write]);
    }

    /// A master that is gone: EPIPE, with SIGPIPE ignored — by PHP CLI in a
    /// worker, and by the Rust runtime here, which is checked first rather than
    /// assumed, since the default action would end the test binary.
    #[test]
    fn a_mark_for_a_master_that_is_gone_is_dropped() {
        let mut current: libc::sigaction = unsafe { std::mem::zeroed() };

        unsafe { libc::sigaction(libc::SIGPIPE, std::ptr::null(), &mut current) };

        assert_eq!(current.sa_sigaction, libc::SIG_IGN, "SIGPIPE is not ignored in this process");

        let (read, write) = plain_pipe();

        close(&[read]);

        assert_eq!(write_mark(write, Mark::Alive).ok(), Some(false));

        close(&[write]);
    }

    #[test]
    fn a_mark_on_a_closed_descriptor_is_dropped() {
        assert_eq!(write_mark(c_int::MAX, Mark::Alive).ok(), Some(false));
    }

    /// One test for everything that goes through the process's own heartbeat,
    /// so no other test sees it change under it.
    #[test]
    fn the_heartbeat_a_fork_hands_over_is_the_one_marked() {
        assert!(reset_in_child(None));

        assert_eq!(settle(), None);
        assert!(matches!(mark(1), Ok(Value::Bool(false))), "marked with no heartbeat");

        let (read, write) = plain_pipe();

        assert!(reset_in_child(Some(write)));

        assert_eq!(settle(), Some(write));
        assert!(has_flag(write, libc::F_GETFL, libc::O_NONBLOCK), "a full pipe would stall the worker");
        assert!(matches!(present(), Value::Bool(true)));

        assert!(matches!(mark(1), Ok(Value::Bool(true))));
        assert!(matches!(mark(3), Ok(Value::Bool(true))));
        assert!(matches!(mark(4), Err(Error::Failed(_))), "a byte that is no mark was written");

        assert_eq!(read_available(read), vec![1, 3]);

        // Back to what a process no master started has, for whatever runs next.
        assert!(reset_in_child(None));

        close(&[read, write]);
    }
}
