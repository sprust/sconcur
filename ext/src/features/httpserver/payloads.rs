//! The Rust counterparts of
//! the PHP HTTP-server payload objects and the request event the server streams
//! back to PHP. The renames are the short keys exchanged with the PHP side.

use serde::Deserialize;

use crate::dto::{Map, Value};

/// A missing body decodes to nil, which reads back as no bytes.
fn nil_value() -> rmpv::Value {
    rmpv::Value::Nil
}
use std::collections::HashMap;

/// The payload of an httpStart command — the listener address and the server
/// tuning parameters (all timeouts in milliseconds, body limit in bytes).
/// PHP: SConcur\Features\HttpServer\Payloads\ServePayload.
///
/// Every field of the wire payload is decoded, but the spike only acts on
/// `address`, `reuse_port` and `handler_timeout_ms`. The read/write/idle
/// timeouts, the body limit, `max_concurrency` and the telemetry block are
/// accepted and ignored — a scope limit worth knowing before reading anything
/// but the ladder into a comparison.
#[allow(dead_code)]
#[derive(Deserialize, Default)]
pub struct ServePayload {
    #[serde(rename = "ad", default)]
    pub address: String,
    #[serde(rename = "rht", default)]
    pub read_header_timeout_ms: i64,
    #[serde(rename = "rt", default)]
    pub read_timeout_ms: i64,
    #[serde(rename = "wt", default)]
    pub write_timeout_ms: i64,
    #[serde(rename = "it", default)]
    pub idle_timeout_ms: i64,
    #[serde(rename = "sht", default)]
    pub shutdown_timeout_ms: i64,
    #[serde(rename = "mrb", default)]
    pub max_request_body: i64,
    #[serde(rename = "mc", default)]
    pub max_concurrency: i64,
    #[serde(rename = "hto", default)]
    pub handler_timeout_ms: i64,
    #[serde(rename = "rp", default)]
    pub reuse_port: bool,
    #[serde(rename = "ts", default)]
    pub telemetry_socket: String,
    #[serde(rename = "sn", default)]
    pub server_name: String,
    #[serde(rename = "ti", default)]
    pub telemetry_interval_ms: i64,
}

/// The payload of an httpRespond command — one write a PHP request-handler
/// coroutine sends back for a given request.
///
/// `hd` and `nr` carry `default`: the PHP side omits both when empty (an empty
/// PHP array would encode as a MessagePack array, not a map — see the comment
/// in RespondPayload::getData).
/// PHP: SConcur\Features\HttpServer\Payloads\RespondPayload.
#[derive(Deserialize)]
pub struct RespondPayload {
    #[serde(rename = "rid", default)]
    pub request_id: String,
    #[serde(rename = "op", default)]
    pub op: i64,
    #[serde(rename = "st", default)]
    pub status: i64,
    #[serde(rename = "hd", default)]
    pub headers: HashMap<String, Vec<String>>,
    /// Kept as an untyped value rather than a String: a response body is
    /// arbitrary bytes, and PHP sends it as a MessagePack str whatever it
    /// holds. Decoding it into a String mangles — or refuses — any body that is
    /// not valid UTF-8, which is what HttpServerRequestTest's binary round trip
    /// catches.
    #[serde(rename = "bd", default = "nil_value")]
    pub body: rmpv::Value,
    /// Marks a fire-and-forget write: publish no task result for it (the PHP
    /// coroutine does not await one — the final write of a full response).
    #[serde(rename = "nr", default)]
    pub no_result: bool,
}

impl RespondPayload {
    /// The body as raw bytes, whether PHP sent text or binary, taken out of the
    /// payload rather than copied out of it.
    ///
    /// The value already owns those bytes: they were allocated when the payload
    /// was decoded and nothing reads `body` again afterwards. Cloning them made
    /// a 100 KB response cross as three copies — the C buffer, the decoded
    /// value, the write command — where two will do. The rest of the struct is
    /// still needed at the call site (the headers go into the same command),
    /// which is why this takes `&mut self` and leaves nil behind instead of
    /// consuming the whole payload.
    pub fn take_body_bytes(&mut self) -> Vec<u8> {
        match std::mem::replace(&mut self.body, rmpv::Value::Nil) {
            rmpv::Value::String(text) => text.into_bytes(),
            rmpv::Value::Binary(bytes) => bytes,
            rmpv::Value::Nil => Vec::new(),
            other => other.to_string().into_bytes(),
        }
    }
}

/// The fallback when the full payload does not decode: a struct with only this
/// field ignores every other key, so a response can still be routed back — and
/// the client answered — when the rest of the payload is malformed.
///
/// The full payload carries `rid` too, so the happy path decodes once and this
/// struct is only reached when that failed — rather than decoding the id first
/// and the payload second on every response.
#[derive(Deserialize, Default)]
pub struct RespondRequestId {
    #[serde(rename = "rid", default)]
    pub request_id: String,
}

/// What the server emits to PHP for each accepted request. PHP decodes it into
/// SConcur\Features\HttpServer\Dto\Request.
pub struct RequestEvent {
    pub request_id: String,
    pub method: String,
    pub path: String,
    pub query: String,
    pub headers: Vec<(String, Vec<String>)>,
    /// The inline first chunk of the request body. `body_key` is the streaming
    /// state key for the remainder, or "" when the whole body fits in `body`.
    pub body: Vec<u8>,
    pub body_key: String,
    pub remote_addr: String,
    pub host: String,
    pub proto: String,
}

impl RequestEvent {
    /// Builds the event by hand instead of through a derived serializer: one
    /// event is marshaled per accepted request, and it is on the hot path. PHP
    /// receives a plain associative array with the struct's short keys, so the
    /// PHP side is unaffected by how it is built here.
    pub fn into_value(self) -> Value {
        let mut event = Map::with_capacity(10);

        event.push("rid", self.request_id);
        event.push("mt", self.method);
        event.push("pt", self.path);
        event.push("qr", self.query);
        event.push("bk", self.body_key);
        event.push("ra", self.remote_addr);
        event.push("ho", self.host);
        event.push("pr", self.proto);

        // The body is bytes, but PHP reads it as a string: there is one string
        // type on that side, so the raw bytes travel as-is and the handler gets
        // the same value it always did.
        event.push("bd", self.body);

        let mut headers = Map::with_capacity(self.headers.len());

        for (name, values) in self.headers {
            headers.push(name, values.into_iter().map(Value::from).collect::<Vec<Value>>());
        }

        event.push("hd", headers);

        event.into()
    }
}
