//! The listener a server accepts on: bound here, with SO_REUSEPORT when the PHP
//! side asked for a process-per-core pool, or taken over from a descriptor
//! someone else bound — systemd's socket activation, say.

use socket2::{Domain, Protocol, Socket, Type};
use std::net::SocketAddr;
use std::os::fd::{FromRawFd, RawFd};
use tokio::net::{TcpListener, TcpStream};

/// Backlog for the pending-connection queue, asked of the kernel at bind time.
pub const LISTEN_BACKLOG: i32 = 4096;

/// What a server accepts from.
pub struct Listener {
    socket: TcpListener,
    /// Taken over from a descriptor someone else bound: the processes that hold it
    /// go on accepting from its queue after this one stops.
    shared: bool,
}

impl Listener {
    /// The next connection. Safe to drop half way, as a select! does: a
    /// connection is never lost to an accept that did not finish.
    pub async fn accept(&mut self) -> std::io::Result<(TcpStream, SocketAddr)> {
        self.socket.accept().await
    }

    /// The connections the kernel has queued on this socket and nobody has accepted
    /// yet, taken now, for a server that is about to close it. Closing a listening
    /// socket resets what is in its queue, and on an SO_REUSEPORT socket nothing
    /// moves it to a sibling — those are clients the kernel already sent to this
    /// process. None for a shared socket, whose queue the others still accept from.
    ///
    /// Taken with plain non-blocking accepts until the queue is empty, rather than
    /// through the runtime, whose readiness may not have caught up with the last
    /// arrivals. A connection that arrives between the last of them and the close is
    /// still reset; net.ipv4.tcp_migrate_req (Linux 5.14) is what hands those to a
    /// sibling.
    pub fn take_queued(&self) -> Vec<(TcpStream, SocketAddr)> {
        let mut queued = Vec::new();

        if self.shared {
            return queued;
        }

        let socket = socket2::SockRef::from(&self.socket);

        // The socket is non-blocking, as the runtime needs it: an empty queue is
        // WouldBlock. Anything else ends the taking as well.
        while let Ok((accepted, address)) = socket.accept() {
            let Some(address) = address.as_socket() else {
                continue;
            };

            let stream = std::net::TcpStream::from(accepted);

            if stream.set_nonblocking(true).is_err() {
                continue;
            }

            if let Ok(stream) = TcpStream::from_std(stream) {
                queued.push((stream, address));
            }
        }

        queued
    }
}

/// The listener a serve payload asks for: the descriptor it hands over, when it
/// hands one over, and otherwise a socket bound to its address.
pub fn open(address: &str, reuse_port: bool, listen_fd: Option<i64>) -> std::io::Result<Listener> {
    match listen_fd {
        Some(descriptor) => adopt(descriptor),
        None => Ok(Listener {
            socket: listen(address, reuse_port)?,
            shared: false,
        }),
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
/// It is polled like a socket of its own. Processes that share one socket all
/// wake for every connection, and the connections are spread over them unevenly;
/// a pool of processes on one port is better served by SO_REUSEPORT.
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

    socket.set_nonblocking(true)?;

    Ok(Listener {
        socket: TcpListener::from_std(socket.into())?,
        shared: true,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    /// What the kernel queued before the server took it is all there, and the socket
    /// is still the runtime's to accept from afterwards.
    #[tokio::test]
    async fn the_connections_queued_before_a_stop_are_taken_whole() {
        let listener = open("127.0.0.1:0", false, None).unwrap();
        let address = listener.socket.local_addr().unwrap();

        // Connected, and never accepted: the handshake completes in the kernel, which
        // queues them on the socket.
        let clients: Vec<_> = (0..5).map(|_| std::net::TcpStream::connect(address).unwrap()).collect();

        let queued = listener.take_queued();

        assert_eq!(queued.len(), clients.len(), "the queue was not taken whole");

        let mut peers: Vec<_> = queued.iter().map(|(_, peer)| *peer).collect();
        let mut locals: Vec<_> = clients.iter().map(|client| client.local_addr().unwrap()).collect();

        peers.sort();
        locals.sort();

        assert_eq!(peers, locals, "a taken connection is not one of the clients");
        assert!(listener.take_queued().is_empty(), "an empty queue gave something");
    }
}
