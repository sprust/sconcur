//! Binding the socket listener and the accept loop behind it.

use tokio::net::TcpListener;

pub fn listen(address: &str, reuse_port: bool) -> std::io::Result<TcpListener> {
    crate::features::httpserver::listen::listen(address, reuse_port)
}
