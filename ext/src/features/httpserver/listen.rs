//! The listener a server accepts on: bound here, with SO_REUSEPORT when the PHP
//! side asked for a process-per-core pool, or taken over from a descriptor
//! someone else bound — a worker master sharing one socket across its workers,
//! or systemd's socket activation.

use socket2::{Domain, Protocol, Socket, Type};
use std::net::SocketAddr;
use std::os::fd::{FromRawFd, RawFd};
use tokio::net::{TcpListener, TcpStream};

/// Backlog for the pending-connection queue, asked of the kernel at bind time.
pub const LISTEN_BACKLOG: i32 = 4096;

/// What a server accepts from.
pub struct Listener(Accepting);

enum Accepting {
    /// A socket of this process alone, accepted on the runtime.
    Polled(TcpListener),
    /// A socket shared with other processes, accepted by a thread of its own.
    #[cfg(target_os = "linux")]
    Shared(super::exclusive::Acceptor),
}

impl Listener {
    /// The next connection. Safe to drop half way, as a select! does: a
    /// connection is never lost to an accept that did not finish.
    pub async fn accept(&mut self) -> std::io::Result<(TcpStream, SocketAddr)> {
        match &mut self.0 {
            Accepting::Polled(listener) => listener.accept().await,
            #[cfg(target_os = "linux")]
            Accepting::Shared(acceptor) => acceptor.accept().await,
        }
    }
}

/// The listener a serve payload asks for: the descriptor it hands over, when it
/// hands one over, and otherwise a socket bound to its address.
pub fn open(address: &str, reuse_port: bool, listen_fd: Option<i64>) -> std::io::Result<Listener> {
    match listen_fd {
        Some(descriptor) => adopt(descriptor),
        None => Ok(Listener(Accepting::Polled(listen(address, reuse_port)?))),
    }
}

fn listen(address: &str, reuse_port: bool) -> std::io::Result<TcpListener> {
    let socket = bind(address, reuse_port, LISTEN_BACKLOG)?;

    socket.set_nonblocking(true)?;

    TcpListener::from_std(socket.into())
}

/// A listening TCP socket: SO_REUSEADDR always, SO_REUSEPORT when asked. Left
/// blocking — the caller decides — and close-on-exec, as socket2 creates every
/// socket.
pub fn bind(address: &str, reuse_port: bool, backlog: i32) -> std::io::Result<Socket> {
    let parsed: SocketAddr = address
        .parse()
        .map_err(|error| std::io::Error::new(std::io::ErrorKind::InvalidInput, format!("{error}")))?;

    let socket = Socket::new(
        Domain::for_address(parsed),
        Type::STREAM,
        Some(Protocol::TCP),
    )?;

    socket.set_reuse_address(true)?;

    if reuse_port {
        socket.set_reuse_port(true)?;
    }

    socket.bind(&parsed.into())?;
    socket.listen(backlog)?;

    Ok(socket)
}

/// Takes over a listening socket bound elsewhere.
///
/// A copy of the descriptor is what is taken over, so the original stays open
/// for the life of the process: stopping this listener closes the copy, the
/// socket goes on accepting for whoever else holds it, and a second serve in the
/// same process can take it over again. The copy is close-on-exec, so nothing a
/// handler execs inherits the listener.
///
/// Other processes accept on the socket too, so on Linux it is accepted by a
/// thread that takes turns with them (see `exclusive`). Elsewhere it is polled
/// like a socket of its own, and every worker wakes for every connection.
fn adopt(descriptor: i64) -> std::io::Result<Listener> {
    let descriptor = RawFd::try_from(descriptor)
        .map_err(|_| std::io::Error::new(std::io::ErrorKind::InvalidInput, format!("no descriptor {descriptor}")))?;

    let copy = unsafe { libc::fcntl(descriptor, libc::F_DUPFD_CLOEXEC, 0) };

    if copy < 0 {
        return Err(std::io::Error::other(format!(
            "descriptor {descriptor}: {}",
            std::io::Error::last_os_error()
        )));
    }

    let socket = unsafe { Socket::from_raw_fd(copy) };

    // A descriptor that is open but is no listening socket — a file, a connected
    // socket, a typo in a number — would otherwise fail on the first accept, as an
    // error that says nothing about the setting that caused it.
    if !socket.is_listener().unwrap_or(false) {
        return Err(std::io::Error::new(
            std::io::ErrorKind::InvalidInput,
            format!("descriptor {descriptor} is not a listening socket"),
        ));
    }

    #[cfg(target_os = "linux")]
    {
        Ok(Listener(Accepting::Shared(super::exclusive::Acceptor::start(socket)?)))
    }

    #[cfg(not(target_os = "linux"))]
    {
        socket.set_nonblocking(true)?;

        Ok(Listener(Accepting::Polled(TcpListener::from_std(socket.into())?)))
    }
}
