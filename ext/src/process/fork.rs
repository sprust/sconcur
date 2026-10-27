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
//! descriptors pointed at /dev/null, the parent's signal watching undone, its
//! heartbeat swapped for the child's own or none, and — in the glue — the random
//! generators unseeded and the parent's objects marked as destroyed. A child
//! that cannot be prepared exits instead (see `abandon`).

use std::cell::RefCell;
use std::os::fd::{AsRawFd, FromRawFd, IntoRawFd, OwnedFd, RawFd};

use libc::c_int;

use super::payloads::ForkParams;
use super::{Error, heartbeat, signals, threads};
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

    // One for each pipe, and a couple for whatever else PHP opens on the way.
    remember_free_numbers(3 + 2);

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
        // Read only here, on the way to a refusal: the count says what the runtime
        // started, which is worth the read of /proc it costs.
        let counted = threads::count().map_or(String::new(), |count| format!(" ({count} threads)"));

        return Err(Error::Refused(format!(
            "the extension's runtime already runs in this process{counted}, and a child would inherit its locks \
             without its threads: fork before any asynchronous work"
        )));
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

/// The exit code of a child that cannot be isolated from its parent: EX_OSERR of
/// sysexits.h, an operating system error.
const UNISOLATED_EXIT_CODE: c_int = 71;

fn prepare_child(keep: &[i64], output: Option<(Pipe, Pipe)>, heartbeat: Option<Pipe>) -> Value {
    // The pipes first, so that a child failing past this point says so where its
    // parent reads it.
    if let Some((stdout, stderr)) = output {
        if unsafe { libc::dup2(stdout.write.as_raw_fd(), 1) } < 0 {
            abandon(b"dup2 of the stdout pipe");
        }

        if unsafe { libc::dup2(stderr.write.as_raw_fd(), 2) } < 0 {
            abandon(b"dup2 of the stderr pipe");
        }

        // The originals close as the pipes drop; 1 and 2 are what stays.
    }

    let null = unsafe { libc::open(c"/dev/null".as_ptr(), libc::O_RDWR) };

    if null < 0 {
        abandon(b"open of /dev/null");
    }

    if unsafe { libc::dup2(null, 0) } < 0 {
        abandon(b"dup2 of /dev/null onto stdin");
    }

    // The read end is the parent's; only the write end stays, close-on-exec, and
    // it is the extension's to write the marks to (`heartbeat`). PHP opens no
    // stream on it, so nothing this worker execs inherits it.
    let heartbeat = heartbeat.map(|pipe| pipe.write.into_raw_fd());

    neutralize(null, keep, heartbeat.unwrap_or(-1));

    // What the child keeps is the child's own — the application's descriptors, which
    // the parent may have been handed without the flag by whatever started it — and
    // not a program's it starts.
    close_on_exec_all(keep.iter().filter_map(|descriptor| RawFd::try_from(*descriptor).ok()).collect());

    if null > 2 {
        unsafe { libc::close(null) };
    }

    if !heartbeat::reset_in_child(heartbeat) {
        abandon(b"fcntl of the heartbeat pipe");
    }

    super::forget_master();

    unsafe { crate::sconcur_after_fork_in_child() };

    Map::new().set("pid", 0_i64).into()
}

/// Points every descriptor above stderr at /dev/null, except the ones asked to be
/// kept: the parent's lock, its log, its sockets and the other workers' pipes
/// must not live on in a worker.
///
/// Pointed rather than closed, because PHP may still hold a stream on one of
/// them. A closed number is reused by the next open, and that stream's eventual
/// close would then close the new file. Pointed close-on-exec (`point_at_null`),
/// so a program the worker starts inherits none of the placeholders.
fn neutralize(null: RawFd, keep: &[i64], heartbeat: RawFd) {
    let Some(descriptors) = open_descriptors() else {
        abandon(b"listing of the open descriptors");
    };

    for descriptor in descriptors {
        let kept = descriptor <= 2
            || descriptor == null
            || descriptor == heartbeat
            || keep.contains(&(descriptor as i64));

        // The listing's own directory is in it and already closed by now.
        if kept || unsafe { libc::fcntl(descriptor, libc::F_GETFD) } < 0 {
            continue;
        }

        if !point_at_null(null, descriptor) {
            abandon(b"dup of /dev/null onto an inherited descriptor");
        }
    }
}

