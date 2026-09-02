//! Mirrors ext/internal/features/httpserver/server.go: the connection side —
//! the per-request hand-off to PHP and the write command that answers it.

use http_body_util::combinators::BoxBody;
use http_body_util::{BodyExt, Full, StreamBody};
use hyper::body::Bytes;
use hyper::service::service_fn;
use hyper::{Request, Response, StatusCode};
use hyper_util::rt::TokioIo;
use std::collections::HashMap;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Mutex, OnceLock};
use std::time::Duration;
use tokio::net::TcpListener;
use tokio::sync::mpsc;
use tokio_util::sync::CancellationToken;

use crate::features::httpserver::payloads::RequestEvent;

/// Short-circuits every request with a constant 200 "ok" written directly from
/// the connection task — PHP is never called. Rung L0 of the attribution ladder
/// (.ai/plans/cpu-per-request-attribution.md): the floor the server gives
/// without PHP. Bench-only; off unless the worker starts with
/// SCONCUR_HTTP_BENCH_L0=1. Read once, like Go's package-level var.
pub fn bench_ladder_l0() -> bool {
    static FLAG: OnceLock<bool> = OnceLock::new();

    *FLAG.get_or_init(|| std::env::var("SCONCUR_HTTP_BENCH_L0").as_deref() == Ok("1"))
}

/// Default server tuning, used as a fallback when the PHP side sends a zero
/// value. The PHP side normally supplies these (its defaults mirror them).
const DEFAULT_HANDLER_TIMEOUT: Duration = Duration::from_secs(60);

/// Buffers accepted requests handed off by the connection task but not yet
/// pulled by the PHP serve loop. A smoothing buffer for accept bursts, not the
/// real backpressure; beyond it the connection task waits on the send.
const REQUEST_QUEUE_SIZE: usize = 1024;

/// Tags what a write command does to the connection. A one-shot response is a
/// single `Full`; a streamed one is `Head`, then any number of `Chunk`, then
/// `End`.
#[derive(PartialEq, Eq, Clone, Copy)]
pub enum WriteKind {
    Full = 0,
    Head = 1,
    Chunk = 2,
    End = 3,
}

impl WriteKind {
    pub fn from_code(code: i64) -> Option<Self> {
        match code {
            0 => Some(WriteKind::Full),
            1 => Some(WriteKind::Head),
            2 => Some(WriteKind::Chunk),
            3 => Some(WriteKind::End),
            _ => None,
        }
    }
}

pub struct WriteCommand {
    pub kind: WriteKind,
    pub status: u16,
    pub headers: HashMap<String, Vec<String>>,
    pub body: Vec<u8>,
    /// Carries the outcome back to the coroutine that issued the write, so a
    /// streaming handler only continues once its chunk has been taken.
    pub done: tokio::sync::oneshot::Sender<Result<(), String>>,
}

/// The feature's process-wide registries. They live on the Core rather than in
/// statics of their own so that a fork discards them with everything else —
/// a child must not inherit a map of the parent's connections, still less one
/// behind a mutex that may have been locked at the moment of the fork.
///
/// `pending_requests` maps a requestId to the channel its connection task reads
/// the PHP handler's writes from. Keyed per process so httpRespond (arriving on
/// a different flow) can find it, and kept until the response is finished —
/// a streamed one sends several commands through it.
pub struct Registries {
    pending_requests: Mutex<HashMap<String, mpsc::Sender<WriteCommand>>>,
    pub(super) server_states: Mutex<HashMap<String, ServerState>>,
}

impl Registries {
    pub fn new() -> Self {
        Registries {
            pending_requests: Mutex::new(HashMap::new()),
            server_states: Mutex::new(HashMap::new()),
        }
    }

    /// The channel a write goes to. Cloned, not taken: a streamed response
    /// needs the entry to survive its head and chunks, and only the end (or the
    /// connection going away) removes it.
    pub fn writer(&self, request_id: &str) -> Option<mpsc::Sender<WriteCommand>> {
        self.pending_requests.lock().unwrap().get(request_id).cloned()
    }

    pub fn forget(&self, request_id: &str) {
        self.pending_requests.lock().unwrap().remove(request_id);
    }
}

