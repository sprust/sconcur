//! Mirrors ext/internal/features/httpserver/payloads: the Rust counterparts of
//! the PHP HTTP-server payload objects and the request event the server streams
//! back to PHP. The renames are the short keys exchanged via MessagePack.

use serde::Deserialize;

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
/// accepted and ignored — a scope limit worth knowing before comparing
/// anything but the ladder against the Go server, which enforces them all.
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
    /// Decoded but unused: the id is resolved from RespondRequestId before this
    /// struct is parsed at all. Kept so the struct stays a faithful picture of
    /// the wire payload.
    #[allow(dead_code)]
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
    /// The body as raw bytes, whether PHP sent text or binary.
    pub fn body_bytes(&self) -> Vec<u8> {
        match &self.body {
            rmpv::Value::String(text) => text.as_bytes().to_vec(),
            rmpv::Value::Binary(bytes) => bytes.clone(),
            rmpv::Value::Nil => Vec::new(),
            other => other.to_string().into_bytes(),
        }
    }
}

/// Decoded on its own first, exactly as Go does: a struct with only this field
/// ignores every other key, so a response can always be routed back even if the
/// rest of the payload is malformed.
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
    /// Writes the event by hand instead of through a derived serializer, for
    /// the same reason Go implements msgpack.CustomEncoder here: one event is
    /// marshaled per accepted request, and it is on the hot path. The wire
    /// bytes are a plain MessagePack map with the struct's short keys, so the
    /// PHP side is unaffected.
    pub fn encode(&self) -> Vec<u8> {
        use rmp::encode;

        // Roughly one small write per field plus the headers; sized to cover a
        // typical request without a growth reallocation.
        let mut buffer: Vec<u8> = Vec::with_capacity(256 + self.body.len());

        let _ = encode::write_map_len(&mut buffer, 10);

        let fields: [(&str, &str); 8] = [
            ("rid", &self.request_id),
            ("mt", &self.method),
            ("pt", &self.path),
            ("qr", &self.query),
            ("bk", &self.body_key),
            ("ra", &self.remote_addr),
            ("ho", &self.host),
            ("pr", &self.proto),
        ];

        for (key, value) in fields {
            let _ = encode::write_str(&mut buffer, key);
            let _ = encode::write_str(&mut buffer, value);
        }

        // The body is bytes, but PHP reads it as a string and the Go side sends
        // a msgpack string too — keep the type identical or ext-msgpack hands
        // the handler a different value than it does today.
        let _ = encode::write_str(&mut buffer, "bd");
        let _ = encode::write_str_len(&mut buffer, self.body.len() as u32);
        buffer.extend_from_slice(&self.body);

        let _ = encode::write_str(&mut buffer, "hd");
        let _ = encode::write_map_len(&mut buffer, self.headers.len() as u32);

        for (name, values) in &self.headers {
            let _ = encode::write_str(&mut buffer, name);
            let _ = encode::write_array_len(&mut buffer, values.len() as u32);

            for value in values {
                let _ = encode::write_str(&mut buffer, value);
            }
        }

        buffer
    }
}