/// Points `descriptor` at the /dev/null open as `null`, close-on-exec: dup2(2)
/// would clear the flag on the copy it makes.
fn point_at_null(null: RawFd, descriptor: RawFd) -> bool {
    #[cfg(target_os = "linux")]
    {
        unsafe { libc::dup3(null, descriptor, libc::O_CLOEXEC) >= 0 }
    }

    #[cfg(not(target_os = "linux"))]
    {
        unsafe { libc::dup2(null, descriptor) >= 0 && libc::fcntl(descriptor, libc::F_SETFD, libc::FD_CLOEXEC) == 0 }
    }
}

/// Makes each of `descriptors` above stderr close-on-exec; one that is not open
/// any more — the listing's own directory — is skipped.
fn close_on_exec_all(descriptors: Vec<RawFd>) {
    for descriptor in descriptors {
        if descriptor > 2 {
            let flags = unsafe { libc::fcntl(descriptor, libc::F_GETFD) };

            if flags >= 0 {
                unsafe { libc::fcntl(descriptor, libc::F_SETFD, flags | libc::FD_CLOEXEC) };
            }
        }
    }
}

/// Ends a child that cannot be isolated from its parent, before it runs a line of
/// PHP: run on, it would keep the parent's descriptors — its lock, its log, the
/// other workers' pipes — or write where the parent writes, and the parent would
/// never know. One line on stderr names the step that failed and its errno, and
/// the exit code is UNISOLATED_EXIT_CODE (71), for the parent to reap.
///
/// The line is put together on the stack and written with one write(2), the
/// errno read before anything else can change it.
fn abandon(step: &[u8]) -> ! {
    let errno = std::io::Error::last_os_error().raw_os_error().unwrap_or(0);

    let mut line = Line::default();

    line.push(b"sconcur: a forked child cannot be isolated from its parent and exits: ");
    line.push(step);
    line.push(b" failed, errno ");
    line.push_number(errno);
    line.push(b"\n");

    unsafe {
        libc::write(2, line.bytes.as_ptr().cast(), line.length);
        libc::_exit(UNISOLATED_EXIT_CODE)
    }
}

/// A line of at most 256 bytes on the stack; what does not fit is cut off.
struct Line {
    bytes: [u8; 256],
    length: usize,
}

impl Default for Line {
    fn default() -> Self {
        Line {
            bytes: [0; 256],
            length: 0,
        }
    }
}

impl Line {
    fn push(&mut self, part: &[u8]) {
        let taken = part.len().min(self.bytes.len() - self.length);

        self.bytes[self.length..self.length + taken].copy_from_slice(&part[..taken]);
        self.length += taken;
    }

    fn push_number(&mut self, number: i32) {
        let mut digits = [0_u8; 12];
        let mut at = digits.len();
        let mut rest = number.unsigned_abs();

        loop {
            at -= 1;
            digits[at] = b'0' + (rest % 10) as u8;
            rest /= 10;

            if rest == 0 {
                break;
            }
        }

        if number < 0 {
            at -= 1;
            digits[at] = b'-';
        }

        self.push(&digits[at..]);
    }
}

/// The descriptors above stderr this process has open: what a master finds open
/// as it starts, opened by the application that started it, which its forked
/// workers keep. The listing's own directory is in the listing and closed by the
/// time it is returned, so it is left out.
pub fn inherited_descriptors() -> Value {
    Value::List(
        open_descriptors()
            .unwrap_or_default()
            .into_iter()
            .filter(|&descriptor| descriptor > 2 && unsafe { libc::fcntl(descriptor, libc::F_GETFD) } >= 0)
            .map(|descriptor| Value::Int(descriptor as i64))
            .collect(),
    )
}