/// Releases the request's registration when the connection task goes away,
/// whatever the reason: the handler answered (the entry is already gone and the
/// removal is a no-op), the handler timed out, or the client disconnected and
/// the connection future was dropped mid-flight.
///
/// Go is covered here by the connection goroutine's own defer; the equivalent
/// on a dropped future has to be a guard, and without one every client that
/// hangs up while PHP is still handling leaks one map entry, unbounded.
struct PendingGuard {
    registries: &'static Registries,
    request_id: String,
}

impl Drop for PendingGuard {
    fn drop(&mut self) {
        self.registries
            .pending_requests
            .lock()
            .unwrap()
            .remove(&self.request_id);
    }
}

static REQUEST_COUNTER: AtomicI64 = AtomicI64::new(0);

fn next_request_id(flow_key: &str) -> String {
    format!(
        "{}:r:{}",
        flow_key,
        REQUEST_COUNTER.fetch_add(1, Ordering::Relaxed) + 1
    )
}

/// One running server: what the accept loop needs, and what StopAccepting
/// closes.
pub struct ServerState {
    pub stop_accepting: CancellationToken,
}

/// Runs the accept loop until the flow is cancelled or accepting is stopped.
/// Each accepted connection is served by its own task, exactly as Go gives each
/// one a goroutine.
#[allow(clippy::too_many_arguments)]
pub async fn accept_loop(
    registries: &'static Registries,
    listener: TcpListener,
    flow_key: String,
    requests: mpsc::Sender<RequestEvent>,
    handler_timeout_ms: i64,
    max_request_body: i64,
    max_concurrency: i64,
    flow_ctx: CancellationToken,
    stop_accepting: CancellationToken,
) {
    // Caps requests in flight, which bounds buffered bodies as much as it bounds
    // work: the permit is taken before the body is read.
    let slots = if max_concurrency > 0 {
        Some(std::sync::Arc::new(tokio::sync::Semaphore::new(
            max_concurrency as usize,
        )))
    } else {
        None
    };
    let handler_timeout = if handler_timeout_ms > 0 {
        Duration::from_millis(handler_timeout_ms as u64)
    } else {
        DEFAULT_HANDLER_TIMEOUT
    };

    loop {
        let accepted = tokio::select! {
            biased;

            _ = flow_ctx.cancelled() => return,
            _ = stop_accepting.cancelled() => return,
            accepted = listener.accept() => accepted,
        };

        let Ok((stream, remote_addr)) = accepted else {
            // A transient accept error must not kill the listener; a fatal one
            // ends it on the next iteration through the cancelled branch.
            continue;
        };

        let _ = stream.set_nodelay(true);

        let flow_key = flow_key.clone();
        let requests = requests.clone();
        let slots = slots.clone();

        tokio::spawn(async move {
            let service = service_fn(move |request: Request<hyper::body::Incoming>| {
                let flow_key = flow_key.clone();
                let requests = requests.clone();
                let slots = slots.clone();

                async move {
                    let _permit = match &slots {
                        Some(slots) => slots.clone().acquire_owned().await.ok(),
                        None => None,
                    };

                    Ok::<_, std::convert::Infallible>(
                        serve_request(
                            registries,
                            request,
                            flow_key,
                            requests,
                            remote_addr,
                            handler_timeout,
                            max_request_body,
                        )
                        .await,
                    )
                }
            });

            let _ = hyper::server::conn::http1::Builder::new()
                .serve_connection(TokioIo::new(stream), service)
                .await;
        });
    }
}

/// A response body is either one buffer or a stream of chunks, so it is boxed.
/// Infallible: nothing this server produces can fail mid-body — a stream that
/// ends early simply ends.
pub type ResponseBody = BoxBody<Bytes, std::convert::Infallible>;

/// Percent-decodes a request path.
///
/// Go's net/http hands the handler `r.URL.Path` already decoded, and both the
/// request event PHP receives and the access line are built from it. hyper keeps
/// the raw path, so decoding here is not a nicety — without it `%2F` reaches an
/// application router as three characters, and an encoded newline slips into the
/// log unescaped because there is no control character left to escape.
///
/// An invalid escape is left as written, the way a permissive decoder should:
/// the alternative is rejecting a request over a log detail.
fn decode_path(path: &str) -> String {
    if !path.contains('%') {
        return path.to_string();
    }

    let raw = path.as_bytes();
    let mut decoded: Vec<u8> = Vec::with_capacity(raw.len());
    let mut index = 0;

    while index < raw.len() {
        if raw[index] == b'%' && index + 2 < raw.len() {
            let high = (raw[index + 1] as char).to_digit(16);
            let low = (raw[index + 2] as char).to_digit(16);

            if let (Some(high), Some(low)) = (high, low) {
                decoded.push((high * 16 + low) as u8);
                index += 3;

                continue;
            }
        }

        decoded.push(raw[index]);
        index += 1;
    }

    String::from_utf8_lossy(&decoded).into_owned()
}

