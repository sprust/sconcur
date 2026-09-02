//! Mirrors ext/internal/features/socketserver.
//!
//! The shape is the HTTP server's: a self-pumping accept stream publishes one
//! connection event per accepted connection, and a respond command routes a
//! write or a close to that connection's write loop. What differs is the
//! per-connection inbound stream — a socket handler reads frames itself, so each
//! connection registers a MessageState PHP pulls with next().

pub mod listen;
pub mod payloads;

use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};
use tokio::net::TcpListener;
use tokio::sync::{Semaphore, mpsc};
use tokio_util::sync::CancellationToken;

use crate::dto::{Message, Result};
use crate::errs::Factory;
use crate::features::{BoxFuture, Feature};
use crate::helpers::calc_execution_ms;
use crate::socket::{MessageState, PendingConnection, WriteKind, dispatch, next_connection_id};
use crate::states as core_states;
use crate::tasks::Task;
use crate::types::method::Method;

use payloads::ConnectionEvent;

static ERRORS: Factory = Factory::new("socketServer");

/// Buffers accepted connections handed off by the accept loop but not yet pulled
/// by the PHP serve loop. A smoothing buffer for accept bursts; the real
/// backpressure is maxConcurrency.
const CONNECTION_QUEUE_SIZE: usize = 1024;

/// Bounds how long a connection may keep its handler alive after the listener
/// stops accepting; past it the connection is closed for good, so a push-only
/// handler that never reads still unwinds.
const DRAIN_GRACE: Duration = Duration::from_secs(2);

const DEFAULT_WRITE_TIMEOUT_MS: i64 = 30_000;
const DEFAULT_MAX_MESSAGE_BYTES: i64 = 1 << 20;

/// The feature's process-wide registries, on the Core so a fork discards them.
pub struct Registries {
    /// Maps a connectionId to its write loop's rendezvous, keyed per process so
    /// a respond arriving on another flow finds it.
    connections: Mutex<HashMap<String, Arc<PendingConnection>>>,
    servers: Mutex<HashMap<String, ServerHandle>>,
}

struct ServerHandle {
    stop_accepting: CancellationToken,
    connections: Vec<Arc<PendingConnection>>,
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
    crate::core::get().socketserver()
}

pub struct SocketFeature;

static INSTANCE: SocketFeature = SocketFeature;

pub fn get() -> &'static SocketFeature {
    &INSTANCE
}

