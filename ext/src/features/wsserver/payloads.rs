//! Mirrors ext-go-legacy/internal/features/wsserver/payloads.

use serde::Deserialize;

/// The payload of a wsServe command. The listener is an HTTP server: a request
/// carrying a valid WebSocket upgrade becomes a streamed connection, anything
/// else is answered 426 Upgrade Required.
/// PHP: SConcur\Features\WsServer\Payloads\ServePayload.
#[allow(dead_code)]
#[derive(Deserialize, Default)]
pub struct ServePayload {
    #[serde(rename = "ad", default)]
    pub address: String,
    #[serde(rename = "hst", default)]
    pub handshake_timeout_ms: i64,
    /// The idle timeout between inbound messages. 0 disables it — a connection
    /// may stay idle forever, kept alive by the server ping.
    #[serde(rename = "it", default)]
    pub idle_timeout_ms: i64,
    #[serde(rename = "wt", default)]
    pub write_timeout_ms: i64,
    /// The server keepalive ping cadence (0 = disabled).
    #[serde(rename = "pi", default)]
    pub ping_interval_ms: i64,
    /// Caps one inbound message; an oversize one closes the connection with 1009.
    #[serde(rename = "mmb", default)]
    pub max_message_bytes: i64,
    #[serde(rename = "mc", default)]
    pub max_concurrency: i64,
    #[serde(rename = "sht", default)]
    pub shutdown_timeout_ms: i64,
    #[serde(rename = "rp", default)]
    pub reuse_port: bool,
    /// Restricts the upgrade endpoint to this path (empty = any path).
    #[serde(rename = "pt", default)]
    pub path: String,
    /// Host patterns the origin check accepts (empty = the check is skipped).
    #[serde(rename = "ao", default)]
    pub allowed_origins: Vec<String>,
    /// Subprotocols the server negotiates (empty = none).
    #[serde(rename = "sp", default)]
    pub subprotocols: Vec<String>,
    #[serde(rename = "ts", default)]
    pub telemetry_socket: String,
    #[serde(rename = "sn", default)]
    pub server_name: String,
    #[serde(rename = "ti", default)]
    pub telemetry_interval_ms: i64,
}

/// The payload of a wsRespond command. `op` selects the kind (0 write, 1 close);
/// `mt` selects the message type of a written message (0 text, 1 binary).
/// PHP: SConcur\Features\WsServer\Payloads\RespondPayload.
#[derive(Deserialize)]
pub struct RespondPayload {
    #[serde(rename = "cid", default)]
    pub connection_id: String,
    #[serde(rename = "op", default)]
    pub op: i64,
    #[serde(rename = "mt", default)]
    pub message_type: i64,
    /// Arbitrary bytes: a binary message is not required to be valid UTF-8.
    #[serde(rename = "dt", default = "nil_value")]
    pub data: rmpv::Value,
}

fn nil_value() -> rmpv::Value {
    rmpv::Value::Nil
}

impl RespondPayload {
    /// The message's bytes, taken out of the payload rather than copied out of
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

/// What the server emits to PHP for each upgraded connection.
pub struct ConnectionEvent {
    pub connection_id: String,
    pub remote_addr: String,
    pub local_addr: String,
    pub path: String,
    pub subprotocol: String,
}

impl ConnectionEvent {
    pub fn encode(&self) -> Vec<u8> {
        use rmp::encode;

        let mut buffer = Vec::with_capacity(128);

        let _ = encode::write_map_len(&mut buffer, 5);

        for (key, value) in [
            ("cid", &self.connection_id),
            ("ra", &self.remote_addr),
            ("la", &self.local_addr),
            ("pa", &self.path),
            ("su", &self.subprotocol),
        ] {
            let _ = encode::write_str(&mut buffer, key);
            let _ = encode::write_str(&mut buffer, value);
        }

        buffer
    }
}
