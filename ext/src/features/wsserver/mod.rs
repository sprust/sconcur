//! Mirrors ext-go-legacy/internal/features/wsserver.
//!
//! The listener is an HTTP server: a request carrying a valid WebSocket upgrade
//! becomes a streamed connection, anything else is answered 426. That is how the
//! Go side gets there too (net/http + coder/websocket), so the same path,
//! origin and subprotocol checks live in the same place.
//!
//! Each connection is pumped by a read task rather than by the handler, so
//! control frames — ping, pong, close — are processed even when the handler is
//! push-only and never reads. The data messages that task forwards are what the
//! MessageState streams to PHP.

pub mod payloads;

use fastwebsockets::{FragmentCollectorRead, Frame, OpCode, Payload, upgrade};
use http_body_util::Full;
use hyper::body::Bytes;
use hyper::service::service_fn;
use hyper::{Request, Response, StatusCode};
use hyper_util::rt::TokioIo;
use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};
use tokio::sync::{Semaphore, mpsc};
use tokio_util::sync::CancellationToken;

use crate::dto::{Message, Result};
use crate::errs::Factory;
use crate::features::{BoxFuture, Feature};
use crate::helpers::calc_execution_ms;
use crate::socket::next_connection_id;
use crate::stats::{ConnectionStats, Pusher};
use crate::states as core_states;
use crate::tasks::Task;
use crate::types::method::Method;
use crate::ws::{InboundMessage, PendingConnection, WriteKind, dispatch, is_binary};

use payloads::ConnectionEvent;

static ERRORS: Factory = Factory::new("wsServer");

const CONNECTION_QUEUE_SIZE: usize = 1024;
const MESSAGE_QUEUE_SIZE: usize = 64;
const DRAIN_GRACE: Duration = Duration::from_secs(2);
const DEFAULT_WRITE_TIMEOUT_MS: i64 = 30_000;
const DEFAULT_MAX_MESSAGE_BYTES: i64 = 1 << 20;

pub struct Registries {
    connections: Mutex<HashMap<String, Arc<PendingConnection>>>,
    servers: Mutex<HashMap<String, ServerHandle>>,
}

struct ServerHandle {
    stop_accepting: CancellationToken,
    connections: Vec<Arc<PendingConnection>>,
    /// Dropped with the handle, which is what ends the push loop when the server
    /// flow does.
    _pusher: Pusher,
}

impl Registries {
    pub fn new() -> Self {
        Registries {
            connections: Mutex::new(HashMap::new()),
            servers: Mutex::new(HashMap::new()),
        }
    }
}

fn registries() -> &'static Registries {
    crate::core::get().wsserver()
}

pub struct WsFeature;

static INSTANCE: WsFeature = WsFeature;

pub fn get() -> &'static WsFeature {
    &INSTANCE
}

impl Feature for WsFeature {
    fn handle(&self, task: Task) -> BoxFuture {
        Box::pin(async move {
            match task.message().method {
                Method::WsServe => handle_serve(task).await,
                Method::WsRespond => handle_respond(task).await,
                _ => {
                    task.add_result(Result::error(
                        task.message(),
                        ERRORS.by_text("unknown method"),
                    ))
                    .await;
                }
            }
        })
    }
}

#[derive(Clone)]
struct Config {
    idle_timeout_ms: i64,
    write_timeout_ms: i64,
    ping_interval_ms: i64,
    max_message_bytes: i64,
    max_concurrency: i64,
    path: String,
    allowed_origins: Vec<String>,
    subprotocols: Vec<String>,
    /// Counted whether or not a collector is listening: two relaxed atomics per
    /// connection, so there is nothing to save by branching on it.
    stats: Arc<ConnectionStats>,
}

impl Config {
    fn from(payload: &payloads::ServePayload) -> Self {
        Config {
            idle_timeout_ms: payload.idle_timeout_ms.max(0),
            write_timeout_ms: if payload.write_timeout_ms > 0 {
                payload.write_timeout_ms
            } else {
                DEFAULT_WRITE_TIMEOUT_MS
            },
            ping_interval_ms: payload.ping_interval_ms.max(0),
            max_message_bytes: if payload.max_message_bytes > 0 {
                payload.max_message_bytes
            } else {
                DEFAULT_MAX_MESSAGE_BYTES
            },
            max_concurrency: payload.max_concurrency.max(0),
            path: payload.path.clone(),
            allowed_origins: payload.allowed_origins.clone(),
            subprotocols: payload.subprotocols.clone(),
            stats: Arc::new(ConnectionStats::new()),
        }
    }
}

