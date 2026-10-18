//! The parameters of the socket client commands.
//!
//! Every message is a command envelope (`cm`/`p`) under the socketClient method:
//! `cm` selects the sub-operation, `p` carries that command's parameters. `p` is
//! a nested map, not a blob of its own — `'p' => $this->getParameters()->getData()`
//! on the PHP side — so it is decoded as a value and typed per command.

use serde::Deserialize;

use crate::dto::{Map, Value};

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

/// The `p` of a Connect — the remote address and the per-connection tuning.
/// There is no single "operation time": the connect timeout bounds the dial, the
/// read timeout the idle wait for an inbound frame, the write timeout one frame.
/// PHP: SConcur\Features\SocketClient\Payloads\ConnectPayloadParameters.
#[derive(Deserialize, Default)]
pub struct ConnectParams {
    #[serde(rename = "ad", default)]
    pub address: String,
    #[serde(rename = "ct", default)]
    pub connect_timeout_ms: i64,
    /// 0 disables it: a connection may stay idle forever.
    #[serde(rename = "rt", default)]
    pub read_timeout_ms: i64,
    #[serde(rename = "wt", default)]
    pub write_timeout_ms: i64,
    #[serde(rename = "mmb", default)]
    pub max_message_bytes: i64,
}

/// The `p` of a Send: the connection to write to and the frame bytes.
#[derive(Deserialize)]
pub struct SendParams {
    #[serde(rename = "cid", default)]
    pub connection_id: String,
    /// Binary-safe, so an untyped value rather than a String.
    #[serde(rename = "dt", default = "nil_value")]
    pub data: rmpv::Value,
}

impl SendParams {
    /// The frame's bytes, taken out of the params rather than copied out of
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

/// The `p` of a Close: the connection to close.
#[derive(Deserialize, Default)]
pub struct CloseParams {
    #[serde(rename = "cid", default)]
    pub connection_id: String,
}

/// The first result a Connect emits: the id used to route Send/Close, plus the
/// resolved addresses. Every result after it is a raw inbound frame.
pub struct ConnectionMeta {
    pub connection_id: String,
    pub remote_addr: String,
    pub local_addr: String,
}

impl ConnectionMeta {
    /// The array PHP receives: `cid`, `ra`, `la`, all strings.
    pub fn value(&self) -> Value {
        Map::with_capacity(3)
            .set("cid", &self.connection_id)
            .set("ra", &self.remote_addr)
            .set("la", &self.local_addr)
            .into()
    }
}