/// The descriptors this process has open. /proc on Linux, /dev/fd elsewhere;
/// none when neither can be read.
fn open_descriptors() -> Option<Vec<RawFd>> {
    for directory in ["/proc/self/fd", "/dev/fd"] {
        if let Ok(entries) = std::fs::read_dir(directory) {
            return Some(
                entries
                    .filter_map(|entry| entry.ok()?.file_name().to_str()?.parse().ok())
                    .collect(),
            );
        }
    }

    None
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

/// Gives a descriptor up to PHP, which opens it as a stream and then hands this
/// number back to `adopted`.
fn handed_over(descriptor: OwnedFd) -> RawFd {
    descriptor.into_raw_fd()
}

thread_local! {
    /// The lowest numbers free once a fork handed its pipes over: dup(2) takes the
    /// lowest free number, so the copies PHP then opens of them land there. Taken
    /// by `adopted`, so it describes the last fork only. Per thread because the PHP
    /// thread is the only one that forks, and so that the tests, each on a thread
    /// of its own, do not share it.
    static FREE_AT_FORK: RefCell<Vec<RawFd>> = const { RefCell::new(Vec::new()) };
}

/// Finds the `count` lowest free numbers by taking them — open(2) takes the lowest
/// free number too — and gives them straight back: a few calls, where listing
/// what is open would cost one per descriptor a master holds.
fn remember_free_numbers(count: usize) {
    let mut free = Vec::with_capacity(count);

    while free.len() < count {
        let number = unsafe { libc::open(c"/dev/null".as_ptr(), libc::O_RDONLY | libc::O_CLOEXEC) };

        if number < 0 {
            break;
        }

        free.push(number);
    }

    for &number in &free {
        unsafe { libc::close(number) };
    }

    FREE_AT_FORK.set(free);
}

/// Closes the descriptors a fork handed over, once PHP has opened its streams on
/// them, and makes the descriptors of those streams close-on-exec. php://fd
/// opens a copy made by dup(2), which leaves the flag behind: without this,
/// every process a forking parent starts would hold the read ends of its
/// children's pipes.
///
/// A copy is found as another descriptor open on the same file the same way. A
/// pipe the fork made is open nowhere else in this process — the end that went
/// to the other process is closed here — so that is PHP's copy and nothing else.
/// dup(2) takes the lowest free number, so the copies are at the numbers that were
/// free at the fork (`FREE_AT_FORK`): those few are asked about first, and every
/// open descriptor — three pipes per worker in a master — only for a copy not
/// among them. Where the open descriptors cannot be listed, the descriptors are
/// only closed.
///
/// Every one handed over is closed, whatever fails: the first failure is the
/// answer.
pub fn adopted(descriptors: &[RawFd]) -> Result<Value, Error> {
    let likely = FREE_AT_FORK.take();

    let mut everything: Option<Vec<RawFd>> = None;

    let mut copies = Vec::new();

    for &descriptor in descriptors {
        let Some(original) = identity(descriptor) else {
            continue;
        };

        let same = |candidates: &[RawFd]| -> Vec<RawFd> {
            candidates
                .iter()
                .copied()
                .filter(|&other| !descriptors.contains(&other) && identity(other) == Some(original))
                .collect()
        };

        let mut found = same(&likely);

        if found.is_empty() {
            found = same(everything.get_or_insert_with(|| open_descriptors().unwrap_or_default()));
        }

        copies.extend(found);
    }

    let mut outcome = Ok(Value::Nil);

    for &descriptor in descriptors {
        if unsafe { libc::close(descriptor) } != 0 && outcome.is_ok() {
            outcome = Err(Error::Failed(format!(
                "close {descriptor}: {}",
                std::io::Error::last_os_error()
            )));
        }
    }

    for copy in copies {
        let flags = unsafe { libc::fcntl(copy, libc::F_GETFD) };

        if (flags < 0 || unsafe { libc::fcntl(copy, libc::F_SETFD, flags | libc::FD_CLOEXEC) } < 0)
            && outcome.is_ok()
        {
            outcome = Err(failed("fcntl"));
        }
    }

    outcome
}

/// The file a descriptor is open on and the way it is open: the two ends of a
/// pipe share an inode and differ in their access mode. None for a descriptor
/// that is not open.
fn identity(descriptor: RawFd) -> Option<(libc::dev_t, libc::ino_t, c_int)> {
    let mut status: libc::stat = unsafe { std::mem::zeroed() };

    if unsafe { libc::fstat(descriptor, &mut status) } != 0 {
        return None;
    }

    let flags = unsafe { libc::fcntl(descriptor, libc::F_GETFL) };

    if flags < 0 {
        return None;
    }

    Some((status.st_dev, status.st_ino, flags & libc::O_ACCMODE))
}

fn failed(call: &str) -> Error {
    Error::Failed(format!("{call}: {}", std::io::Error::last_os_error()))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn close_on_exec(descriptor: RawFd) -> bool {
        let flags = unsafe { libc::fcntl(descriptor, libc::F_GETFD) };

        flags & libc::FD_CLOEXEC != 0
    }

    /// A placeholder is /dev/null and close-on-exec: a dup2 would leave a program
    /// the worker starts a copy of it.
    #[test]
    fn a_placeholder_is_close_on_exec() {
        let null = unsafe { libc::open(c"/dev/null".as_ptr(), libc::O_RDWR) };
        let placeholder_pipe = pipe().ok().unwrap();
        let descriptor = placeholder_pipe.read.as_raw_fd();

        assert!(point_at_null(null, descriptor));

        assert!(close_on_exec(descriptor), "a program the worker starts would inherit the placeholder");
        assert_eq!(
            std::fs::read_link(format!("/proc/self/fd/{descriptor}")).ok(),
            Some(std::path::PathBuf::from("/dev/null"))
        );

        unsafe { libc::close(null) };
    }

    /// Every descriptor above stderr ends close-on-exec; stderr and below are left
    /// as they were, and a number nothing is open on is skipped. Only descriptors
    /// of its own are handed in: the tests share the process, and the others'
    /// flags are theirs.
    #[test]
    fn what_a_master_handed_over_is_made_close_on_exec() {
        let plain = unsafe { libc::open(c"/dev/null".as_ptr(), libc::O_RDONLY) };

        assert!(!close_on_exec(plain));

        let stderr_before = unsafe { libc::fcntl(2, libc::F_GETFD) };

        close_on_exec_all(vec![2, plain, c_int::MAX]);

        assert!(close_on_exec(plain), "a descriptor above stderr was left inheritable");
        assert_eq!(unsafe { libc::fcntl(2, libc::F_GETFD) }, stderr_before, "stderr was changed");

        unsafe { libc::close(plain) };
    }

    /// What php://fd does to a handed-over end is a dup(2), which leaves the flag
    /// behind. The copy of the adopted end gets it back; a copy of another pipe
    /// open in the same process is left as it was, and so is the other end of the
    /// adopted pipe, which shares its inode.
    #[test]
    fn the_copy_php_opened_of_a_handed_over_descriptor_is_made_close_on_exec() {
        let adopted_pipe = pipe().ok().unwrap();
        let other_pipe = pipe().ok().unwrap();

        let handed = handed_over(adopted_pipe.read);
        let copy = unsafe { libc::dup(handed) };
        let other = unsafe { libc::dup(other_pipe.read.as_raw_fd()) };
        let other_end = unsafe { libc::dup(adopted_pipe.write.as_raw_fd()) };

        assert!(!close_on_exec(copy) && !close_on_exec(other) && !close_on_exec(other_end));

        assert!(adopted(&[handed]).is_ok());

        assert!(close_on_exec(copy), "the copy would reach whatever this process execs");
        assert!(!close_on_exec(other), "a descriptor on another pipe was changed");
        assert!(!close_on_exec(other_end), "the other end of the pipe was changed");

        unsafe {
            libc::close(copy);
            libc::close(other);
            libc::close(other_end);
        }
    }

    /// After a fork the copies are looked for at the numbers that were free at
    /// the fork, and found there: every copy of every pipe handed over at once,
    /// and a descriptor that was open at the fork left alone even on the same
    /// pipe.
    #[test]
    fn the_copies_at_the_numbers_free_at_the_fork_are_made_close_on_exec() {
        let first = pipe().ok().unwrap();
        let second = pipe().ok().unwrap();

        let older_end = unsafe { libc::dup(first.write.as_raw_fd()) };

        let handed = [handed_over(first.read), handed_over(second.read)];

        remember_free_numbers(4);

        let copies = handed.map(|descriptor| unsafe { libc::dup(descriptor) });

        assert!(copies.iter().all(|&copy| !close_on_exec(copy)));

        assert!(adopted(&handed).is_ok());

        assert!(
            copies.iter().all(|&copy| close_on_exec(copy)),
            "a copy would reach whatever this process execs"
        );
        assert!(!close_on_exec(older_end), "the other end of the pipe was changed");
        assert!(
            handed.iter().all(|&descriptor| unsafe { libc::fcntl(descriptor, libc::F_GETFD) } < 0),
            "a descriptor handed over was left open"
        );

        unsafe {
            for copy in copies {
                libc::close(copy);
            }

            libc::close(older_end);
        }
    }
}