async fn handle_serve(task: Task) {
    let message = task.message();
    let start_time = Instant::now();

    let payload: payloads::ServePayload = match rmp_serde::from_slice(&message.payload) {
        Ok(payload) => payload,
        Err(error) => {
            task.add_result(Result::error(
                message,
                ERRORS.by_err("parse serve payload", error),
            ))
            .await;

            return;
        }
    };

    let listener =
        match crate::features::httpserver::listen::listen(&payload.address, payload.reuse_port) {
            Ok(listener) => listener,
            Err(error) => {
                task.add_result(Result::error(message, ERRORS.by_err("listen", error)))
                    .await;

                return;
            }
        };

    let stop_accepting = CancellationToken::new();

    // Built before the handle is registered, so the pusher and the accept loop
    // share one set of counters.
    let config = Config::from(&payload);

    registries().servers.lock().unwrap().insert(
        message.flow_key.clone(),
        ServerHandle {
            stop_accepting: stop_accepting.clone(),
            connections: Vec::new(),
            _pusher: Pusher::start(
                payload.server_name.clone(),
                payload.telemetry_socket.clone(),
                payload.telemetry_interval_ms,
                start_time,
                config.stats.clone(),
            ),
        },
    );

    let (connections_tx, mut connections_rx) = mpsc::channel(CONNECTION_QUEUE_SIZE);

    // Held for the life of the serve task: the stream must outlive the accept
    // loop, or a graceful stop ends it while handlers are still in flight.
    let _stream_open = connections_tx.clone();

    tokio::spawn(accept_loop(
        listener,
        task.message_arc(),
        config,
        connections_tx,
        task.context().clone(),
        stop_accepting,
    ));

    loop {
        let received = tokio::select! {
            biased;

            _ = task.context().cancelled() => {
                registries().servers.lock().unwrap().remove(&message.flow_key);

                return;
            }
            received = connections_rx.recv() => received,
        };

        match received {
            Some(event) => {
                task.add_result(Result::success_with_next(
                    message,
                    event.encode(),
                    calc_execution_ms(start_time),
                ))
                .await;
            }
            None => {
                registries().servers.lock().unwrap().remove(&message.flow_key);

                task.add_result(Result::success(
                    message,
                    Vec::new(),
                    calc_execution_ms(start_time),
                ))
                .await;

                return;
            }
        }
    }
}

async fn accept_loop(
    listener: tokio::net::TcpListener,
    message: Arc<Message>,
    config: Config,
    connections: mpsc::Sender<ConnectionEvent>,
    flow_ctx: CancellationToken,
    stop_accepting: CancellationToken,
) {
    let slots = if config.max_concurrency > 0 {
        Some(Arc::new(Semaphore::new(config.max_concurrency as usize)))
    } else {
        None
    };

    loop {
        let accepted = tokio::select! {
            biased;

            _ = flow_ctx.cancelled() => return,
            _ = stop_accepting.cancelled() => return,
            accepted = listener.accept() => accepted,
        };

        let Ok((stream, remote_addr)) = accepted else {
            continue;
        };

        let _ = stream.set_nodelay(true);

        let local_addr = stream
            .local_addr()
            .map(|address| address.to_string())
            .unwrap_or_default();

        let message = message.clone();
        let config = config.clone();
        let connections = connections.clone();
        let flow_ctx = flow_ctx.clone();
        let slots = slots.clone();

        tokio::spawn(async move {
            let service = service_fn(move |request: Request<hyper::body::Incoming>| {
                let message = message.clone();
                let config = config.clone();
                let connections = connections.clone();
                let flow_ctx = flow_ctx.clone();
                let slots = slots.clone();
                let local_addr = local_addr.clone();

                async move {
                    Ok::<_, std::convert::Infallible>(
                        upgrade_request(
                            request,
                            message,
                            config,
                            connections,
                            remote_addr,
                            local_addr,
                            flow_ctx,
                            slots,
                        )
                        .await,
                    )
                }
            });

            let _ = hyper::server::conn::http1::Builder::new()
                .serve_connection(TokioIo::new(stream), service)
                .with_upgrades()
                .await;
        });
    }
}

