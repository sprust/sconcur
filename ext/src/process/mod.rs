//! Process control: what a worker master asks of the operating system — fork a
//! worker, reap it, signal it, watch its own signals — as the commands of the
//! one `process` export, so the PHP side needs neither ext-pcntl nor ext-posix.
//!
//! None of it is a feature. A feature is a task on the runtime, and the process
//! that forks must not run one: a fork keeps only the calling thread. So these
//! run synchronously on the PHP thread, start nothing, and a fork is refused once
//! anything has started. The commands are data all the same — a short name and a
//! parameter map, decoded into the structs of `payloads` — so a new one is a
//! match arm here, not a new C symbol.

mod fork;
mod heartbeat;
mod payloads;
mod signals;
mod threads;

use std::sync::atomic::{AtomicBool, Ordering};

use serde::Deserialize;

use crate::dto::{Map, Value};

/// How a command failed. `Refused` is a misuse the caller has to fix — forking a
/// process that already runs threads — and is raised as a LogicException;
/// `Failed` is the system saying no, or a parameter nothing can be done with — an
/// unknown signal, a pid that names no single process — raised as a
/// RuntimeException.
pub enum Error {
    Refused(String),
    Failed(String),
}

/// Runs one command. The names are the values of
/// SConcur\Process\ProcessCommandEnum.
pub fn run(command: &[u8], payload: &Value) -> Result<Value, Error> {
    match command {
        b"frk" => fork::fork(decode(payload)?),
        b"ext" => {
            let params: payloads::ExitParams = decode(payload)?;

            exit(params.code)
        }
        b"kil" => kill(decode(payload)?),
        b"rap" => reap(decode(payload)?),
        b"ppd" => Ok(Value::Int(unsafe { libc::getppid() } as i64)),
        b"sgw" => {
            let params: payloads::WatchParams = decode(payload)?;

            signals::watch(&params.signals)
        }
        b"sgt" => Ok(signals::take()),
        b"sgr" => {
            signals::restore();

            Ok(Value::Nil)
        }
        b"cls" => close(decode(payload)?),
        b"adp" => {
            let params: payloads::CloseParams = decode(payload)?;

            fork::adopted(params.descriptor as libc::c_int)
        }
        b"bnd" => bind(decode(payload)?),
        b"cfk" => fork::refuse_unless_alone().map(|_| Value::Nil),
        b"mst" => {
            MASTER.store(true, Ordering::Relaxed);

            Ok(Value::Nil)
        }
        b"fds" => Ok(fork::inherited_descriptors()),
        b"hbq" => Ok(heartbeat::present()),
        b"hbw" => {
            let params: payloads::MarkParams = decode(payload)?;

            heartbeat::mark(params.mark)
        }
        other => Err(Error::Failed(format!(
            "unknown process command '{}'",
            String::from_utf8_lossy(other)
        ))),
    }
}

/// Whether this process is a worker master's (`mst`). Set once the master starts
/// and never cleared in it; a fork clears it in the child (`fork::prepare_child`).
static MASTER: AtomicBool = AtomicBool::new(false);

/// Whether this process is a worker master's, which must never start the
/// runtime: every worker is a fork of it, and a process running threads cannot
/// fork. Asked before an asynchronous call starts one.
pub fn is_master() -> bool {
    MASTER.load(Ordering::Relaxed)
}

/// In a freshly forked child: the child is a worker, not the master it is a copy
/// of.
pub(super) fn forget_master() {
    MASTER.store(false, Ordering::Relaxed);
}

fn decode<'a, T: Deserialize<'a>>(payload: &'a Value) -> Result<T, Error> {
    payload
        .decode()
        .map_err(|error| Error::Failed(format!("the parameters do not decode: {error}")))
}

/// Leaves the process without running PHP's shutdown: the shutdown functions
/// and destructors of a worker are the master's, inherited with its memory, and
/// running them once per worker would undo the master's work N times over.
/// What the worker printed is written out first.
fn exit(code: i64) -> ! {
    unsafe { crate::sconcur_flush_output() };

    if let Some(core) = crate::core::built() {
        core.logger().flush();
    }

    unsafe { libc::_exit(code as libc::c_int) }
}