/// One access-log line for a finished request:
/// `<ISO-start-time> <method> <path> <status> <ms>ms`. The method and the path
/// are escaped, so a crafted request cannot forge a line of its own.
fn access_line(
    started_at: std::time::SystemTime,
    elapsed: std::time::Duration,
    method: &str,
    path: &str,
    status: u16,
) -> String {
    format!(
        "{} {} {} {} {:.2}ms\n",
        crate::logger::timestamp(started_at),
        crate::logger::sanitize(method),
        crate::logger::sanitize(path),
        status,
        elapsed.as_secs_f64() * 1000.0,
    )
}

async fn serve_request(
    registries: &'static Registries,
    request: Request<hyper::body::Incoming>,
    flow_key: String,
    requests: mpsc::Sender<RequestEvent>,
    remote_addr: std::net::SocketAddr,
    handler_timeout: Duration,
    max_request_body: i64,
) -> Response<ResponseBody> {
    let started_at = std::time::SystemTime::now();
    let started = std::time::Instant::now();
    let deadline = std::time::Instant::now() + handler_timeout;

    if bench_ladder_l0() {
        let response = text_response(StatusCode::OK, "ok");

        crate::logger::write(access_line(
            started_at,
            started.elapsed(),
            request.method().as_str(),
            &decode_path(request.uri().path()),
            response.status().as_u16(),
        ));

        return response;
    }

    let request_id = next_request_id(&flow_key);

    let method = request.method().to_string();
    let proto = format!("{:?}", request.version());
    let path = decode_path(request.uri().path());
    let query = request.uri().query().unwrap_or_default().to_string();

    let mut headers: Vec<(String, Vec<String>)> = Vec::with_capacity(request.headers().len());
    let mut host = String::new();

    for (name, value) in request.headers() {
        let name = name.as_str().to_string();
        let value = value.to_str().unwrap_or_default().to_string();

        if name.eq_ignore_ascii_case("host") {
            host = value.clone();
        }

        match headers.iter_mut().find(|(existing, _)| *existing == name) {
            Some((_, values)) => values.push(value),
            None => headers.push((name, vec![value])),
        }
    }

    // Refused on the declared length before a byte is read, and again on what
    // actually arrived — a chunked body declares nothing.
    let declared = request
        .headers()
        .get(hyper::header::CONTENT_LENGTH)
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.parse::<i64>().ok());

    if max_request_body > 0 && declared.is_some_and(|length| length > max_request_body) {
        return oversize_response(started_at, started, &method, &path);
    }

    let body = match request.into_body().collect().await {
        Ok(collected) => collected.to_bytes().to_vec(),
        Err(_) => Vec::new(),
    };

    if max_request_body > 0 && body.len() as i64 > max_request_body {
        return oversize_response(started_at, started, &method, &path);
    }

    // Capacity one: a write hands over and waits for the next, which is the
    // backpressure a streaming handler needs — it must not run ahead of the
    // client.
    let (writes_tx, mut writes_rx) = mpsc::channel::<WriteCommand>(1);

    registries
        .pending_requests
        .lock()
        .unwrap()
        .insert(request_id.clone(), writes_tx);

    let pending_guard = PendingGuard {
        registries,
        request_id: request_id.clone(),
    };

    let event = RequestEvent {
        request_id: request_id.clone(),
        method,
        path,
        query,
        headers,
        body,
        body_key: String::new(),
        remote_addr: remote_addr.to_string(),
        host,
        proto,
    };

    let log_method = event.method.clone();
    let log_path = event.path.clone();

    let response = if requests.send(event).await.is_err() {
        text_response(StatusCode::SERVICE_UNAVAILABLE, "Service Unavailable")
    } else {
        match tokio::time::timeout(handler_timeout, writes_rx.recv()).await {
            Ok(Some(command)) if command.kind == WriteKind::Head => {
                // A streamed response: the head goes out now and the body is
                // fed by the chunks that follow. The guard moves into the body,
                // so the registration lives exactly as long as the stream.
                stream_response(command, writes_rx, pending_guard, deadline)
            }
            Ok(Some(command)) => build_response(command),
            // The handler never answered, or the request was dropped without
            // one. The guard releases the registration either way.
            _ => text_response(StatusCode::GATEWAY_TIMEOUT, "Gateway Timeout"),
        }
    };

    crate::logger::write(access_line(
        started_at,
        started.elapsed(),
        &log_method,
        &log_path,
        response.status().as_u16(),
    ));

    response
}