#[allow(clippy::too_many_arguments)]
async fn upgrade_request(
    mut request: Request<hyper::body::Incoming>,
    message: Arc<Message>,
    config: Config,
    connections: mpsc::Sender<ConnectionEvent>,
    remote_addr: std::net::SocketAddr,
    local_addr: String,
    flow_ctx: CancellationToken,
    slots: Option<Arc<Semaphore>>,
) -> Response<Full<Bytes>> {
    let path = request.uri().path().to_string();

    if !upgrade::is_upgrade_request(&request) {
        return refuse(StatusCode::UPGRADE_REQUIRED, "Upgrade Required");
    }

    if !config.path.is_empty() && path != config.path {
        return refuse(StatusCode::NOT_FOUND, "Not Found");
    }

    if !origin_allowed(&request, &config) {
        return refuse(StatusCode::FORBIDDEN, "Forbidden");
    }

    let subprotocol = negotiate_subprotocol(&request, &config);

    let (response, upgraded) = match upgrade::upgrade(&mut request) {
        Ok(pair) => pair,
        Err(_) => return refuse(StatusCode::BAD_REQUEST, "Bad Request"),
    };

    let connection_id = next_connection_id(&message.flow_key);

    {
        let event = ConnectionEvent {
            connection_id: connection_id.clone(),
            remote_addr: remote_addr.to_string(),
            local_addr,
            path,
            subprotocol: subprotocol.clone().unwrap_or_default(),
        };

        tokio::spawn(async move {
            // The permit is taken before the handshake completes, so a capped
            // server queues connections rather than upgrading them all.
            let _permit = match &slots {
                Some(slots) => match slots.clone().acquire_owned().await {
                    Ok(permit) => Some(permit),
                    Err(_) => return,
                },
                None => None,
            };

            let Ok(websocket) = upgraded.await else {
                return;
            };

            serve_connection(
                websocket,
                connection_id,
                event,
                message,
                config,
                connections,
                flow_ctx,
            )
            .await;
        });
    }

    // Rebuild the 101 with this server's body type, plus the negotiated
    // subprotocol the upgrade helper does not know about.
    let mut answer = Response::builder().status(response.status());

    for (name, value) in response.headers() {
        answer = answer.header(name, value);
    }

    if let Some(subprotocol) = subprotocol {
        answer = answer.header("Sec-WebSocket-Protocol", subprotocol);
    }

    answer
        .body(Full::new(Bytes::new()))
        .unwrap_or_else(|_| refuse(StatusCode::INTERNAL_SERVER_ERROR, "Internal Server Error"))
}

fn refuse(status: StatusCode, body: &'static str) -> Response<Full<Bytes>> {
    Response::builder()
        .status(status)
        .header("Content-Type", "text/plain; charset=utf-8")
        .body(Full::new(Bytes::from_static(body.as_bytes())))
        .expect("static response is always valid")
}

/// Skipped entirely when the PHP side listed no origins, which is how it says
/// "any origin".
fn origin_allowed(request: &Request<hyper::body::Incoming>, config: &Config) -> bool {
    if config.allowed_origins.is_empty() {
        return true;
    }

    let Some(origin) = request
        .headers()
        .get(hyper::header::ORIGIN)
        .and_then(|value| value.to_str().ok())
    else {
        // No Origin header at all is a non-browser client, which the browser
        // same-origin rule was never about.
        return true;
    };

    let host = origin
        .split("://")
        .nth(1)
        .unwrap_or(origin)
        .split('/')
        .next()
        .unwrap_or(origin);

    config
        .allowed_origins
        .iter()
        .any(|allowed| allowed == "*" || allowed.eq_ignore_ascii_case(host))
}

fn negotiate_subprotocol(
    request: &Request<hyper::body::Incoming>,
    config: &Config,
) -> Option<String> {
    if config.subprotocols.is_empty() {
        return None;
    }

    let offered = request
        .headers()
        .get("Sec-WebSocket-Protocol")
        .and_then(|value| value.to_str().ok())?;

    let offered: Vec<&str> = offered.split(',').map(str::trim).collect();

    // The server's order decides, not the client's: the configured list is a
    // preference, and iterating the offer instead would let a client pick.
    config
        .subprotocols
        .iter()
        .find(|supported| offered.iter().any(|candidate| candidate == supported))
        .cloned()
}

