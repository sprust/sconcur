//! Binding the socket listener and the accept loop behind it.

use tokio::net::TcpListener;

pub fn open(address: &str, reuse_port: bool, listen_fd: Option<i64>) -> std::io::Result<TcpListener> {
    crate::features::httpserver::listen::open(address, reuse_port, listen_fd)
}
