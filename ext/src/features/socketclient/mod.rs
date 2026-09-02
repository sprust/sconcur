//! Mirrors ext-go-legacy/internal/features/socketclient.
//!
//! The socket server's per-connection handling, initiated by a dial instead of
//! an accept: the same `socket` plumbing carries the framing, the write loop and
//! the inbound stream. What differs is that the connection's stream is the
//! connect task's own — its first result is the connection metadata, and every
//! one after it is an inbound frame.

pub mod payloads;

use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};
use tokio::sync::mpsc;
use tokio_util::sync::CancellationToken;

use crate::dto::{Message, Result};
use crate::errs::Factory;
use crate::features::{BoxFuture, Feature};
use crate::helpers::calc_execution_ms;
use crate::socket::{MessageState, PendingConnection, WriteKind, dispatch, next_connection_id};
use crate::states as core_states;
use crate::states::{StateCloseFuture, StateContract, StateFuture};
use crate::tasks::Task;

static ERRORS: Factory = Factory::new("socketClient");

/// Prefixed onto a dial-failure payload so the PHP side maps it to
/// SocketClientConnectException, mirroring the HTTP client's markers.
const NETWORK_ERROR_MARKER: &str = "net";

const DEFAULT_CONNECT_TIMEOUT_MS: i64 = 10_000;
const DEFAULT_WRITE_TIMEOUT_MS: i64 = 30_000;
const DEFAULT_MAX_MESSAGE_BYTES: i64 = 1 << 20;

pub struct Registries {
    connections: Mutex<HashMap<String, Arc<PendingConnection>>>,
}

impl Registries {
    pub fn new() -> Self {
        Registries {
            connections: Mutex::new(HashMap::new()),
        }
    }
}

fn registries() -> &'static Registries {
    crate::core::get().socketclient()
}

pub struct SocketClientFeature;

static INSTANCE: SocketClientFeature = SocketClientFeature;

pub fn get() -> &'static SocketClientFeature {
    &INSTANCE
}

impl Feature for SocketClientFeature {
    fn handle(&self, task: Task) -> BoxFuture {
        Box::pin(async move {
            let message = task.message();

            let envelope: payloads::Envelope = match rmp_serde::from_slice(&message.payload) {
                Ok(envelope) => envelope,
                Err(error) => {
                    task.add_result(Result::error(
                        message,
                        ERRORS.by_err("parse envelope", error),
                    ))
                    .await;

                    return;
                }
            };

            match envelope.command.as_str() {
                "con" => handle_connect(&task, envelope.params).await,
                "snd" => handle_send(&task, envelope.params).await,
                "cls" => handle_close(&task, envelope.params).await,
                _ => {
                    task.add_result(Result::error(message, ERRORS.by_text("unknown command")))
                        .await;
                }
            }
        })
    }
}

/// Streams one dialed connection: the first `next` returns the connection
/// metadata, every one after it the next inbound frame. The client mirror of the
/// server's per-connection stream, with the metadata prepended.
struct ConnectionState {
    message: Arc<Message>,
    meta: payloads::ConnectionMeta,
    meta_sent: AtomicBool,
    inbound: MessageState,
    start_time: Instant,
    /// Closes the connection and unregisters it. Idempotent, and shared with the
    /// write loop so whichever side ends first releases the other.
    closed: CancellationToken,
    connection_id: String,
}

impl StateContract for ConnectionState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            if !self.meta_sent.swap(true, Ordering::SeqCst) {
                return Result::success_with_next(
                    &self.message,
                    self.meta.encode(),
                    calc_execution_ms(self.start_time),
                );
            }

            self.inbound.next().await
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            // Closing first unblocks an in-flight inbound read; the inbound
            // state's own close is a no-op beyond that.
            self.closed.cancel();

            registries()
                .connections
                .lock()
                .unwrap()
                .remove(&self.connection_id);

            self.inbound.close().await;
        })
    }
}

