//! Mirrors ext/internal/features/wsclient.
//!
//! The ws server's per-connection handling, initiated by a dial and a client
//! handshake instead of an upgrade. The shape is the socket client's — the
//! connect task's own stream carries the metadata first and inbound messages
//! after — and the two loops are the ws server's, for the same reason: reading
//! continuously is what answers a ping when the handler never reads.

pub mod payloads;

use fastwebsockets::{FragmentCollectorRead, Frame, OpCode, Payload, handshake};
use hyper::Request;
use hyper::body::Bytes;
use hyper_util::rt::{TokioExecutor, TokioIo};
use http_body_util::Empty;
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
use crate::socket::next_connection_id;
use crate::states as core_states;
use crate::states::{StateCloseFuture, StateContract, StateFuture};
use crate::tasks::Task;
use crate::ws::{InboundMessage, MessageState, PendingConnection, WriteKind, dispatch, is_binary};

static ERRORS: Factory = Factory::new("wsClient");

/// Prefixed onto a connect-failure payload so the PHP side maps it to its
/// connect exception, as the socket client does.
const NETWORK_ERROR_MARKER: &str = "net";

const DEFAULT_CONNECT_TIMEOUT_MS: i64 = 10_000;
const DEFAULT_WRITE_TIMEOUT_MS: i64 = 30_000;
const MESSAGE_QUEUE_SIZE: usize = 64;

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
    crate::core::get().wsclient()
}

pub struct WsClientFeature;

static INSTANCE: WsClientFeature = WsClientFeature;

pub fn get() -> &'static WsClientFeature {
    &INSTANCE
}

impl Feature for WsClientFeature {
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

struct ConnectionState {
    message: Arc<Message>,
    meta: payloads::ConnectionMeta,
    meta_sent: AtomicBool,
    inbound: MessageState,
    start_time: Instant,
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

    let (dial_address, authority, path) = match payloads::split_address(&params.address) {
        Ok(parts) => parts,
        Err(error) => {
            task.add_result(Result::error(message, network_error(&error)))
                .await;

            return;
        }
    };

    let connect_timeout = Duration::from_millis(if params.connect_timeout_ms > 0 {
        params.connect_timeout_ms
    } else {
        DEFAULT_CONNECT_TIMEOUT_MS
    } as u64);

    // One budget for the dial and the handshake together, the way the PHP side
    // documents connectTimeoutMs.
    let established = tokio::time::timeout(
        connect_timeout,
        establish(&dial_address, &authority, &path, &params.subprotocols),
    )
    .await;

    let (websocket, subprotocol, remote_addr, local_addr) = match established {
        Ok(Ok(parts)) => parts,
        Ok(Err(error)) => {
            task.add_result(Result::error(
                message,
                network_error(&format!("connect {}: {error}", params.address)),
            ))
            .await;

            return;
        }
        Err(_) => {
            task.add_result(Result::error(
                message,
                network_error(&format!("connect {}: timeout", params.address)),
            ))
            .await;

            return;
        }
    };

    let connection_id = next_connection_id(&message.flow_key);

    let mut websocket = websocket;

    websocket.set_auto_close(true);
    websocket.set_auto_pong(true);

    if params.max_message_bytes > 0 {
        websocket.set_max_message_size(params.max_message_bytes as usize);
    }

    let (reader, writer) = websocket.split(tokio::io::split);
    let writer = Arc::new(tokio::sync::Mutex::new(writer));

    let (commands_tx, mut commands_rx) = mpsc::channel(1);
    let (messages_tx, messages_rx) = mpsc::channel(MESSAGE_QUEUE_SIZE);