async fn serve_connection(
    websocket: fastwebsockets::WebSocket<TokioIo<hyper::upgrade::Upgraded>>,
    connection_id: String,
    event: ConnectionEvent,
    message: Arc<Message>,
    config: Config,
    connections: mpsc::Sender<ConnectionEvent>,
    flow_ctx: CancellationToken,
) {
    // Held for the life of the connection; its drop records the close, on an
    // abandoned path as much as on the orderly one.
    let _counted = config.stats.opened();

    let mut websocket = websocket;

    websocket.set_auto_close(true);
    websocket.set_auto_pong(true);
    websocket.set_max_message_size(config.max_message_bytes as usize);

    let (reader, writer) = websocket.split(tokio::io::split);

    // Shared because the read half must be able to answer a ping while the write
    // loop is idle; coder/websocket allows a concurrent read and write on one
    // connection, and this is the equivalent.
    let writer = Arc::new(tokio::sync::Mutex::new(writer));

    let (commands_tx, mut commands_rx) = mpsc::channel(1);
    let (messages_tx, messages_rx) = mpsc::channel(MESSAGE_QUEUE_SIZE);

    let pending = Arc::new(PendingConnection {
        commands: commands_tx,
        drain: CancellationToken::new(),
        closed: CancellationToken::new(),
    });

    let inbound_key = format!("{connection_id}:in");

    let state = Arc::new(crate::ws::MessageState::new(
        message.clone(),
        messages_rx,
        pending.drain.clone(),
    ));

    registries()
        .connections
        .lock()
        .unwrap()
        .insert(connection_id.clone(), pending.clone());

    if let Some(server) = registries()
        .servers
        .lock()
        .unwrap()
        .get_mut(&message.flow_key)
    {
        server.connections.push(pending.clone());
    }

    let _ = core_states::get().register(inbound_key.clone(), state);

    let published = tokio::select! {
        _ = flow_ctx.cancelled() => false,
        outcome = connections.send(event) => outcome.is_ok(),
    };

    if published {
        let read_writer = writer.clone();
        let read_closed = pending.closed.clone();
        let idle_timeout = config.idle_timeout_ms;

        let reader_task = tokio::spawn(async move {
            read_loop(reader, read_writer, messages_tx, idle_timeout, read_closed).await;
        });

        write_loop(writer, &mut commands_rx, &config, &pending, &flow_ctx).await;

        reader_task.abort();
    }

    registries()
        .connections
        .lock()
        .unwrap()
        .remove(&connection_id);

    if let Some(server) = registries()
        .servers
        .lock()
        .unwrap()
        .get_mut(&message.flow_key)
    {
        server
            .connections
            .retain(|held| !Arc::ptr_eq(held, &pending));
    }

    core_states::get().delete_state(&inbound_key).await;
}

type SocketWriter = fastwebsockets::WebSocketWrite<
    tokio::io::WriteHalf<TokioIo<hyper::upgrade::Upgraded>>,
>;
type SocketReader = fastwebsockets::WebSocketRead<
    tokio::io::ReadHalf<TokioIo<hyper::upgrade::Upgraded>>,
>;

/// Reads the connection continuously, so control frames are answered whether or
/// not the handler ever reads. Data messages go to the state; everything else is
/// handled here.
async fn read_loop(
    reader: SocketReader,
    writer: Arc<tokio::sync::Mutex<SocketWriter>>,
    messages: mpsc::Sender<InboundMessage>,
    idle_timeout_ms: i64,
    closed: CancellationToken,
) {
    let mut reader = FragmentCollectorRead::new(reader);

    // Bound outside the loop: read_frame borrows the closure, so a temporary
    // built in the call expression would be dropped while still borrowed.
    let obligated = writer.clone();

    let mut send_obligated = move |frame: Frame<'_>| {
        let writer = obligated.clone();
        let frame = Frame::new(frame.fin, frame.opcode, None, Payload::Owned(frame.payload.to_vec()));

        async move { writer.lock().await.write_frame(frame).await }
    };

    loop {
        let read = reader.read_frame(&mut send_obligated);

        let frame = if idle_timeout_ms > 0 {
            match tokio::time::timeout(Duration::from_millis(idle_timeout_ms as u64), read).await {
                Ok(frame) => frame,
                // The idle deadline ends the input, the way Go's read deadline
                // does; the handler sees end-of-stream, not an error.
                Err(_) => return,
            }
        } else {
            tokio::select! {
                _ = closed.cancelled() => return,
                frame = read => frame,
            }
        };

        let Ok(frame) = frame else {
            return;
        };

        match frame.opcode {
            OpCode::Text | OpCode::Binary => {
                let binary = frame.opcode == OpCode::Binary;

                if messages
                    .send(InboundMessage {
                        binary,
                        data: frame.payload.to_vec(),
                    })
                    .await
                    .is_err()
                {
                    return;
                }
            }
            OpCode::Close => return,
            _ => {}
        }
    }
}

