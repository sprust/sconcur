//! Binding the socket listener and the accept loop behind it.

use crate::features::httpserver::listen::Listener;

pub fn open(address: &str, reuse_port: bool, listen_fd: Option<i64>) -> std::io::Result<Listener> {
    crate::features::httpserver::listen::open(address, reuse_port, listen_fd)
}
