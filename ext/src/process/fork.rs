//! Forking a worker, and what the child has to do before it is handed back to
//! PHP.
//!
//! The fork itself is refused in a process that runs threads. Only the forking
//! thread survives into the child: a lock another thread held at that moment —
//! the runtime's, malloc's, a driver's — stays held in the child for good, and
//! the first thing that wants it hangs. A master that never started this
//! extension's runtime is single-threaded, and that is what is checked.
//!
//! The child is prepared before PHP runs another line in it: stdin from
//! /dev/null, stdout and stderr onto the parent's pipes, the parent's other
//! descriptors pointed at /dev/null, the parent's signal watching undone, and —
//! in the glue — the random generators unseeded and the parent's objects marked
//! as destroyed.

use std::os::fd::{AsRawFd, FromRawFd, IntoRawFd, OwnedFd, RawFd};

use libc::c_int;

use super::payloads::ForkParams;
use super::{Error, signals, threads};
use crate::dto::{Map, Value};

/// The two ends of one pipe.
struct Pipe {
    read: OwnedFd,
    write: OwnedFd,
}

pub fn fork(params: ForkParams) -> Result<Value, Error> {
    refuse_unless_alone()?;

    // Owned until handed over, so an error on the way closes whatever was
    // already opened.
    let output = if params.capture_output {
        Some((pipe()?, pipe()?))
    } else {
        None
    };

    let heartbeat = if params.heartbeat { Some(pipe()?) } else { None };

    let watched = signals::snapshot();
    let mask = signals::block(&watched);

    // Whatever C stdio holds unwritten would otherwise be written twice, once by
    // each process.
    unsafe { libc::fflush(std::ptr::null_mut()) };

    let pid = unsafe { libc::fork() };

    if pid == 0 {
        signals::reset_in_child(&watched);
        signals::unblock(&mask);

        return Ok(prepare_child(&params.keep, output, heartbeat));
    }

    signals::unblock(&mask);

    if pid < 0 {
        return Err(failed("fork"));
    }

    // The write ends belong to the child now; the parent's copies close here, or
    // it would never see the end of the child's output.
    let (stdout, stderr) = match output {
        Some((stdout, stderr)) => (handed_over(stdout.read), handed_over(stderr.read)),
        None => (-1, -1),
    };

    let heartbeat = heartbeat.map_or(-1, |pipe| handed_over(pipe.read));

    Ok(Map::new()
        .set("pid", pid as i64)
        .set("out", stdout as i64)
        .set("err", stderr as i64)
        .set("hb", heartbeat as i64)
        .into())
}

/// Refuses a fork in a process that runs threads, without forking: what a master
/// asks before it starts, so a process that is not fit to fork is refused before
/// anything is written, rather than at its first worker.
pub fn refuse_unless_alone() -> Result<(), Error> {
    if crate::core::built().is_some() {
        return Err(Error::Refused(
            "the extension's runtime already runs in this process, and a child would inherit its locks without its \
             threads: fork before any asynchronous work"
                .to_string(),
        ));
    }

    if let Some(count) = threads::count()
        && count > 1
    {
        return Err(Error::Refused(format!(
            "this process runs {count} threads and a fork keeps only the calling one, so a lock any other held would \
             stay held in the child; something other than this extension started them"
        )));
    }

    Ok(())
}

fn prepare_child(keep: &[i64], output: Option<(Pipe, Pipe)>, heartbeat: Option<Pipe>) -> Value {
    let null = unsafe { libc::open(c"/dev/null".as_ptr(), libc::O_RDWR) };

    if null >= 0 {
        unsafe { libc::dup2(null, 0) };
    }

    if let Some((stdout, stderr)) = output {
        unsafe {
            libc::dup2(stdout.write.as_raw_fd(), 1);
            libc::dup2(stderr.write.as_raw_fd(), 2);
        }

        // The originals close as the pipes drop; 1 and 2 are what stays.
    }

    // The read end is the parent's; only the write end stays, close-on-exec, so
    // nothing this worker execs can mark it alive.
    let heartbeat = heartbeat.map_or(-1, |pipe| pipe.write.into_raw_fd());

    if null >= 0 {
        neutralize(null, keep, heartbeat);

        if null > 2 {
            unsafe { libc::close(null) };
        }
    }

    unsafe { crate::sconcur_after_fork_in_child() };

    Map::new()
        .set("pid", 0_i64)
        .set("hb", heartbeat as i64)
        .into()
}

/// Points every descriptor above stderr at /dev/null, except the ones asked to be
/// kept: the parent's lock, its log, its sockets and the other workers' pipes
/// must not live on in a worker.
///
/// Pointed rather than closed, because PHP may still hold a stream on one of
/// them. A closed number is reused by the next open, and that stream's eventual
/// close would then close the new file.
fn neutralize(null: RawFd, keep: &[i64], heartbeat: RawFd) {
    for descriptor in open_descriptors() {
        let kept = descriptor <= 2
            || descriptor == null
            || descriptor == heartbeat
            || keep.contains(&(descriptor as i64));

        // The listing's own directory is in it and already closed by now.
        if kept || unsafe { libc::fcntl(descriptor, libc::F_GETFD) } < 0 {
            continue;
        }

        unsafe { libc::dup2(null, descriptor) };
    }
}

/// The descriptors above stderr this process has open: what a master finds open
/// as it starts, opened by the application that started it, which its forked
/// workers keep. The listing's own directory is in the listing and closed by the
/// time it is returned, so it is left out.
pub fn inherited_descriptors() -> Value {
    Value::List(
        open_descriptors()
            .into_iter()
            .filter(|&descriptor| descriptor > 2 && unsafe { libc::fcntl(descriptor, libc::F_GETFD) } >= 0)
            .map(|descriptor| Value::Int(descriptor as i64))
            .collect(),
    )
}

/// The descriptors this process has open. /proc on Linux, /dev/fd elsewhere.
fn open_descriptors() -> Vec<RawFd> {
    for directory in ["/proc/self/fd", "/dev/fd"] {
        if let Ok(entries) = std::fs::read_dir(directory) {
            return entries
                .filter_map(|entry| entry.ok()?.file_name().to_str()?.parse().ok())
                .collect();
        }
    }

    Vec::new()
}

/// A pipe whose ends are close-on-exec: the parent's ends must not leak into a
/// process the master execs, and the child keeps 1 and 2 only through the dup2,
/// which does not copy the flag.
fn pipe() -> Result<Pipe, Error> {
    let mut ends: [c_int; 2] = [-1, -1];

    #[cfg(target_os = "linux")]
    let created = unsafe { libc::pipe2(ends.as_mut_ptr(), libc::O_CLOEXEC) };

    #[cfg(not(target_os = "linux"))]
    let created = unsafe { libc::pipe(ends.as_mut_ptr()) };

    if created != 0 {
        return Err(failed("pipe"));
    }

    #[cfg(not(target_os = "linux"))]
    for end in ends {
        unsafe { libc::fcntl(end, libc::F_SETFD, libc::FD_CLOEXEC) };
    }

    Ok(unsafe {
        Pipe {
            read: OwnedFd::from_raw_fd(ends[0]),
            write: OwnedFd::from_raw_fd(ends[1]),
        }
    })
}

/// Gives a descriptor up to PHP, which opens it as a stream and closes this
/// number.
fn handed_over(descriptor: OwnedFd) -> RawFd {
    descriptor.into_raw_fd()
}

fn failed(call: &str) -> Error {
    Error::Failed(format!("{call}: {}", std::io::Error::last_os_error()))
}
