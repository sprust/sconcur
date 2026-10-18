//! The parameters of the socket client commands.
//!
//! Every message is a command envelope (`cm`/`p`) under the socketClient method:
//! `cm` selects the sub-operation, `p` carries that command's parameters. `p` is
//! a nested array — `'p' => $this->getParameters()->getData()` on the PHP side —
//! so it stays an untyped value until the command is known, then is decoded into
//! that command's struct.

use serde::Deserialize;

use crate::dto::{Map, Value};

#[derive(Deserialize)]
pub struct Envelope {
    #[serde(rename = "cm", default)]
    pub command: String,
    #[serde(rename = "p", default = "nil_value")]
    pub params: Value,
}

/// `Value` has no `Default`, so a missing `p` is spelled out as null.
fn nil_value() -> Value {
    Value::Nil
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
    /// The bytes of the PHP string, whatever they are: a frame is not required
    /// to be valid UTF-8, so not a `String`.
    #[serde(rename = "dt", default, deserialize_with = "crate::dto::value_de::bytes")]
    pub data: Vec<u8>,
}

impl SendParams {
    /// The frame's bytes, taken out of the params rather than copied out of
    /// them: the decoded value already owns them and nothing reads `data`
    /// again.
    pub fn take_data_bytes(&mut self) -> Vec<u8> {
        std::mem::take(&mut self.data)
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
