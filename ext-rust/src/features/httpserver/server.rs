//! Mirrors ext/internal/features/httpserver/server.go: the connection side —
//! the per-request hand-off to PHP and the write command that answers it.

use http_body_util::{BodyExt, Full};
use hyper::body::Bytes;
use hyper::service::service_fn;
use hyper::{Request, Response, StatusCode};
use hyper_util::rt::TokioIo;
use std::collections::HashMap;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Mutex, OnceLock};
use std::time::Duration;
use tokio::net::TcpListener;
use tokio::sync::{mpsc, oneshot};
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

/// Tags what a write command does to the connection.
///
/// Spike scope: only the one-shot full response is implemented. The streamed
/// kinds (head/chunk/end) answer with an explicit error rather than silently
/// doing nothing, so anything reaching for them fails loudly.
#[derive(PartialEq, Eq, Clone, Copy)]
pub enum WriteKind {
    Full = 0,
}

pub struct WriteCommand {
    /// Kept although only `Full` is built: it names the wire op a command
    /// came from, which is what the streamed kinds will select on.
    #[allow(dead_code)]
    pub kind: WriteKind,
    pub status: u16,
    pub headers: HashMap<String, Vec<String>>,
    pub body: Vec<u8>,
}

/// The feature's process-wide registries. They live on the Core rather than in
/// statics of their own so that a fork discards them with everything else —
/// a child must not inherit a map of the parent's connections, still less one
/// behind a mutex that may have been locked at the moment of the fork.
///
/// `pending_requests` maps a requestId to the channel its connection task waits
/// on for the PHP handler's response. Keyed per process so httpRespond
/// (arriving on a different flow) can find it. Go keeps the entry until the
/// response is finished, because a streamed response sends several commands
/// through it; with only the one-shot write in scope the entry is taken by the
/// first command, which is also what frees it.
pub struct Registries {
    pending_requests: Mutex<HashMap<String, oneshot::Sender<WriteCommand>>>,
    pub(super) server_states: Mutex<HashMap<String, ServerState>>,
}

impl Registries {
    pub fn new() -> Self {
        Registries {
            pending_requests: Mutex::new(HashMap::new()),
            server_states: Mutex::new(HashMap::new()),
        }
    }

    pub fn take_pending(&self, request_id: &str) -> Option<oneshot::Sender<WriteCommand>> {
        self.pending_requests.lock().unwrap().remove(request_id)
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
pub async fn accept_loop(
    registries: &'static Registries,
    listener: TcpListener,
    flow_key: String,
    requests: mpsc::Sender<RequestEvent>,
    handler_timeout_ms: i64,
    flow_ctx: CancellationToken,
    stop_accepting: CancellationToken,
) {
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

        tokio::spawn(async move {
            let service = service_fn(move |request: Request<hyper::body::Incoming>| {
                let flow_key = flow_key.clone();
                let requests = requests.clone();

                async move {
                    Ok::<_, std::convert::Infallible>(
                        serve_request(
                            registries,
                            request,
                            flow_key,
                            requests,
                            remote_addr,
                            handler_timeout,
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

async fn serve_request(
    registries: &'static Registries,
    request: Request<hyper::body::Incoming>,
    flow_key: String,
    requests: mpsc::Sender<RequestEvent>,
    remote_addr: std::net::SocketAddr,
    handler_timeout: Duration,
) -> Response<Full<Bytes>> {
    if bench_ladder_l0() {
        return text_response(StatusCode::OK, "ok");
    }

    let request_id = next_request_id(&flow_key);

    let method = request.method().to_string();
    let proto = format!("{:?}", request.version());
    let path = request.uri().path().to_string();
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

    let body = match request.into_body().collect().await {
        Ok(collected) => collected.to_bytes().to_vec(),
        Err(_) => Vec::new(),
    };

    let (response_tx, response_rx) = oneshot::channel::<WriteCommand>();

    registries
        .pending_requests
        .lock()
        .unwrap()
        .insert(request_id.clone(), response_tx);

    let _pending_guard = PendingGuard {
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

    if requests.send(event).await.is_err() {
        return text_response(StatusCode::SERVICE_UNAVAILABLE, "Service Unavailable");
    }

    match tokio::time::timeout(handler_timeout, response_rx).await {
        Ok(Ok(command)) => build_response(command),
        // The handler never answered, or the request was dropped without one.
        // The guard releases the registration either way.
        _ => text_response(StatusCode::GATEWAY_TIMEOUT, "Gateway Timeout"),
    }
}

fn build_response(command: WriteCommand) -> Response<Full<Bytes>> {
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

    builder
        .body(Full::new(Bytes::from(command.body)))
        .unwrap_or_else(|_| text_response(StatusCode::INTERNAL_SERVER_ERROR, "Internal Server Error"))
}

fn text_response(status: StatusCode, body: &'static str) -> Response<Full<Bytes>> {
    Response::builder()
        .status(status)
        // Same reason as in build_response: net/http would sniff this header,
        // and the L0 rung must put the same bytes on the wire as the Go one.
        .header("Content-Type", "text/plain; charset=utf-8")
        .body(Full::new(Bytes::from_static(body.as_bytes())))
        .expect("static response is always valid")
}

pub const fn request_queue_size() -> usize {
    REQUEST_QUEUE_SIZE
}
