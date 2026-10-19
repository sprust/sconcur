//! The parameters of the WebSocket client commands. The envelope is the socket
//! client's: `cm` selects the sub-operation, `p` carries a nested array of that
//! command's parameters, decoded once the command is known.

use serde::Deserialize;

use crate::dto::{Borrowed, Map, Value};

#[derive(Deserialize)]
pub struct Envelope<'a> {
    #[serde(rename = "cm", default)]
    pub command: String,
    #[serde(rename = "p", borrow, default = "crate::dto::value_de::borrowed_nil")]
    pub params: Borrowed<'a>,
}

/// The `p` of a Connect — the ws:// URL and the per-connection tuning.
/// PHP: SConcur\Features\WsClient\Payloads\ConnectPayloadParameters.
#[derive(Deserialize, Default)]
pub struct ConnectParams {
    #[serde(rename = "ad", default)]
    pub address: String,
    /// Bounds the dial and the handshake together.
    #[serde(rename = "ct", default)]
    pub connect_timeout_ms: i64,
    /// 0 disables it: a connection may stay idle forever.
    #[serde(rename = "rt", default)]
    pub read_timeout_ms: i64,
    #[serde(rename = "wt", default)]
    pub write_timeout_ms: i64,
    #[serde(rename = "mmb", default)]
    pub max_message_bytes: i64,
    #[serde(rename = "sp", default)]
    pub subprotocols: Vec<String>,
}

/// The `p` of a Send: the connection, the message type (0 text, 1 binary) and
/// the bytes.
#[derive(Deserialize)]
pub struct SendParams {
    #[serde(rename = "cid", default)]
    pub connection_id: String,
    #[serde(rename = "mt", default)]
    pub message_type: i64,
    /// The bytes of the PHP string: a binary message is not required to be
    /// valid UTF-8, so not a `String`.
    #[serde(rename = "dt", default, deserialize_with = "crate::dto::value_de::bytes")]
    pub data: Vec<u8>,
}

impl SendParams {
    /// The message's bytes, taken out of the params rather than copied out of
    /// them: the decoded value already owns them and nothing reads `data`
    /// again.
    pub fn take_data_bytes(&mut self) -> Vec<u8> {
        std::mem::take(&mut self.data)
    }
}

#[derive(Deserialize, Default)]
pub struct CloseParams {
    #[serde(rename = "cid", default)]
    pub connection_id: String,
}

/// The first result a Connect emits. Every result after it is an inbound
/// message.
pub struct ConnectionMeta {
    pub connection_id: String,
    pub remote_addr: String,
    pub local_addr: String,
    pub subprotocol: String,
}

impl ConnectionMeta {
    /// The array PHP receives: `cid`, `ra`, `la`, `su`, all strings.
    pub fn value(&self) -> Value {
        Map::with_capacity(4)
            .set("cid", &self.connection_id)
            .set("ra", &self.remote_addr)
            .set("la", &self.local_addr)
            .set("su", &self.subprotocol)
            .into()
    }
}

/// Splits a `ws://host[:port][/path]` address into what the dial and the
/// handshake each need. No URL crate for one scheme: the PHP side documents
/// ws:// only, and a hand-written split keeps the failure message specific.
pub fn split_address(address: &str) -> Result<(String, String, String), String> {
    let rest = address
        .strip_prefix("ws://")
        .ok_or_else(|| format!("unsupported address {address}: only ws:// is supported"))?;

    let (authority, path) = match rest.find('/') {
        Some(index) => (&rest[..index], &rest[index..]),
        None => (rest, "/"),
    };

    if authority.is_empty() {
        return Err(format!("address {address} has no host"));
    }

    let host = match authority.rfind(':') {
        Some(_) => authority.to_string(),
        // The default WebSocket port, so a host-only address still dials.
        None => format!("{authority}:80"),
    };

    Ok((host, authority.to_string(), path.to_string()))
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The map WsClient::connect reads into a Connection.
    #[test]
    fn a_connection_meta_carries_the_addresses_and_the_subprotocol() {
        let value = ConnectionMeta {
            connection_id: "c-1".to_string(),
            remote_addr: "10.0.0.1:5000".to_string(),
            local_addr: "10.0.0.2:6000".to_string(),
            subprotocol: "chat".to_string(),
        }
        .value();

        assert_eq!(value.get("cid").and_then(Value::as_str), Some("c-1"));
        assert_eq!(value.get("ra").and_then(Value::as_str), Some("10.0.0.1:5000"));
        assert_eq!(value.get("la").and_then(Value::as_str), Some("10.0.0.2:6000"));
        assert_eq!(value.get("su").and_then(Value::as_str), Some("chat"));
        assert_eq!(value.as_map().unwrap().len(), 4);
    }
}