impl Feature for SocketFeature {
    fn handle(&self, task: Task) -> BoxFuture {
        Box::pin(async move {
            match task.message().method {
                Method::SocketServe => handle_serve(task).await,
                Method::SocketRespond => handle_respond(task).await,
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

/// Opens the listener and starts the self-pumping accept stream: each accepted
/// connection is delivered to PHP as the next stream result.
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

    let listener = match listen::listen(&payload.address, payload.reuse_port) {
        Ok(listener) => listener,
        Err(error) => {
            task.add_result(Result::error(message, ERRORS.by_err("listen", error)))
                .await;

            return;
        }
    };

    let stop_accepting = CancellationToken::new();

    registries().servers.lock().unwrap().insert(
        message.flow_key.clone(),
        ServerHandle {
            stop_accepting: stop_accepting.clone(),
            connections: Vec::new(),
        },
    );

    let (connections_tx, mut connections_rx) = mpsc::channel(CONNECTION_QUEUE_SIZE);

    // Held for the life of the serve task on purpose. Without it the channel
    // closes as soon as the accept loop returns — which is exactly what a
    // graceful stop does — and the stream would end while handlers are still in
    // flight. The PHP serve loop would then leave its drain and unwind them,
    // instead of waiting. Go gets this from selecting on the flow context.
    let _stream_open = connections_tx.clone();

    tokio::spawn(accept_loop(
        listener,
        task.message_arc(),
        Config::from(&payload),
        connections_tx,
        task.context().clone(),
        stop_accepting,
    ));

    // The pump runs as this task's own body: the serve task has nothing else to
    // do, and the stream then lives exactly as long as the task PHP awaits.
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
            // Unreachable while this task holds a sender; kept as the honest
            // end-of-stream answer rather than an unwrap.
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

/// The resolved tuning for one server, with the defaults the PHP side mirrors.
#[derive(Clone)]
struct Config {
    read_timeout_ms: i64,
    write_timeout_ms: i64,
    max_message_bytes: i64,
    max_concurrency: i64,
}

impl Config {
    fn from(payload: &payloads::ServePayload) -> Self {
        Config {
            // 0 stays 0: an idle connection may live forever.
            read_timeout_ms: payload.read_timeout_ms.max(0),
            write_timeout_ms: if payload.write_timeout_ms > 0 {
                payload.write_timeout_ms
            } else {
                DEFAULT_WRITE_TIMEOUT_MS
            },
            max_message_bytes: if payload.max_message_bytes > 0 {
                payload.max_message_bytes
            } else {
                DEFAULT_MAX_MESSAGE_BYTES
            },
            max_concurrency: payload.max_concurrency.max(0),
        }
    }
}

async fn accept_loop(
    listener: TcpListener,
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

        let message = message.clone();
        let config = config.clone();
        let connections = connections.clone();
        let flow_ctx = flow_ctx.clone();
        let slots = slots.clone();

        tokio::spawn(async move {
            // The concurrency cap is taken before anything else the connection
            // costs, so a capped server queues connections rather than buffering
            // their state.
            let _permit = match &slots {
                Some(slots) => match slots.clone().acquire_owned().await {
                    Ok(permit) => Some(permit),
                    Err(_) => return,
                },
                None => None,
            };

            serve_connection(stream, remote_addr, message, config, connections, flow_ctx).await;
        });
    }
}

/// One access-log line for a finished connection:
/// `<ISO-start-time> <remoteAddr> frames=<n> <status> <ms>ms`, where frames is
/// how many were written to the client over its life.
fn access_line(
    started_at: std::time::SystemTime,
    elapsed: Duration,
    remote_addr: &str,
    frame_count: u64,
    status: &str,
) -> String {
    format!(
        "{} {} frames={} {} {:.2}ms\n",
        crate::logger::timestamp(started_at),
        crate::logger::sanitize(remote_addr),
        frame_count,
        status,
        elapsed.as_secs_f64() * 1000.0,
    )
}

async fn serve_connection(
    stream: tokio::net::TcpStream,
    remote_addr: std::net::SocketAddr,
    message: Arc<Message>,
    config: Config,
    connections: mpsc::Sender<ConnectionEvent>,
    flow_ctx: CancellationToken,
) {
    let local_addr = stream
        .local_addr()
        .map(|address| address.to_string())
        .unwrap_or_default();

    let started_at = std::time::SystemTime::now();
    let started = Instant::now();

    let connection_id = next_connection_id(&message.flow_key);
    let inbound_key = format!("{connection_id}:in");

    let (reader, mut writer) = stream.into_split();

    let (commands_tx, mut commands_rx) = mpsc::channel(1);

    let pending = Arc::new(PendingConnection {
        commands: commands_tx,
        read_stopped: CancellationToken::new(),
        closed: CancellationToken::new(),
    });

    let state = Arc::new(MessageState::new(
        message.clone(),
        reader,
        config.read_timeout_ms,
        config.max_message_bytes,
        &ERRORS,
        pending.read_stopped.clone(),
    ));

    {
        let mut registry = registries().connections.lock().unwrap();

        registry.insert(connection_id.clone(), pending.clone());
    }

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
        outcome = connections.send(ConnectionEvent {
            connection_id: connection_id.clone(),
            remote_addr: remote_addr.to_string(),
            local_addr,
        }) => outcome.is_ok(),
    };

    let mut frame_count: u64 = 0;
    let mut status = if published { "ok" } else { "shutdown" };

    if published {
        let write_timeout = Duration::from_millis(config.write_timeout_ms as u64);

        loop {
            let command = tokio::select! {
                biased;

                _ = flow_ctx.cancelled() => {
                    status = "shutdown";

                    break;
                }
                _ = pending.closed.cancelled() => {
                    status = "shutdown";

                    break;
                }
                command = commands_rx.recv() => command,
            };

            let Some(command) = command else {
                break;
            };

            if command.kind == WriteKind::Close {
                let _ = command.done.send(Ok(()));

                break;
            }

            let written = tokio::time::timeout(
                write_timeout,
                crate::socket::write_frame(&mut writer, &command.data),
            )
            .await;

            let outcome = match written {
                Ok(outcome) => outcome,
                Err(_) => Err("write timeout".to_string()),
            };

            let failed = outcome.is_err();

            let _ = command.done.send(outcome);

            if failed {
                status = "write_error";

                break;
            }

            frame_count += 1;
        }
    }

    // Once the write loop stops consuming, a handler still trying to write is
    // released by the command channel closing with this scope.
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

    crate::logger::write(access_line(
        started_at,
        started.elapsed(),
        &remote_addr.to_string(),
        frame_count,
        status,
    ));
}

/// Routes one action (write a frame, or close) from a PHP connection handler to
/// the waiting connection's write loop.
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

    if id_only.connection_id.is_empty() {
        task.add_result(Result::error(
            message,
            ERRORS.by_text("parse respond connectionId"),
        ))
        .await;

        return;
    }

    let pending = registries()
        .connections
        .lock()
        .unwrap()
        .get(&id_only.connection_id)
        .cloned();

    let Some(pending) = pending else {
        // The connection is already gone (closed or disconnected).
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

/// Closes the listener of the given server flow and ends the inbound stream of
/// every in-flight connection, so on a SO_REUSEPORT pool the kernel routes new
/// connections to siblings while this one drains. A handler that never reads
/// would not notice, so after a bounded grace every connection is closed for
/// good and its next write fails.
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
        pending.close_read();
    }

    if let Ok(handle) = tokio::runtime::Handle::try_current() {
        handle.spawn(async move {
            tokio::time::sleep(DRAIN_GRACE).await;

            for pending in &connections {
                pending.close();
            }
        });
    }
}

/// Mirrors the feature's share of features.Shutdown.
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
