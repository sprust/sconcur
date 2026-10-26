//! The Rust counterparts of
//! the PHP HTTP-server payload objects and the request event the server streams
//! back to PHP. The renames are the short keys exchanged with the PHP side.

use serde::Deserialize;
use std::collections::HashMap;

use crate::dto::{Map, Value};

/// The payload of an httpStart command — the listener address and the server
/// tuning parameters (all timeouts in milliseconds, body limit in bytes).
/// PHP: SConcur\Features\HttpServer\Payloads\ServePayload.
///
/// Every field of the wire payload is decoded. The core acts on `address`,
/// `reuse_port`, `listen_fd`, `handler_timeout_ms`, the body limit,
/// `max_concurrency` and the telemetry block; the read-header/read/write/idle/shutdown timeouts are
/// accepted and not applied.
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
    /// A listening socket bound elsewhere, to accept on instead of binding the
    /// address: one from socket activation, say.
    #[serde(rename = "lfd", default)]
    pub listen_fd: Option<i64>,
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
/// `hd` and `nr` carry `default`: the PHP side omits both when empty (see
/// RespondPayload::getData).
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
    /// Bytes rather than a String: a response body is arbitrary bytes, and a
    /// PHP string holds whatever it holds. Decoding it into a String refuses
    /// any body that is not valid UTF-8, which is what HttpServerRequestTest's
    /// binary round trip catches.
    #[serde(rename = "bd", default, deserialize_with = "crate::dto::value_de::bytes")]
    pub body: Vec<u8>,
    /// Marks a fire-and-forget write: publish no task result for it (the PHP
    /// coroutine does not await one — the final write of a full response).
    #[serde(rename = "nr", default)]
    pub no_result: bool,
}

impl RespondPayload {
    /// The body as raw bytes, taken out of the payload rather than copied out
    /// of it.
    ///
    /// The payload already owns those bytes: they were allocated when it was
    /// decoded and nothing reads `body` again afterwards. Cloning them made a
    /// 100 KB response cross as one copy more than it needs. The rest of the
    /// struct is still needed at the call site (the headers go into the same
    /// command), which is why this takes `&mut self` and leaves an empty body
    /// behind instead of consuming the whole payload.
    pub fn take_body_bytes(&mut self) -> Vec<u8> {
        std::mem::take(&mut self.body)
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

/// What the server emits to PHP for each accepted request. PHP reads it in
/// HttpServer::decodeRequest into a PSR-7 request.
pub struct RequestEvent {
    pub request_id: String,
    pub method: String,
    pub path: String,
    pub query: String,
    pub headers: Vec<(String, Vec<String>)>,
    /// The whole request body, read before the event is sent. `body_key` stays
    /// "": the PHP side still accepts a streaming key, the core never sets one.
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

#[cfg(test)]
mod tests {
    use super::*;

    /// The array HttpServer::decodeRequest reads: the ten short keys, the body as
    /// the string it is, the headers as name => list of values.
    #[test]
    fn a_request_event_is_the_map_the_php_server_reads() {
        let event = RequestEvent {
            request_id: "r-1".to_string(),
            method: "POST".to_string(),
            path: "/users".to_string(),
            query: "a=1".to_string(),
            headers: vec![("Host".to_string(), vec!["example".to_string()]), ("Accept".to_string(), vec!["a".to_string(), "b".to_string()])],
            body: vec![0, 200, 7],
            body_key: "body-1".to_string(),
            remote_addr: "10.0.0.1:1234".to_string(),
            host: "example".to_string(),
            proto: "HTTP/1.1".to_string(),
        };

        let value = event.into_value();
        let keys: Vec<String> = value.as_map().unwrap().iter().map(|(key, _)| key.text()).collect();

        assert_eq!(keys, ["rid", "mt", "pt", "qr", "bk", "ra", "ho", "pr", "bd", "hd"]);
        assert_eq!(value.get("rid").and_then(Value::as_str), Some("r-1"));
        assert_eq!(value.get("mt").and_then(Value::as_str), Some("POST"));
        assert_eq!(value.get("bd").and_then(Value::as_bytes), Some(&[0u8, 200, 7][..]));
        assert_eq!(value.get("bk").and_then(Value::as_str), Some("body-1"));

        let headers = value.get("hd").expect("headers");

        assert_eq!(headers.as_map().unwrap().len(), 2);
        assert_eq!(
            headers.get("Accept").and_then(Value::as_list).map(|values| values.len()),
            Some(2)
        );
        assert_eq!(headers.get("Host").and_then(Value::as_list).and_then(|values| values[0].as_str()), Some("example"));
    }
}