/// True once the signal is on its way, false when there is no such process.
fn kill(params: payloads::KillParams) -> Result<Value, Error> {
    let pid = one_process(params.pid)?;
    let signal = signals::number(&params.signal)?;

    if unsafe { libc::kill(pid, signal) } == 0 {
        return Ok(Value::Bool(true));
    }

    let error = std::io::Error::last_os_error();

    if error.raw_os_error() == Some(libc::ESRCH) {
        return Ok(Value::Bool(false));
    }

    Err(Error::Failed(format!("kill {}: {error}", params.pid)))
}

/// The children of the given pids that have exited, without waiting for the
/// rest. Each comes with its exit code or the signal that ended it; a pid that is
/// not a child of this process (any more) comes with neither, so the caller can
/// stop waiting for it.
///
/// Every pid is checked before any is reaped: a list refused half way would have
/// reaped the children ahead of the bad pid without reporting them, and a child
/// reaped unreported is waited for forever.
fn reap(params: payloads::ReapParams) -> Result<Value, Error> {
    let pids = params
        .pids
        .iter()
        .map(|&pid| one_process(pid))
        .collect::<Result<Vec<libc::pid_t>, Error>>()?;

    let mut exited = Vec::new();

    for pid in pids {
        let mut status: libc::c_int = 0;

        let answer = unsafe { libc::waitpid(pid, &mut status, libc::WNOHANG) };

        if answer == 0 {
            continue;
        }

        if answer < 0 {
            let error = std::io::Error::last_os_error();

            match error.raw_os_error() {
                // Asked again on the next tick.
                Some(libc::EINTR) => continue,
                Some(libc::ECHILD) => {
                    exited.push(outcome(pid.into(), None, None));

                    continue;
                }
                _ => return Err(Error::Failed(format!("waitpid {pid}: {error}"))),
            }
        }

        let (code, signal) = classify(status);

        exited.push(outcome(pid.into(), code, signal));
    }

    Ok(Value::List(exited))
}

/// A pid that names one process. kill(2) takes 0 for this process's whole group —
/// the master included — and -1 for every process it may signal; waitpid(2) takes
/// both for any child, which would then be reported under the pid asked for. A
/// number outside pid_t's range would be truncated to one of them by a cast. All
/// are refused.
///
/// Refused as Failed, so the caller gets the ProcessException the PHP side throws
/// for the same pid before it asks: Refused is a ForkRefusedException, which says
/// the process runs threads.
fn one_process(pid: i64) -> Result<libc::pid_t, Error> {
    match libc::pid_t::try_from(pid) {
        Ok(pid) if pid > 0 => Ok(pid),
        _ => Err(Error::Failed(format!("pid {pid} is not a child"))),
    }
}

/// The exit code, or the number of the signal that ended the process.
fn classify(status: libc::c_int) -> (Option<i64>, Option<i64>) {
    if libc::WIFEXITED(status) {
        return (Some(libc::WEXITSTATUS(status) as i64), None);
    }

    if libc::WIFSIGNALED(status) {
        return (None, Some(libc::WTERMSIG(status) as i64));
    }

    (None, None)
}

fn outcome(pid: i64, code: Option<i64>, signal: Option<i64>) -> Value {
    Map::new()
        .set("pid", pid)
        .set("cd", code.map_or(Value::Nil, Value::Int))
        .set("sg", signal.map_or(Value::Nil, Value::Int))
        .into()
}

/// A listening socket for workers to share, answered as its descriptor. Bound
/// once in the master and inherited by every worker it forks or execs, so all of
/// them accept from one queue: a stopping worker leaves connections to the others
/// rather than resetting the ones that queued for it, as a SO_REUSEPORT socket
/// closing would.
///
/// Close-on-exec like every socket created here: a fork keeps it regardless, an
/// exec'd worker gets it through the descriptor it is placed on, and anything
/// else the master execs does not.
fn bind(params: payloads::BindParams) -> Result<Value, Error> {
    let backlog = if params.backlog > 0 {
        params.backlog.min(i32::MAX as i64) as i32
    } else {
        crate::features::httpserver::listen::LISTEN_BACKLOG
    };

    let socket = crate::features::httpserver::listen::bind(&params.address, false, backlog)
        .map_err(|error| Error::Failed(format!("bind {}: {error}", params.address)))?;

    Ok(Value::Int(std::os::fd::IntoRawFd::into_raw_fd(socket) as i64))
}