async fn handle_connect(task: &Task, params: rmpv::Value) {
    let message = task.message();
    let start_time = Instant::now();

    let params: payloads::ConnectParams = match rmpv::ext::from_value(params) {
        Ok(params) => params,
        Err(error) => {
            task.add_result(Result::error(
                message,
                ERRORS.by_err("parse connect params", error),
            ))
            .await;

            return;
        }
    };

    let connect_timeout = Duration::from_millis(if params.connect_timeout_ms > 0 {
        params.connect_timeout_ms
    } else {
        DEFAULT_CONNECT_TIMEOUT_MS
    } as u64);

    let dialed = tokio::time::timeout(
        connect_timeout,
        tokio::net::TcpStream::connect(&params.address),
    )
    .await;

    let stream = match dialed {
        Ok(Ok(stream)) => stream,
        Ok(Err(error)) => {
            // A dial failure is a network error, not a protocol one: the marker
            // is what makes PHP raise SocketClientConnectException.
            task.add_result(Result::error(
                message,
                network_error(&format!("dial {}: {error}", params.address)),
            ))
            .await;

            return;
        }
        Err(_) => {
            task.add_result(Result::error(
                message,
                network_error(&format!("dial {}: timeout", params.address)),
            ))
            .await;

            return;
        }
    };

    let _ = stream.set_nodelay(true);

    let remote_addr = stream
        .peer_addr()
        .map(|address| address.to_string())
        .unwrap_or_default();
    let local_addr = stream
        .local_addr()
        .map(|address| address.to_string())
        .unwrap_or_default();

    let connection_id = next_connection_id(&message.flow_key);

    let (reader, mut writer) = stream.into_split();
    let (commands_tx, mut commands_rx) = mpsc::channel(1);

    let pending = Arc::new(PendingConnection {
        commands: commands_tx,
        read_stopped: CancellationToken::new(),
        closed: CancellationToken::new(),
    });

    registries()
        .connections
        .lock()
        .unwrap()
        .insert(connection_id.clone(), pending.clone());

    let write_timeout = Duration::from_millis(if params.write_timeout_ms > 0 {
        params.write_timeout_ms
    } else {
        DEFAULT_WRITE_TIMEOUT_MS
    } as u64);

    let write_closed = pending.closed.clone();
    let write_read_stopped = pending.read_stopped.clone();
    let flow_ctx = task.context().clone();

    tokio::spawn(async move {
        loop {
            let command = tokio::select! {
                biased;

                _ = flow_ctx.cancelled() => break,
                _ = write_closed.cancelled() => break,
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
                break;
            }
        }

        // Whichever side ends first releases the other: a reader still parked on
        // the socket has to learn the connection is over.
        write_closed.cancel();
        write_read_stopped.cancel();
    });

    let max_message_bytes = if params.max_message_bytes > 0 {
        params.max_message_bytes
    } else {
        DEFAULT_MAX_MESSAGE_BYTES
    };

    let state = Arc::new(ConnectionState {
        message: task.message_arc(),
        meta: payloads::ConnectionMeta {
            connection_id: connection_id.clone(),
            remote_addr,
            local_addr,
        },
        meta_sent: AtomicBool::new(false),
        inbound: MessageState::new(
            task.message_arc(),
            reader,
            params.read_timeout_ms.max(0),
            max_message_bytes,
            &ERRORS,
            pending.read_stopped.clone(),
        ),
        start_time,
        closed: pending.closed.clone(),
        connection_id: connection_id.clone(),
    });

    match core_states::get()
        .start(task.context().clone(), &message.task_key, state.clone())
        .await
    {
        Ok(result) => task.add_result(result).await,
        Err(error) => {
            state.close().await;

            task.add_result(Result::error(
                message,
                ERRORS.by_err("start connection", error),
            ))
            .await;
        }
    }
}

async fn handle_send(task: &Task, params: rmpv::Value) {
    let params: payloads::SendParams = match rmpv::ext::from_value(params) {
        Ok(params) => params,
        Err(error) => {
            task.add_result(Result::error(
                task.message(),
                ERRORS.by_err("parse send params", error),
            ))
            .await;

            return;
        }
    };

    route(
        task,
        &params.connection_id,
        WriteKind::Frame,
        params.data_bytes(),
    )
    .await;
}

async fn handle_close(task: &Task, params: rmpv::Value) {
    let params: payloads::CloseParams = match rmpv::ext::from_value(params) {
        Ok(params) => params,
        Err(error) => {
            task.add_result(Result::error(
                task.message(),
                ERRORS.by_err("parse close params", error),
            ))
            .await;

            return;
        }
    };

    route(task, &params.connection_id, WriteKind::Close, Vec::new()).await;
}

/// Routes one action to the connection's write loop, keyed by connection id, and
/// waits for it to be applied.
async fn route(task: &Task, connection_id: &str, kind: WriteKind, data: Vec<u8>) {
    let message = task.message();
    let start_time = Instant::now();

    if connection_id.is_empty() {
        task.add_result(Result::error(message, ERRORS.by_text("empty connectionId")))
            .await;

        return;
    }

    let pending = registries()
        .connections
        .lock()
        .unwrap()
        .get(connection_id)
        .cloned();

    let Some(pending) = pending else {
        // The connection is already gone (closed or disconnected).
        task.add_result(Result::error(
            message,
            ERRORS.by_text(&format!("unknown connectionId {connection_id}")),
        ))
        .await;

        return;
    };

    match dispatch(&pending, kind, data).await {
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
                ERRORS.by_text(&format!("write: {error}")),
            ))
            .await;
        }
    }
}

fn network_error(text: &str) -> String {
    format!("{NETWORK_ERROR_MARKER}: {}", ERRORS.by_text(text))
}
