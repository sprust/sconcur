//! The listener a server accepts on: bound here, always with SO_REUSEPORT, so the
//! workers of a pool share one port and the kernel spreads the connections over
//! them.

use socket2::{Domain, Protocol, Socket, Type};
use std::net::SocketAddr;
use tokio::net::{TcpListener, TcpStream};

/// Backlog for the pending-connection queue, asked of the kernel at bind time.
pub const LISTEN_BACKLOG: i32 = 4096;

/// What a server accepts from.
pub struct Listener {
    socket: TcpListener,
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
    /// process.
    ///
    /// Taken with plain non-blocking accepts until the queue is empty, rather than
    /// through the runtime, whose readiness may not have caught up with the last
    /// arrivals. A connection that arrives between the last of them and the close is
    /// still reset; net.ipv4.tcp_migrate_req (Linux 5.14) is what hands those to a
    /// sibling.
    pub fn take_queued(&self) -> Vec<(TcpStream, SocketAddr)> {
        let mut queued = Vec::new();

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

/// The listener a serve payload asks for: a socket bound to its address.
pub fn open(address: &str) -> std::io::Result<Listener> {
    let socket = bind(address, LISTEN_BACKLOG)?;

    socket.set_nonblocking(true)?;

    Ok(Listener {
        socket: TcpListener::from_std(socket.into())?,
    })
}

/// A listening TCP socket with SO_REUSEADDR and SO_REUSEPORT. SO_REUSEPORT always:
/// every worker of a pool binds the port itself, and a server running alone loses
/// nothing by it. What it gives up is the refusal a second process on the port
/// would otherwise get — one started by mistake shares the port instead
/// (docs/http-server.md). Close-on-exec, as socket2 creates every socket.
fn bind(address: &str, backlog: i32) -> std::io::Result<Socket> {
    let parsed: SocketAddr = address
        .parse()
        .map_err(|error| std::io::Error::new(std::io::ErrorKind::InvalidInput, format!("{error}")))?;

    let socket = Socket::new(
        Domain::for_address(parsed),
        Type::STREAM,
        Some(Protocol::TCP),
    )?;

    socket.set_reuse_address(true)?;
    socket.set_reuse_port(true)?;

    socket.bind(&parsed.into())?;
    socket.listen(backlog)?;

    Ok(socket)
}

#[cfg(test)]
mod tests {
    use super::*;

    /// What the kernel queued before the server took it is all there, and the socket
    /// is still the runtime's to accept from afterwards.
    #[tokio::test]
    async fn the_connections_queued_before_a_stop_are_taken_whole() {
        let listener = open("127.0.0.1:0").unwrap();
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