/// Builds the streamed response: status and headers now, body chunks as they
/// arrive. The absence of a Content-Length is what makes it a chunked transfer,
/// which is the observable difference a caller checks for.
fn stream_response(
    head: WriteCommand,
    writes: mpsc::Receiver<WriteCommand>,
    guard: PendingGuard,
    deadline: std::time::Instant,
) -> Response<ResponseBody> {
    let mut builder = Response::builder()
        .status(StatusCode::from_u16(head.status).unwrap_or(StatusCode::OK));

    for (name, values) in &head.headers {
        for value in values {
            builder = builder.header(name.as_str(), value.as_str());
        }
    }

    let body = StreamBody::new(futures_util::stream::unfold(
        (writes, guard),
        move |(mut writes, guard)| async move {
            // The same deadline that bounds a one-shot handler bounds this one,
            // or a handler that stops writing would hold the connection open
            // for as long as it liked.
            let command = tokio::time::timeout_at(deadline.into(), writes.recv())
                .await
                .ok()
                .flatten()?;

            match command.kind {
                WriteKind::Chunk => {
                    let _ = command.done.send(Ok(()));

                    Some((
                        Ok(hyper::body::Frame::data(Bytes::from(command.body))),
                        (writes, guard),
                    ))
                }
                // End, or anything else arriving mid-stream, finishes the body.
                _ => {
                    let _ = command.done.send(Ok(()));

                    None
                }
            }
        },
    ));

    let _ = head.done.send(Ok(()));

    builder
        .body(BodyExt::boxed(body))
        .unwrap_or_else(|_| text_response(StatusCode::INTERNAL_SERVER_ERROR, "Internal Server Error"))
}

fn build_response(command: WriteCommand) -> Response<ResponseBody> {
    let mut builder = Response::builder()
        .status(StatusCode::from_u16(command.status).unwrap_or(StatusCode::OK));

    let mut has_content_type = false;

    for (name, values) in &command.headers {
        if name.eq_ignore_ascii_case("content-type") {
            has_content_type = true;
        }

        for value in values {
            builder = builder.header(name.as_str(), value.as_str());
        }
    }

    // net/http sniffs a Content-Type when the handler set none, so a response
    // it writes carries 39 bytes this one otherwise would not. That difference
    // lands directly in the ladder's transfer figures, so the header is
    // mirrored here to keep the two servers byte-comparable.
    //
    // Spike simplification: Go inspects the first 512 bytes, this assumes text.
    // The only body any spike feature emits is the ladder's constant.
    if !has_content_type && !command.body.is_empty() {
        builder = builder.header("Content-Type", "text/plain; charset=utf-8");
    }

    let body = BodyExt::boxed(Full::new(Bytes::from(command.body)));

    builder
        .body(body)
        .unwrap_or_else(|_| text_response(StatusCode::INTERNAL_SERVER_ERROR, "Internal Server Error"))
}

/// The 413 a body over the limit gets, logged like any other answer.
fn oversize_response(
    started_at: std::time::SystemTime,
    started: std::time::Instant,
    method: &str,
    path: &str,
) -> Response<ResponseBody> {
    let response = text_response(StatusCode::PAYLOAD_TOO_LARGE, "Payload Too Large");

    crate::logger::write(access_line(
        started_at,
        started.elapsed(),
        method,
        path,
        response.status().as_u16(),
    ));

    response
}

fn text_response(status: StatusCode, body: &'static str) -> Response<ResponseBody> {
    Response::builder()
        .status(status)
        // Same reason as in build_response: net/http would sniff this header,
        // and the L0 rung must put the same bytes on the wire as the Go one.
        .header("Content-Type", "text/plain; charset=utf-8")
        .body(BodyExt::boxed(Full::new(Bytes::from_static(body.as_bytes()))))
        .expect("static response is always valid")
}

pub const fn request_queue_size() -> usize {
    REQUEST_QUEUE_SIZE
}
