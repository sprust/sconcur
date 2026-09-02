//! Mirrors ext/internal/features/httpclient/response_state.go: the request is
//! performed on the first `next()`, which answers with the response metadata and
//! the inline first chunk of the body; every `next()` after it is another chunk.

use futures_util::StreamExt;
use std::pin::Pin;
use std::sync::Arc;
use std::time::{Duration, Instant};

use crate::dto::{Message, Result};
use crate::helpers::calc_execution_ms;
use crate::states::{StateCloseFuture, StateContract, StateFuture};

use super::payloads::{ResponseMeta, collect_headers};
use super::{network_error, request_error};

type BodyStream = Pin<Box<dyn futures_util::Stream<Item = reqwest::Result<bytes::Bytes>> + Send>>;

/// How the request reaches the wire. A buffered body is sent on the first
/// `next()`; a streamed one is already in flight, because PHP has to be able to
/// push its chunks before anything waits on the response.
pub enum Pending {
    Buffered(reqwest::RequestBuilder),
    Streamed(tokio::sync::oneshot::Receiver<reqwest::Result<reqwest::Response>>),
}

pub struct ResponseState {
    message: Arc<Message>,
    chunk_size: usize,
    max_response_body: i64,
    response_header_timeout: Option<Duration>,
    start_time: Instant,
    inner: tokio::sync::Mutex<Inner>,
}

struct Inner {
    pending: Option<Pending>,
    stream: Option<BodyStream>,
    /// Bytes read past what has already been handed to PHP.
    carry: Vec<u8>,
    /// Counted as *delivered*, not as read off the socket. The stream hands over
    /// items of whatever size the transport produced, so one item can be the
    /// whole body — counting reads would trip the cap on the very first call and
    /// fail the request upfront, where Go fails it mid-stream after the metadata
    /// has already reached PHP.
    delivered_total: i64,
    exhausted: bool,
}

impl ResponseState {
    pub fn new(
        message: Arc<Message>,
        pending: Pending,
        chunk_size: i64,
        max_response_body: i64,
        response_header_timeout_ms: i64,
    ) -> Self {
        ResponseState {
            message,
            chunk_size: if chunk_size > 0 {
                chunk_size as usize
            } else {
                64 * 1024
            },
            max_response_body,
            response_header_timeout: (response_header_timeout_ms > 0)
                .then(|| Duration::from_millis(response_header_timeout_ms as u64)),
            start_time: Instant::now(),
            inner: tokio::sync::Mutex::new(Inner {
                pending: Some(pending),
                stream: None,
                carry: Vec::new(),
                delivered_total: 0,
                exhausted: false,
            }),
        }
    }

    fn error(&self, text: String) -> Result {
        Result::error(&self.message, text)
    }

    fn chunk(&self, body: Vec<u8>, has_next: bool) -> Result {
        if has_next {
            Result::success_with_next(&self.message, body, calc_execution_ms(self.start_time))
        } else {
            Result::success(&self.message, body, calc_execution_ms(self.start_time))
        }
    }
}

impl StateContract for ResponseState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            let mut inner = self.inner.lock().await;

            if let Some(pending) = inner.pending.take() {
                return self.send(&mut inner, pending).await;
            }

            match read_chunk(&mut inner, self.chunk_size, self.max_response_body).await {
                Ok((body, has_next)) => self.chunk(body, has_next),
                Err(error) => self.error(network_error(&error)),
            }
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            let mut inner = self.inner.lock().await;

            // Dropping the stream closes the connection or returns it to the
            // pool; a body left half-read must not hold either.
            inner.stream = None;
            inner.pending = None;
            inner.exhausted = true;
        })
    }
}

impl ResponseState {
    async fn send(&self, inner: &mut Inner, pending: Pending) -> Result {
        let response = match pending {
            Pending::Buffered(builder) => {
                // send() resolves when the status line and headers are in, which
                // is exactly what responseHeaderTimeout bounds.
                let sent = match self.response_header_timeout {
                    Some(limit) => match tokio::time::timeout(limit, builder.send()).await {
                        Ok(sent) => sent,
                        Err(_) => {
                            return self.error(network_error("response header timeout"));
                        }
                    },
                    None => builder.send().await,
                };

                match sent {
                    Ok(response) => response,
                    Err(error) => return self.error(network_error(&error.to_string())),
                }
            }
            Pending::Streamed(receiver) => match receiver.await {
                Ok(Ok(response)) => response,
                Ok(Err(error)) => return self.error(network_error(&error.to_string())),
                Err(_) => return self.error(request_error("upload was abandoned")),
            },
        };

        let status = response.status().as_u16();
        let headers = collect_headers(response.headers());
        let content_length = response
            .content_length()
            .map(|length| length as i64)
            .unwrap_or(-1);

        inner.stream = Some(Box::pin(response.bytes_stream()));

        let (body, has_next) =
            match read_chunk(inner, self.chunk_size, self.max_response_body).await {
                Ok(chunk) => chunk,
                Err(error) => return self.error(network_error(&error)),
            };

        let meta = ResponseMeta {
            status,
            headers,
            body,
            content_length,
        };

        if has_next {
            Result::success_with_next(
                &self.message,
                meta.encode(),
                calc_execution_ms(self.start_time),
            )
        } else {
            Result::success(
                &self.message,
                meta.encode(),
                calc_execution_ms(self.start_time),
            )
        }
    }
}

/// Reads one chunk of exactly `chunk_size` bytes, or whatever is left at the end.
/// The stream hands over arbitrary sizes, so what does not fit is carried to the
/// next call — PHP asked for a granularity and gets it.
async fn read_chunk(
    inner: &mut Inner,
    chunk_size: usize,
    max_response_body: i64,
) -> std::result::Result<(Vec<u8>, bool), String> {
    if inner.exhausted && inner.carry.is_empty() {
        return Ok((Vec::new(), false));
    }

    while inner.carry.len() < chunk_size && !inner.exhausted {
        let Some(stream) = inner.stream.as_mut() else {
            inner.exhausted = true;

            break;
        };

        match stream.next().await {
            Some(Ok(bytes)) => inner.carry.extend_from_slice(&bytes),
            Some(Err(error)) => {
                inner.exhausted = true;
                inner.stream = None;

                return Err(error.to_string());
            }
            None => {
                inner.exhausted = true;
                inner.stream = None;
            }
        }
    }

    let taken = inner.carry.len().min(chunk_size);

    if max_response_body > 0 && inner.delivered_total + taken as i64 > max_response_body {
        inner.exhausted = true;
        inner.stream = None;
        inner.carry.clear();

        return Err(format!(
            "response body exceeds maxResponseBody of {max_response_body} bytes"
        ));
    }

    inner.delivered_total += taken as i64;

    let body: Vec<u8> = inner.carry.drain(..taken).collect();

    // More follows only if something is actually left: an exhausted stream with
    // an empty carry ends here rather than costing PHP one more empty crossing.
    let has_next = !inner.carry.is_empty() || !inner.exhausted;

    Ok((body, has_next))
}
