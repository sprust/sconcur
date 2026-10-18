//! The parameters of the socket server commands.

use serde::Deserialize;

use crate::dto::{Map, Value};

/// The payload of a socketServe command — the listener address and the server
/// tuning (timeouts in milliseconds, sizes in bytes).
///
/// Every field is decoded but not all are acted on: `shutdown_timeout_ms` is
/// accepted and not applied.
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
    /// The bytes of the PHP string, whatever they are: a frame is not required
    /// to be valid UTF-8, so not a `String`.
    #[serde(rename = "dt", default, deserialize_with = "crate::dto::value_de::bytes")]
    pub data: Vec<u8>,
}

impl RespondPayload {
    /// The frame's bytes, taken out of the payload rather than copied out of
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

/// What the server emits to PHP for each accepted connection. PHP reads it
/// inline in SocketServer::handleConnection.
pub struct ConnectionEvent {
    pub connection_id: String,
    pub remote_addr: String,
    pub local_addr: String,
}

impl ConnectionEvent {
    /// The array PHP receives: `cid`, `ra`, `la`, all strings.
    pub fn value(&self) -> Value {
        Map::with_capacity(3)
            .set("cid", &self.connection_id)
            .set("ra", &self.remote_addr)
            .set("la", &self.local_addr)
            .into()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The map SocketServer::handleConnection reads into a Connection.
    #[test]
    fn a_connection_event_carries_the_three_addresses() {
        let value = ConnectionEvent {
            connection_id: "c-1".to_string(),
            remote_addr: "10.0.0.1:5000".to_string(),
            local_addr: "0.0.0.0:9000".to_string(),
        }
        .value();

        assert_eq!(value.get("cid").and_then(Value::as_str), Some("c-1"));
        assert_eq!(value.get("ra").and_then(Value::as_str), Some("10.0.0.1:5000"));
        assert_eq!(value.get("la").and_then(Value::as_str), Some("0.0.0.0:9000"));
        assert_eq!(value.as_map().unwrap().len(), 3);
    }
}