async fn write_loop(
    writer: Arc<tokio::sync::Mutex<SocketWriter>>,
    commands: &mut mpsc::Receiver<crate::ws::WriteCommand>,
    config: &Config,
    pending: &PendingConnection,
    flow_ctx: &CancellationToken,
) {
    let write_timeout = Duration::from_millis(config.write_timeout_ms as u64);

    // interval_at, not interval: tokio's ticker fires immediately on its first
    // tick where Go's time.NewTicker waits a full period. That difference put a
    // keepalive ping on the wire before the connection's first real reply, and a
    // client reading one frame took the ping for the answer.
    let mut ping = config.ping_interval_ms.gt(&0).then(|| {
        let period = Duration::from_millis(config.ping_interval_ms as u64);

        tokio::time::interval_at(tokio::time::Instant::now() + period, period)
    });

    loop {
        let command = tokio::select! {
            biased;

            _ = flow_ctx.cancelled() => break,
            _ = pending.closed.cancelled() => break,
            _ = async {
                match ping.as_mut() {
                    Some(ping) => { ping.tick().await; }
                    None => std::future::pending::<()>().await,
                }
            } => {
                let sent = tokio::time::timeout(
                    write_timeout,
                    async {
                        writer
                            .lock()
                            .await
                            .write_frame(Frame::new(true, OpCode::Ping, None, Payload::Owned(Vec::new())))
                            .await
                    },
                )
                .await;

                // A keepalive that cannot be written means the connection is
                // gone; there is nothing left for the loop to do.
                if !matches!(sent, Ok(Ok(()))) {
                    break;
                }

                continue;
            }
            command = commands.recv() => command,
        };

        let Some(command) = command else {
            break;
        };

        if command.kind == WriteKind::Close {
            let _ = writer
                .lock()
                .await
                .write_frame(Frame::close(1000, b""))
                .await;

            let _ = command.done.send(Ok(()));

            break;
        }

        let opcode = if command.binary {
            OpCode::Binary
        } else {
            OpCode::Text
        };

        let written = tokio::time::timeout(write_timeout, async {
            writer
                .lock()
                .await
                .write_frame(Frame::new(true, opcode, None, Payload::Owned(command.data)))
                .await
        })
        .await;

        let outcome = match written {
            Ok(Ok(())) => Ok(()),
            Ok(Err(error)) => Err(error.to_string()),
            Err(_) => Err("write timeout".to_string()),
        };

        let failed = outcome.is_err();

        let _ = command.done.send(outcome);

        if failed {
            break;
        }
    }
}

async fn handle_respond(task: Task) {
    let message = task.message();
    let start_time = Instant::now();

    let id_only: payloads::RespondConnectionId = match rmp_serde::from_slice(&message.payload) {
        Ok(id_only) => id_only,
        Err(error) => {
            task.add_result(Result::error(
                message,
                ERRORS.by_err("parse respond connectionId", error),
            ))
            .await;

            return;
        }
    };

    let pending = registries()
        .connections
        .lock()
        .unwrap()
        .get(&id_only.connection_id)
        .cloned();

    let Some(pending) = pending else {
        task.add_result(Result::error(
            message,
            ERRORS.by_text(&format!("unknown connectionId {}", id_only.connection_id)),
        ))
        .await;

        return;
    };

    let payload: payloads::RespondPayload = match rmp_serde::from_slice(&message.payload) {
        Ok(payload) => payload,
        Err(error) => {
            task.add_result(Result::error(
                message,
                ERRORS.by_err("parse respond payload", error),
            ))
            .await;

            return;
        }
    };

    match dispatch(
        &pending,
        WriteKind::from_code(payload.op),
        is_binary(payload.message_type),
        payload.data_bytes(),
    )
    .await
    {
        Ok(()) => {
            task.add_result(Result::success(
                message,
                Vec::new(),
                calc_execution_ms(start_time),
            ))
            .await;
        }
        Err(error) => {
            task.add_result(Result::error(
                message,
                ERRORS.by_text(&format!("write response: {error}")),
            ))
            .await;
        }
    }
}

pub fn stop_accepting(flow_key: &str) {
    let connections = {
        let mut servers = registries().servers.lock().unwrap();

        let Some(server) = servers.get_mut(flow_key) else {
            return;
        };

        server.stop_accepting.cancel();

        server.connections.clone()
    };

    for pending in &connections {
        pending.start_drain();
    }

    // The Core's handle, not Handle::try_current(): this is called from the PHP
    // thread, which is outside the runtime, so try_current() finds nothing and
    // the force-close is never scheduled. A push-only connection then keeps its
    // handler alive to the shutdown timeout, and the server exits late — which
    // is what made SocketServerMaxConnectionsTest flaky.
    crate::core::get().runtime().spawn(async move {
        tokio::time::sleep(DRAIN_GRACE).await;

        for pending in &connections {
            pending.close();
        }
    });
}

pub fn shutdown() {
    let mut servers = registries().servers.lock().unwrap();

    for server in servers.values() {
        server.stop_accepting.cancel();

        for pending in &server.connections {
            pending.close();
        }
    }

    servers.clear();
}