    let pending = Arc::new(PendingConnection {
        commands: commands_tx,
        drain: CancellationToken::new(),
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

    spawn_read_loop(
        reader,
        writer.clone(),
        messages_tx,
        params.read_timeout_ms.max(0),
        pending.clone(),
    );

    spawn_write_loop(
        writer,
        commands_rx_owned(&mut commands_rx),
        write_timeout,
        pending.clone(),
        task.context().clone(),
    );

    let state = Arc::new(ConnectionState {
        message: task.message_arc(),
        meta: payloads::ConnectionMeta {
            connection_id: connection_id.clone(),
            remote_addr,
            local_addr,
            subprotocol,
        },
        meta_sent: AtomicBool::new(false),
        inbound: MessageState::new(task.message_arc(), messages_rx, pending.drain.clone()),
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

/// Moves the receiver out so it can be owned by the spawned write loop; the
/// caller keeps nothing that would hold the channel open.
fn commands_rx_owned(
    commands: &mut mpsc::Receiver<crate::ws::WriteCommand>,
) -> mpsc::Receiver<crate::ws::WriteCommand> {
    std::mem::replace(commands, mpsc::channel(1).1)
}

type ClientWriter =
    fastwebsockets::WebSocketWrite<tokio::io::WriteHalf<TokioIo<hyper::upgrade::Upgraded>>>;
type ClientReader =
    fastwebsockets::WebSocketRead<tokio::io::ReadHalf<TokioIo<hyper::upgrade::Upgraded>>>;

async fn establish(
    dial_address: &str,
    authority: &str,
    path: &str,
    subprotocols: &[String],
) -> std::result::Result<
    (
        fastwebsockets::WebSocket<TokioIo<hyper::upgrade::Upgraded>>,
        String,
        String,
        String,
    ),
    String,
> {
    let stream = tokio::net::TcpStream::connect(dial_address)
        .await
        .map_err(|error| error.to_string())?;

    let _ = stream.set_nodelay(true);

    let remote_addr = stream
        .peer_addr()
        .map(|address| address.to_string())
        .unwrap_or_default();
    let local_addr = stream
        .local_addr()
        .map(|address| address.to_string())
        .unwrap_or_default();

    let mut request = Request::builder()
        .method("GET")
        .uri(path)
        .header("Host", authority)
        .header(hyper::header::UPGRADE, "websocket")
        .header(hyper::header::CONNECTION, "upgrade")
        .header("Sec-WebSocket-Key", handshake::generate_key())
        .header("Sec-WebSocket-Version", "13");

    if !subprotocols.is_empty() {
        request = request.header("Sec-WebSocket-Protocol", subprotocols.join(", "));
    }

    let request = request
        .body(Empty::<Bytes>::new())
        .map_err(|error| error.to_string())?;

    let (websocket, response) = handshake::client(&TokioExecutor::new(), request, stream)
        .await
        .map_err(|error| error.to_string())?;

    let subprotocol = response
        .headers()
        .get("Sec-WebSocket-Protocol")
        .and_then(|value| value.to_str().ok())
        .unwrap_or_default()
        .to_string();

    Ok((websocket, subprotocol, remote_addr, local_addr))
}

fn spawn_read_loop(
    reader: ClientReader,
    writer: Arc<tokio::sync::Mutex<ClientWriter>>,
    messages: mpsc::Sender<InboundMessage>,
    idle_timeout_ms: i64,
    pending: Arc<PendingConnection>,
) {
    tokio::spawn(async move {
        let mut reader = FragmentCollectorRead::new(reader);

        // Bound outside the loop: read_frame borrows the closure.
        let obligated = writer.clone();

        let mut send_obligated = move |frame: Frame<'_>| {
            let writer = obligated.clone();
            let frame = Frame::new(
                frame.fin,
                frame.opcode,
                None,
                Payload::Owned(frame.payload.to_vec()),
            );

            async move { writer.lock().await.write_frame(frame).await }
        };

        loop {
            let read = reader.read_frame(&mut send_obligated);

            let frame = if idle_timeout_ms > 0 {
                match tokio::time::timeout(Duration::from_millis(idle_timeout_ms as u64), read)
                    .await
                {
                    Ok(frame) => frame,
                    // The idle deadline ends the input; the handler sees
                    // end-of-stream, not an error.
                    Err(_) => break,
                }
            } else {
                tokio::select! {
                    _ = pending.closed.cancelled() => break,
                    frame = read => frame,
                }
            };

            let Ok(frame) = frame else {
                break;
            };

            match frame.opcode {
                OpCode::Text | OpCode::Binary => {
                    if messages
                        .send(InboundMessage {
                            binary: frame.opcode == OpCode::Binary,
                            data: frame.payload.to_vec(),
                        })
                        .await
                        .is_err()
                    {
                        break;
                    }
                }
                OpCode::Close => break,
                _ => {}
            }
        }

        // The input is over: release a handler parked on read().
        pending.drain.cancel();
    });
}

fn spawn_write_loop(
    writer: Arc<tokio::sync::Mutex<ClientWriter>>,
    mut commands: mpsc::Receiver<crate::ws::WriteCommand>,
    write_timeout: Duration,
    pending: Arc<PendingConnection>,
    flow_ctx: CancellationToken,
) {
    tokio::spawn(async move {
        loop {
            let command = tokio::select! {
                biased;

                _ = flow_ctx.cancelled() => break,
                _ = pending.closed.cancelled() => break,
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

        // Whichever side ends first releases the other.
        pending.closed.cancel();
        pending.drain.cancel();
    });
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
        is_binary(params.message_type),
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

    route(
        task,
        &params.connection_id,
        WriteKind::Close,
        false,
        Vec::new(),
    )
    .await;
}

async fn route(
    task: &Task,
    connection_id: &str,
    kind: WriteKind,
    binary: bool,
    data: Vec<u8>,
) {
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
        task.add_result(Result::error(
            message,
            ERRORS.by_text(&format!("unknown connectionId {connection_id}")),
        ))
        .await;

        return;
    };

    match dispatch(&pending, kind, binary, data).await {
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
