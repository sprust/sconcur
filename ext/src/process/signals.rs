//! The signals a process watches without ext-pcntl.
//!
//! The handler does the one thing a signal handler may do safely: it sets a bit.
//! The PHP side takes the bits on its own schedule — a master once per
//! supervision tick, a worker on each pass of its serve loop — so no PHP code
//! ever runs inside a handler, and a signal that lands while the PHP thread is in
//! a syscall costs that syscall nothing but an EINTR it already handles.
//!
//! What was installed before is kept, and put back by `restore` and in a forked
//! child. In a PHP process that is usually the engine's own deferring handler
//! (zend_signal takes HUP, INT, QUIT, TERM, USR1, USR2 and PROF at startup), not
//! SIG_DFL, so restoring the previous action rather than the default is what
//! leaves the engine as it was.

use std::sync::Mutex;
use std::sync::atomic::{AtomicU64, Ordering};

use libc::c_int;

use super::Error;
use crate::dto::Value;

/// One bit per signal number, set by the handler, cleared by `take`.
static PENDING: AtomicU64 = AtomicU64::new(0);

/// The signals being watched, each with the action that was installed for it
/// before. Only the PHP thread touches it, so it is never locked by another
/// thread at the moment of a fork.
static WATCHED: Mutex<Vec<(c_int, libc::sigaction)>> = Mutex::new(Vec::new());

/// The signals PHP may name. By name, because the numbers differ between
/// platforms: USR1 is 10 on Linux and 30 on macOS.
const NAMED: [(&str, c_int); 8] = [
    ("HUP", libc::SIGHUP),
    ("INT", libc::SIGINT),
    ("QUIT", libc::SIGQUIT),
    ("KILL", libc::SIGKILL),
    ("USR1", libc::SIGUSR1),
    ("USR2", libc::SIGUSR2),
    ("TERM", libc::SIGTERM),
    ("CHLD", libc::SIGCHLD),
];

pub fn number(name: &str) -> Result<c_int, Error> {
    NAMED
        .iter()
        .find(|(known, _)| *known == name)
        .map(|(_, number)| *number)
        .ok_or_else(|| Error::Failed(format!("unknown signal '{name}'")))
}

fn name(number: c_int) -> Option<&'static str> {
    NAMED
        .iter()
        .find(|(_, known)| *known == number)
        .map(|(name, _)| *name)
}

extern "C" fn on_signal(number: c_int) {
    if (1..=64).contains(&number) {
        PENDING.fetch_or(1 << (number - 1), Ordering::SeqCst);
    }
}

fn watched() -> std::sync::MutexGuard<'static, Vec<(c_int, libc::sigaction)>> {
    // Nothing done under the lock can panic halfway through a change.
    WATCHED.lock().unwrap_or_else(|poisoned| poisoned.into_inner())
}

/// Starts recording the given signals. One already watched is left as it is.
pub fn watch(names: &[String]) -> Result<Value, Error> {
    let numbers = names
        .iter()
        .map(|name| number(name))
        .collect::<Result<Vec<c_int>, Error>>()?;

    let mut watched = watched();

    for number in numbers {
        if watched.iter().any(|(already, _)| *already == number) {
            continue;
        }

        let mut action: libc::sigaction = unsafe { std::mem::zeroed() };

        action.sa_sigaction = on_signal as extern "C" fn(c_int) as libc::sighandler_t;
        // Restarted, so a signal does not fail the file write or the read the
        // PHP thread happens to be in; a select or a sleep returns early anyway.
        action.sa_flags = libc::SA_RESTART;

        unsafe { libc::sigemptyset(&mut action.sa_mask) };

        let mut previous: libc::sigaction = unsafe { std::mem::zeroed() };

        if unsafe { libc::sigaction(number, &action, &mut previous) } != 0 {
            return Err(Error::Failed(format!(
                "cannot watch {}: {}",
                name(number).unwrap_or("the signal"),
                std::io::Error::last_os_error(),
            )));
        }

        watched.push((number, previous));
    }

    Ok(Value::Nil)
}

/// The signals that arrived since the last call, by name, each once.
pub fn take() -> Value {
    let bits = PENDING.swap(0, Ordering::SeqCst);

    let arrived: Vec<Value> = NAMED
        .iter()
        .filter(|(_, number)| bits & (1 << (number - 1)) != 0)
        .map(|(name, _)| Value::from(*name))
        .collect();

    Value::List(arrived)
}

/// Puts back what was installed before each watched signal, and forgets what
/// arrived.
pub fn restore() {
    let mut watched = watched();

    for (number, previous) in watched.drain(..) {
        unsafe { libc::sigaction(number, &previous, std::ptr::null_mut()) };
    }

    PENDING.store(0, Ordering::SeqCst);
}

/// A copy of the watched signals and their previous actions, taken in the parent
/// before a fork: the child restores from the copy.
pub fn snapshot() -> Vec<(c_int, libc::sigaction)> {
    watched().clone()
}

/// Blocks the watched signals for the length of a fork, so none is handled in
/// the child before it has put the previous actions back. Answers the mask to
/// return to.
pub fn block(watched: &[(c_int, libc::sigaction)]) -> libc::sigset_t {
    let mut blocked: libc::sigset_t = unsafe { std::mem::zeroed() };
    let mut previous: libc::sigset_t = unsafe { std::mem::zeroed() };

    unsafe {
        libc::sigemptyset(&mut blocked);

        for (number, _) in watched {
            libc::sigaddset(&mut blocked, *number);
        }

        libc::pthread_sigmask(libc::SIG_BLOCK, &blocked, &mut previous);
    }

    previous
}

pub fn unblock(previous: &libc::sigset_t) {
    unsafe { libc::pthread_sigmask(libc::SIG_SETMASK, previous, std::ptr::null_mut()) };
}

/// In a freshly forked child: the parent's watching ends here. The child starts
/// with the actions the parent had before it watched, and with nothing recorded.
pub fn reset_in_child(watched: &[(c_int, libc::sigaction)]) {
    for (number, previous) in watched {
        unsafe { libc::sigaction(*number, previous, std::ptr::null_mut()) };
    }

    self::watched().clear();

    PENDING.store(0, Ordering::SeqCst);
}

#[cfg(test)]
mod tests {
    use super::*;

    /// A signal raised at this process is recorded, handed over once, and the
    /// previous action is back after `restore`. USR2 is used by no other test
    /// of the core, so the watch does not race one.
    #[test]
    fn a_watched_signal_is_taken_once_and_the_previous_action_comes_back() {
        let mut before: libc::sigaction = unsafe { std::mem::zeroed() };

        unsafe { libc::sigaction(libc::SIGUSR2, std::ptr::null(), &mut before) };

        watch(&["USR2".to_string()]).ok().unwrap();

        unsafe { libc::raise(libc::SIGUSR2) };

        assert_eq!(take().as_list().map(|list| list.len()), Some(1));
        assert_eq!(take().as_list().map(|list| list.len()), Some(0));

        restore();

        let mut after: libc::sigaction = unsafe { std::mem::zeroed() };

        unsafe { libc::sigaction(libc::SIGUSR2, std::ptr::null(), &mut after) };

        assert_eq!(after.sa_sigaction, before.sa_sigaction);
    }

    #[test]
    fn a_signal_is_named_rather_than_numbered() {
        assert_eq!(number("TERM").ok(), Some(libc::SIGTERM));
        assert!(number("SIGTERM").is_err());
    }
}
