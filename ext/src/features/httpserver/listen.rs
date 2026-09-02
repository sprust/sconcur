//! Mirrors ext-go-legacy/internal/features/httpserver/listen.go: binding the listener,
//! with SO_REUSEPORT when the PHP side asked for a process-per-core pool.

use socket2::{Domain, Protocol, Socket, Type};
use std::net::SocketAddr;
use tokio::net::TcpListener;

/// Backlog for the pending-connection queue. Matches what Go's net.Listen asks
/// the kernel for (somaxconn), so the two servers queue accepts alike.
const LISTEN_BACKLOG: i32 = 4096;

pub fn listen(address: &str, reuse_port: bool) -> std::io::Result<TcpListener> {
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

    socket.set_nonblocking(true)?;
    socket.bind(&parsed.into())?;
    socket.listen(LISTEN_BACKLOG)?;

    TcpListener::from_std(socket.into())
}
