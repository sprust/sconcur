//! The fork server's end of its events pipe: the one its master reads to learn
//! what the fork server's workers did — a slot up, a slot gone, the live pids —
//! one JSON object per line, which the PHP side builds (`ForkServer::emit`).
//!
//! Held here rather than by a PHP stream, for the reason `claim` gives: a stream
//! on the pipe was a copy without close-on-exec, which every worker kept and
//! every program a worker or the bootstrap started inherited. Anything among
//! them could write into the master's view of the group, and a write without a
//! newline would glue onto the next real event and lose it. Claimed as the
//! module starts, the descriptor is close-on-exec before the bootstrap runs.
//!
//! Process-level state: the descriptor or none. A worker the fork server forks
//! never has one (`reset_in_child`): the fork points the parent's descriptors at
//! /dev/null, and this one with them.

use std::os::fd::RawFd;
use std::sync::atomic::{AtomicI32, Ordering};

use libc::c_int;

use super::{Error, claim};
use crate::dto::Value;

/// The variable a fork server finds the number of its events descriptor in.
/// PHP: SConcur\Worker\ForkServer::EVENTS_FD_ENVIRONMENT_NAME.
const ENVIRONMENT_NAME: &str = "SCONCUR_FORK_SERVER_EVENTS_FD";

/// Not looked for yet.
const UNSETTLED: c_int = -2;

/// Looked for, and there is none: a process that is no fork server.
const NONE: c_int = -1;

/// The descriptor events go to, NONE or UNSETTLED. Only the PHP thread touches
/// it.
static DESCRIPTOR: AtomicI32 = AtomicI32::new(UNSETTLED);

/// The events pipe of this process: looked for in the environment the first
/// time, and the same answer from then on. Blocking, unlike the heartbeat: an
/// event that does not reach the master whole is worse than one that waits.
pub fn settle() -> Option<RawFd> {
    let mut descriptor = DESCRIPTOR.load(Ordering::Relaxed);

    if descriptor == UNSETTLED {
        descriptor = claim::writable_pipe(ENVIRONMENT_NAME, false).unwrap_or(NONE);

        DESCRIPTOR.store(descriptor, Ordering::Relaxed);
    }

    (descriptor >= 0).then_some(descriptor)
}

/// Whether this process has an events pipe: whether it is a fork server.
pub fn present() -> Value {
    Value::Bool(settle().is_some())
}

/// Writes one event line, whole, and answers whether it was written. Not
/// written is not an error: there is no events pipe, or the master is gone
/// (EPIPE — PHP CLI ignores SIGPIPE, so the write fails rather than ending the
/// fork server, whose orphan check then stops it).
pub fn write(line: &str) -> Result<Value, Error> {
    let Some(descriptor) = settle() else {
        return Ok(Value::Bool(false));
    };

    write_all(descriptor, line.as_bytes()).map(Value::Bool)
}

/// In a freshly forked child: no events pipe. A worker is not the fork server.
pub fn reset_in_child() {
    DESCRIPTOR.store(NONE, Ordering::Relaxed);
}

fn write_all(descriptor: RawFd, mut bytes: &[u8]) -> Result<bool, Error> {
    while !bytes.is_empty() {
        let written = unsafe { libc::write(descriptor, bytes.as_ptr().cast(), bytes.len()) };

        if written > 0 {
            bytes = &bytes[written as usize..];

            continue;
        }

        let error = std::io::Error::last_os_error();

        match error.raw_os_error() {
            Some(libc::EINTR) => continue,
            Some(code) if code == libc::EPIPE || code == libc::EBADF => return Ok(false),
            _ => return Err(Error::Failed(format!("fork server events write: {error}"))),
        }
    }

    Ok(true)
}

#[cfg(test)]
mod tests {
    use super::super::claim::test_pipes::*;
    use super::*;

    /// A line longer than a single write may take arrives whole: a pipe takes at
    /// most its buffer at once, and the rest follows.
    #[test]
    fn a_line_reaches_the_reader_whole() {
        let (read, write) = plain_pipe();

        let line = format!("{}\n", "x".repeat(200_000));

        let reader = std::thread::spawn(move || {
            let mut received = Vec::new();

            while received.last() != Some(&b'\n') {
                let chunk = read_available(read);

                assert!(!chunk.is_empty(), "the writer stopped half way");

                received.extend(chunk);
            }

            close(&[read]);

            received.len()
        });

        assert_eq!(write_all(write, line.as_bytes()).ok(), Some(true));
        assert_eq!(reader.join().unwrap(), line.len());

        close(&[write]);
    }

    /// A master that is gone: EPIPE, with SIGPIPE ignored by the Rust runtime
    /// here as by PHP CLI in a fork server (see the heartbeat's test of it).
    #[test]
    fn a_line_for_a_master_that_is_gone_is_dropped() {
        let (read, write) = plain_pipe();

        close(&[read]);

        assert_eq!(write_all(write, b"{}\n").ok(), Some(false));

        close(&[write]);
    }

    /// One test for everything that goes through the process's own events pipe,
    /// so no other test sees it change under it.
    #[test]
    fn a_process_that_is_no_fork_server_writes_no_events() {
        reset_in_child();

        assert_eq!(settle(), None);
        assert!(matches!(present(), Value::Bool(false)));
        assert!(matches!(write("{}\n"), Ok(Value::Bool(false))), "an event was written with no pipe");
    }
}
