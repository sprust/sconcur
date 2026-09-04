//! One client per distinct
//! tuning, reused across requests.
//!
//! Building a client per request would build a connection pool per request, so
//! nothing would ever be kept alive, which is what this cache is for.

use reqwest::Client;
use std::collections::HashMap;
use std::sync::Mutex;
use std::time::Duration;

use super::payloads::RequestParams;

const DEFAULT_CONNECT_TIMEOUT_MS: i64 = 10_000;
const DEFAULT_IDLE_CONN_TIMEOUT_MS: i64 = 90_000;
const DEFAULT_TLS_HANDSHAKE_TIMEOUT_MS: i64 = 10_000;
const DEFAULT_MAX_IDLE_CONNS_PER_HOST: i64 = 2;

/// The settings that decide what a client *is*. Two requests differing only in
/// their per-request deadline share one; differing in redirects or TLS
/// verification do not.
#[derive(PartialEq, Eq, Hash, Clone)]
struct ClientKey {
    follow_redirects: bool,
    max_redirects: i64,
    verify_tls: bool,
    connect_timeout_ms: i64,
    max_idle_conns_per_host: i64,
    idle_conn_timeout_ms: i64,
    tls_handshake_timeout_ms: i64,
}

pub struct Clients {
    clients: Mutex<HashMap<ClientKey, Client>>,
}

impl Clients {
    pub fn new() -> Self {
        Clients {
            clients: Mutex::new(HashMap::new()),
        }
    }

    /// A streamed body cannot be replayed, so a redirect would fail opaquely
    /// mid-upload, so the caller passes `follow_redirects: false` for those.
    pub fn get(&self, params: &RequestParams, follow_redirects: bool) -> Result<Client, String> {
        let key = ClientKey {
            follow_redirects,
            max_redirects: params.max_redirects,
            verify_tls: params.verify_tls,
            connect_timeout_ms: params.connect_timeout_ms,
            max_idle_conns_per_host: params.max_idle_conns_per_host,
            idle_conn_timeout_ms: params.idle_conn_timeout_ms,
            tls_handshake_timeout_ms: params.tls_handshake_timeout_ms,
        };

        if let Some(client) = self.clients.lock().unwrap().get(&key) {
            return Ok(client.clone());
        }

        let client = build(&key)?;

        Ok(self
            .clients
            .lock()
            .unwrap()
            .entry(key)
            .or_insert(client)
            .clone())
    }

    /// Mirrors CloseIdleConnections: called on extension shutdown. Dropping the
    /// handles closes each pool once the last clone goes.
    pub fn close_idle(&self) {
        self.clients.lock().unwrap().clear();
    }
}

fn build(key: &ClientKey) -> Result<Client, String> {
    let redirects = if key.follow_redirects {
        reqwest::redirect::Policy::limited(key.max_redirects.max(0) as usize)
    } else {
        reqwest::redirect::Policy::none()
    };

    let mut builder = Client::builder()
        .redirect(redirects)
        .connect_timeout(Duration::from_millis(
            or_default(key.connect_timeout_ms, DEFAULT_CONNECT_TIMEOUT_MS) as u64,
        ))
        .pool_idle_timeout(Duration::from_millis(or_default(
            key.idle_conn_timeout_ms,
            DEFAULT_IDLE_CONN_TIMEOUT_MS,
        ) as u64))
        .pool_max_idle_per_host(or_default(
            key.max_idle_conns_per_host,
            DEFAULT_MAX_IDLE_CONNS_PER_HOST,
        ) as usize);

    if !key.verify_tls {
        // Deliberate and driven by the PHP side, which exposes it for
        // self-signed certificates in development.
        builder = builder
            .danger_accept_invalid_certs(true)
            .danger_accept_invalid_hostnames(true);
    }

    // reqwest has no separate handshake timeout; the connect timeout above is
    // the closest bound, and this keeps the field from silently doing nothing.
    let _ = or_default(
        key.tls_handshake_timeout_ms,
        DEFAULT_TLS_HANDSHAKE_TIMEOUT_MS,
    );

    builder.build().map_err(|error| error.to_string())
}

fn or_default(value: i64, fallback: i64) -> i64 {
    if value > 0 { value } else { fallback }
}