fn close(params: payloads::CloseParams) -> Result<Value, Error> {
    if unsafe { libc::close(params.descriptor as libc::c_int) } != 0 {
        return Err(Error::Failed(format!(
            "close {}: {}",
            params.descriptor,
            std::io::Error::last_os_error()
        )));
    }

    Ok(Value::Nil)
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The two statuses a reap reports, built the way the kernel encodes them:
    /// the exit code in the second byte, the signal in the low seven bits.
    #[test]
    fn an_exit_code_and_a_signal_are_told_apart() {
        assert_eq!(classify(7 << 8), (Some(7), None));
        assert_eq!(classify(libc::SIGTERM), (None, Some(libc::SIGTERM as i64)));
    }

    /// A descriptor this process opened is listed; stdin, stdout and stderr never
    /// are, being every process's own.
    #[test]
    fn the_descriptors_above_stderr_are_listed() {
        use std::os::fd::AsRawFd;

        let file = std::fs::File::open("/dev/null").unwrap();
        let descriptor = file.as_raw_fd() as i64;

        let Value::List(listed) = fork::inherited_descriptors() else {
            panic!("not a list");
        };

        let numbers: Vec<i64> = listed
            .iter()
            .filter_map(|item| match item {
                Value::Int(number) => Some(*number),
                _ => None,
            })
            .collect();

        assert!(numbers.contains(&descriptor), "{numbers:?}");
        assert!(numbers.iter().all(|&number| number > 2), "{numbers:?}");
    }

    /// The message a command failed with as a ProcessException.
    fn failure(result: Result<Value, Error>) -> String {
        match result {
            Err(Error::Failed(message)) => message,
            Err(Error::Refused(message)) => panic!("refused as a fork: {message}"),
            Ok(_) => panic!("answered"),
        }
    }

    /// 0 and -1 name no single process: to kill(2) they are this process's group and
    /// every process it may signal, to waitpid(2) any child; 2^32 and 2^32 - 1 would
    /// become them in a cast to pid_t. The signal is CHLD, which nothing acts on by
    /// default, so that the test harms nobody where the check is missing.
    #[test]
    fn a_pid_that_names_no_single_process_is_refused() {
        for pid in [0, -1, 1 << 32, (1 << 32) - 1] {
            let killed = kill(payloads::KillParams {
                pid,
                signal: "CHLD".to_string(),
            });

            assert_eq!(failure(killed), format!("pid {pid} is not a child"));

            let reaped = reap(payloads::ReapParams { pids: vec![pid] });

            assert_eq!(failure(reaped), format!("pid {pid} is not a child"));
        }
    }

    /// A list with a bad pid in it is refused whole: the child ahead of it is not
    /// reaped by the refused call, so the next one still reports how it exited.
    #[test]
    fn a_refused_reap_reaps_none_of_its_pids() {
        let child = std::process::Command::new("true").spawn().unwrap();
        let pid = child.id() as i64;

        // Until it has exited, left unreaped.
        let mut info: libc::siginfo_t = unsafe { std::mem::zeroed() };
        let flags = libc::WEXITED | libc::WNOWAIT;

        assert_eq!(
            unsafe { libc::waitid(libc::P_PID, pid as libc::id_t, &mut info, flags) },
            0
        );

        let refused = reap(payloads::ReapParams { pids: vec![pid, 0] });

        assert_eq!(failure(refused), "pid 0 is not a child");

        let Ok(exited) = reap(payloads::ReapParams { pids: vec![pid] }) else {
            panic!("not reaped");
        };

        let first = exited.as_list().and_then(|exited| exited.first());

        assert_eq!(
            first.and_then(|outcome| outcome.get("cd")),
            Some(&Value::Int(0)),
            "the child was reaped by the refused call and reported by neither: {exited:?}"
        );
    }

    #[test]
    fn the_fork_parameters_decode_from_their_short_keys() {
        let payload: Value = Map::new()
            .set("kp", vec![Value::Int(5), Value::Int(9)])
            .set("io", true)
            .set("hb", false)
            .into();

        let params: payloads::ForkParams = decode(&payload).ok().unwrap();

        assert_eq!(params.keep, vec![5, 9]);
        assert!(params.capture_output);
        assert!(!params.heartbeat);
    }
}
