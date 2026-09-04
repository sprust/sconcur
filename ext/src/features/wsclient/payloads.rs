//! The parameters of the WebSocket client commands. The envelope is the socket
//! client's: `cm` selects the sub-operation, `p` carries a nested map of that
//! command's parameters.

use serde::Deserialize;

#[derive(Deserialize)]
pub struct Envelope {
    #[serde(rename = "cm", default)]
    pub command: String,
    #[serde(rename = "p", default = "nil_value")]
    pub params: rmpv::Value,
}

fn nil_value() -> rmpv::Value {
    rmpv::Value::Nil
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
    #[serde(rename = "dt", default = "nil_value")]
    pub data: rmpv::Value,
}

impl SendParams {
    /// The message's bytes, taken out of the params rather than copied out of
    /// them: the decoded value already owns them and nothing reads `data`
    /// again.
    pub fn take_data_bytes(&mut self) -> Vec<u8> {
        match std::mem::replace(&mut self.data, rmpv::Value::Nil) {
            rmpv::Value::String(text) => text.into_bytes(),
            rmpv::Value::Binary(bytes) => bytes,
            _ => Vec::new(),
        }
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
    pub fn encode(&self) -> Vec<u8> {
        use rmp::encode;

        let mut buffer = Vec::with_capacity(128);

        let _ = encode::write_map_len(&mut buffer, 4);

        for (key, value) in [
            ("cid", &self.connection_id),
            ("ra", &self.remote_addr),
            ("la", &self.local_addr),
            ("su", &self.subprotocol),
        ] {
            let _ = encode::write_str(&mut buffer, key);
            let _ = encode::write_str(&mut buffer, value);
        }

        buffer
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
