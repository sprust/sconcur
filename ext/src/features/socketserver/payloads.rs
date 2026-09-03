//! Mirrors ext-go-legacy/internal/features/socketserver/payloads.

use serde::Deserialize;

/// The payload of a socketServe command — the listener address and the server
/// tuning (timeouts in milliseconds, sizes in bytes).
///
/// As with the HTTP server, every field is decoded but not all are acted on:
/// `max_concurrency`, `shutdown_timeout_ms` and the telemetry block are accepted
/// and ignored in this port. A scope limit worth knowing before comparing
/// anything but throughput against the Go server.
/// PHP: SConcur\Features\SocketServer\Payloads\ServePayload.
#[allow(dead_code)]
#[derive(Deserialize, Default)]
pub struct ServePayload {
    #[serde(rename = "ad", default)]
    pub address: String,
    /// The idle timeout between messages (no frame within → close). 0 disables it.
    #[serde(rename = "rt", default)]
    pub read_timeout_ms: i64,
    #[serde(rename = "wt", default)]
    pub write_timeout_ms: i64,
    /// Caps the length of a single inbound frame, guarding against a huge length
    /// prefix.
    #[serde(rename = "mmb", default)]
    pub max_message_bytes: i64,
    #[serde(rename = "mc", default)]
    pub max_concurrency: i64,
    #[serde(rename = "sht", default)]
    pub shutdown_timeout_ms: i64,
    #[serde(rename = "rp", default)]
    pub reuse_port: bool,
    #[serde(rename = "ts", default)]
    pub telemetry_socket: String,
    #[serde(rename = "sn", default)]
    pub server_name: String,
    #[serde(rename = "ti", default)]
    pub telemetry_interval_ms: i64,
}

/// The payload of a socketRespond command — one action a PHP connection handler
/// performs. `op` selects the kind (0 write a length-prefixed frame, 1 close).
/// PHP: SConcur\Features\SocketServer\Payloads\RespondPayload.
#[derive(Deserialize)]
pub struct RespondPayload {
    #[serde(rename = "cid", default)]
    pub connection_id: String,
    #[serde(rename = "op", default)]
    pub op: i64,
    /// Arbitrary bytes, so an untyped value rather than a String — a frame is
    /// not required to be valid UTF-8.
    #[serde(rename = "dt", default = "nil_value")]
    pub data: rmpv::Value,
}

fn nil_value() -> rmpv::Value {
    rmpv::Value::Nil
}

impl RespondPayload {
    /// The frame's bytes, taken out of the payload rather than copied out of
    /// it: the decoded value already owns them and nothing reads `data` again.
    pub fn take_data_bytes(&mut self) -> Vec<u8> {
        match std::mem::replace(&mut self.data, rmpv::Value::Nil) {
            rmpv::Value::String(text) => text.into_bytes(),
            rmpv::Value::Binary(bytes) => bytes,
            _ => Vec::new(),
        }
    }
}

/// The fallback when the full payload does not decode: a struct with only this
/// field ignores every other key, so a malformed response is still named for
/// what is wrong with it. The full payload carries `cid` too, so the happy path
/// decodes once and never reaches this.
#[derive(Deserialize, Default)]
pub struct RespondConnectionId {
    #[serde(rename = "cid", default)]
    pub connection_id: String,
}

/// What the server emits to PHP for each accepted connection. PHP decodes it
/// inline in SocketServer::handleConnection.
pub struct ConnectionEvent {
    pub connection_id: String,
    pub remote_addr: String,
    pub local_addr: String,
}

impl ConnectionEvent {
    pub fn encode(&self) -> Vec<u8> {
        use rmp::encode;

        let mut buffer = Vec::with_capacity(96);

        let _ = encode::write_map_len(&mut buffer, 3);

        for (key, value) in [
            ("cid", &self.connection_id),
            ("ra", &self.remote_addr),
            ("la", &self.local_addr),
        ] {
            let _ = encode::write_str(&mut buffer, key);
            let _ = encode::write_str(&mut buffer, value);
        }

        buffer
    }
}
