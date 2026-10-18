//! The parameters of the HTTP client commands.
//!
//! Every message is a command envelope (`cm`/`p`): `cm` selects the
//! sub-operation, `p` carries a nested map of that command's parameters.

use serde::Deserialize;
use std::collections::HashMap;

use crate::dto::{Map, Value};

#[derive(Deserialize)]
pub struct Envelope {
    #[serde(rename = "cm", default)]
    pub command: String,
    /// The command's parameters, kept as an untyped subtree until the command
    /// is known: its handler decodes them into its own parameter struct.
    #[serde(rename = "p", default = "nil_value")]
    pub params: Value,
}

/// Missing parameters decode to nil rather than failing: the command handler
/// reports what it actually needs, which is a better message than
/// "missing field p".
fn nil_value() -> Value {
    Value::Nil
}

/// The `p` of a Request — one request to send, plus its tuning.
/// PHP: SConcur\Features\HttpClient\Payloads\RequestPayloadParameters.
#[derive(Deserialize)]
pub struct RequestParams {
    #[serde(rename = "m", default)]
    pub method: String,
    #[serde(rename = "u", default)]
    pub url: String,
    #[serde(rename = "h", default)]
    pub headers: HashMap<String, Vec<String>>,
    /// Bytes rather than a String: a request body is arbitrary bytes, and a
    /// PHP string holds whatever it holds.
    #[serde(rename = "b", default, deserialize_with = "crate::dto::value_de::bytes")]
    pub body: Vec<u8>,
    /// Opens the request with a body PHP fills through upload commands.
    #[serde(rename = "sb", default)]
    pub stream_body: bool,
    /// Keys the open request so its upload chunks find it.
    #[serde(rename = "rid", default)]
    pub request_id: String,
    /// The hard limit for the whole operation — connect, send, and reading the
    /// entire body. 0 disables it.
    #[serde(rename = "rt", default)]
    pub request_timeout_ms: i64,
    #[serde(rename = "ct", default)]
    pub connect_timeout_ms: i64,
    /// Bounds waiting for the status line and headers.
    #[serde(rename = "rht", default)]
    pub response_header_timeout_ms: i64,
    #[serde(rename = "mrb", default)]
    pub max_response_body: i64,
    #[serde(rename = "fr", default)]
    pub follow_redirects: bool,
    #[serde(rename = "mr", default)]
    pub max_redirects: i64,
    /// The granularity of reading the response body: the inline first chunk and
    /// each streamed chunk after it.
    #[serde(rename = "cs", default)]
    pub chunk_size: i64,
    #[serde(rename = "vt", default)]
    pub verify_tls: bool,
    /// Decoded but unused: reqwest sizes its pool per host, so the process-wide
    /// total has no counterpart. `max_idle_conns_per_host` is what applies.
    #[allow(dead_code)]
    #[serde(rename = "mic", default)]
    pub max_idle_conns: i64,
    #[serde(rename = "mih", default)]
    pub max_idle_conns_per_host: i64,
    #[serde(rename = "ict", default)]
    pub idle_conn_timeout_ms: i64,
    #[serde(rename = "tht", default)]
    pub tls_handshake_timeout_ms: i64,
    /// When set, the body is written straight into this file instead of being
    /// streamed to PHP.
    #[serde(rename = "sp", default)]
    pub sink_path: String,
    #[serde(rename = "sm", default)]
    pub sink_mode: String,
    #[serde(rename = "spm", default)]
    pub sink_perm: i64,
    /// Decoded but unused: the body arrives as a stream of buffers the transport
    /// already sized, so there is no buffer here to size.
    #[allow(dead_code)]
    #[serde(rename = "dbs", default)]
    pub download_buffer_size_bytes: i64,
}

impl RequestParams {
    /// The request body, taken out of the params rather than copied out of
    /// them: the decoded params already own those bytes and nothing reads
    /// `body` again. The rest of the params is still needed at the call site,
    /// which is why this takes `&mut self` instead of consuming them.
    pub fn take_body_bytes(&mut self) -> Vec<u8> {
        std::mem::take(&mut self.body)
    }
}

/// The `p` of an UploadChunk/UploadEnd: the request being uploaded to and, for a
/// chunk, the bytes to append. The command itself distinguishes the two.
#[derive(Deserialize)]
pub struct UploadParams {
    #[serde(rename = "rid", default)]
    pub request_id: String,
    #[serde(rename = "b", default, deserialize_with = "crate::dto::value_de::bytes")]
    pub body: Vec<u8>,
}

impl UploadParams {
    /// The chunk's bytes, taken out of the params rather than copied out of
    /// them — see RequestParams::take_body_bytes.
    pub fn take_body_bytes(&mut self) -> Vec<u8> {
        std::mem::take(&mut self.body)
    }
}

/// The first result a request emits: status, headers and the inline first chunk
/// of the body. Every result after it is a raw body chunk.
pub struct ResponseMeta {
    pub status: u16,
    pub headers: Vec<(String, Vec<String>)>,
    pub body: Vec<u8>,
    /// The response Content-Length, or -1 when unknown (a chunked transfer).
    pub content_length: i64,
}

impl ResponseMeta {
    pub fn into_value(self) -> Value {
        Map::with_capacity(4)
            .set("st", self.status)
            .set("hd", headers_value(self.headers))
            // The body is bytes, but PHP reads it as a string: one string type
            // on that side, so the raw bytes travel as-is.
            .set("b", self.body)
            .set("cl", self.content_length)
            .into()
    }
}

/// The single result of a download: status, the headers as the server returned
/// them, and the bytes actually written — ground truth, independent of any
/// Content-Length header.
pub struct DownloadMeta {
    pub status: u16,
    pub headers: Vec<(String, Vec<String>)>,
    pub written: i64,
}

impl DownloadMeta {
    pub fn into_value(self) -> Value {
        Map::with_capacity(3)
            .set("st", self.status)
            .set("hd", headers_value(self.headers))
            .set("n", self.written)
            .into()
    }
}

/// The headers as PHP reads them: a map of name to the list of its values.
fn headers_value(headers: Vec<(String, Vec<String>)>) -> Value {
    let mut map = Map::with_capacity(headers.len());

    for (name, values) in headers {
        map.push(name, values.into_iter().map(Value::from).collect::<Vec<Value>>());
    }

    map.into()
}

/// Collects a response's headers, grouping repeated names the way net/http
/// presents them.
pub fn collect_headers(headers: &reqwest::header::HeaderMap) -> Vec<(String, Vec<String>)> {
    let mut collected: Vec<(String, Vec<String>)> = Vec::with_capacity(headers.len());

    for (name, value) in headers {
        let name = name.as_str().to_string();
        let value = value.to_str().unwrap_or_default().to_string();

        match collected.iter_mut().find(|(existing, _)| *existing == name) {
            Some((_, values)) => values.push(value),
            None => collected.push((name, vec![value])),
        }
    }

    collected
}
