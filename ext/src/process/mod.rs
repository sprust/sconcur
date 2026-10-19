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
mod payloads;
mod signals;
mod threads;

use serde::Deserialize;

use crate::dto::{Map, Value};

/// How a command failed. `Refused` is a misuse the caller has to fix — forking a
/// process that already runs threads — and is raised as a LogicException;
/// `Failed` is the system saying no, raised as a RuntimeException.
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
        b"bnd" => bind(decode(payload)?),
        b"thr" => Ok(threads::count().map_or(Value::Nil, |count| Value::Int(count as i64))),
        other => Err(Error::Failed(format!(
            "unknown process command '{}'",
            String::from_utf8_lossy(other)
        ))),
    }
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
    let signal = signals::number(&params.signal)?;

    if unsafe { libc::kill(params.pid as libc::pid_t, signal) } == 0 {
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
fn reap(params: payloads::ReapParams) -> Result<Value, Error> {
    let mut exited = Vec::new();

    for pid in params.pids {
        let mut status: libc::c_int = 0;

        let answer = unsafe { libc::waitpid(pid as libc::pid_t, &mut status, libc::WNOHANG) };

        if answer == 0 {
            continue;
        }

        if answer < 0 {
            let error = std::io::Error::last_os_error();

            match error.raw_os_error() {
                // Asked again on the next tick.
                Some(libc::EINTR) => continue,
                Some(libc::ECHILD) => {
                    exited.push(outcome(pid, None, None));

                    continue;
                }
                _ => return Err(Error::Failed(format!("waitpid {pid}: {error}"))),
            }
        }

        let (code, signal) = classify(status);

        exited.push(outcome(pid, code, signal));
    }

    Ok(Value::List(exited))
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
