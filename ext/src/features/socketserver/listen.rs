//! Mirrors ext-go-legacy/internal/features/socketserver/listen.go.

use tokio::net::TcpListener;

pub fn listen(address: &str, reuse_port: bool) -> std::io::Result<TcpListener> {
    crate::features::httpserver::listen::listen(address, reuse_port)
}
