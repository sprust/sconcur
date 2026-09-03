//! Mirrors ext-go-legacy/internal/features/httpclient/payloads.
//!
//! Every message is a command envelope (`cm`/`p`): `cm` selects the
//! sub-operation, `p` carries a nested map of that command's parameters.

use serde::Deserialize;
use std::collections::HashMap;

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
    #[serde(rename = "b", default = "nil_value")]
    pub body: rmpv::Value,
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
    /// already sized, where Go copies through a buffer of its own choosing.
    #[allow(dead_code)]
    #[serde(rename = "dbs", default)]
    pub download_buffer_size_bytes: i64,
}

impl RequestParams {
    /// The request body, taken out of the params rather than copied out of
    /// them: the decoded value already owns those bytes and nothing reads
    /// `body` again. The rest of the params is still needed at the call site,
    /// which is why this takes `&mut self` instead of consuming them.
    pub fn take_body_bytes(&mut self) -> Vec<u8> {
        match std::mem::replace(&mut self.body, rmpv::Value::Nil) {
            rmpv::Value::String(text) => text.into_bytes(),
            rmpv::Value::Binary(bytes) => bytes,
            _ => Vec::new(),
        }
    }
}

/// The `p` of an UploadChunk/UploadEnd: the request being uploaded to and, for a
/// chunk, the bytes to append. The command itself distinguishes the two.
#[derive(Deserialize)]
pub struct UploadParams {
    #[serde(rename = "rid", default)]
    pub request_id: String,
    #[serde(rename = "b", default = "nil_value")]
    pub body: rmpv::Value,
}

impl UploadParams {
    /// The chunk's bytes, taken out of the params rather than copied out of
    /// them — see RequestParams::take_body_bytes.
    pub fn take_body_bytes(&mut self) -> Vec<u8> {
        match std::mem::replace(&mut self.body, rmpv::Value::Nil) {
            rmpv::Value::String(text) => text.into_bytes(),
            rmpv::Value::Binary(bytes) => bytes,
            _ => Vec::new(),
        }
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
    pub fn encode(&self) -> Vec<u8> {
        use rmp::encode;

        let mut buffer = Vec::with_capacity(256 + self.body.len());

        let _ = encode::write_map_len(&mut buffer, 4);

        let _ = encode::write_str(&mut buffer, "st");
        let _ = encode::write_sint(&mut buffer, self.status as i64);

        let _ = encode::write_str(&mut buffer, "hd");
        write_headers(&mut buffer, &self.headers);

        let _ = encode::write_str(&mut buffer, "b");
        let _ = encode::write_str_len(&mut buffer, self.body.len() as u32);
        buffer.extend_from_slice(&self.body);

        let _ = encode::write_str(&mut buffer, "cl");
        let _ = encode::write_sint(&mut buffer, self.content_length);

        buffer
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
    pub fn encode(&self) -> Vec<u8> {
        use rmp::encode;

        let mut buffer = Vec::with_capacity(256);

        let _ = encode::write_map_len(&mut buffer, 3);

        let _ = encode::write_str(&mut buffer, "st");
        let _ = encode::write_sint(&mut buffer, self.status as i64);

        let _ = encode::write_str(&mut buffer, "hd");
        write_headers(&mut buffer, &self.headers);

        let _ = encode::write_str(&mut buffer, "n");
        let _ = encode::write_sint(&mut buffer, self.written);

        buffer
    }
}

fn write_headers(buffer: &mut Vec<u8>, headers: &[(String, Vec<String>)]) {
    use rmp::encode;

    let _ = encode::write_map_len(buffer, headers.len() as u32);

    for (name, values) in headers {
        let _ = encode::write_str(buffer, name);
        let _ = encode::write_array_len(buffer, values.len() as u32);

        for value in values {
            let _ = encode::write_str(buffer, value);
        }
    }
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
