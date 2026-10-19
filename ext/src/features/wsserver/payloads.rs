//! The parameters of the WebSocket server commands.

use serde::Deserialize;

use crate::dto::{Map, Value};

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
    /// A listening socket bound elsewhere, to accept on instead of binding the
    /// address: a worker master's shared socket, or one from socket activation.
    #[serde(rename = "lfd", default)]
    pub listen_fd: Option<i64>,
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
    /// The bytes of the PHP string: a binary message is not required to be
    /// valid UTF-8, so not a `String`.
    #[serde(rename = "dt", default, deserialize_with = "crate::dto::value_de::bytes")]
    pub data: Vec<u8>,
}

impl RespondPayload {
    /// The message's bytes, taken out of the payload rather than copied out of
    /// it: the decoded value already owns them and nothing reads `data` again.
    pub fn take_data_bytes(&mut self) -> Vec<u8> {
        std::mem::take(&mut self.data)
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
    /// The array PHP receives: `cid`, `ra`, `la`, `pa`, `su`, all strings.
    pub fn value(&self) -> Value {
        Map::with_capacity(5)
            .set("cid", &self.connection_id)
            .set("ra", &self.remote_addr)
            .set("la", &self.local_addr)
            .set("pa", &self.path)
            .set("su", &self.subprotocol)
            .into()
    }
}
