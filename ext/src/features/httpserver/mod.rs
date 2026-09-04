//! The HTTP server feature: the command dispatch behind serve and respond.

pub mod listen;
pub mod payloads;
pub mod requeststats;
pub mod server;

pub use server::Registries as HttpRegistries;

use std::collections::HashMap;
use std::sync::{Arc, OnceLock};
use std::time::Instant;
use tokio::sync::mpsc;
use tokio_util::sync::CancellationToken;

use crate::dto::Result;
use crate::errs::Factory;
use crate::features::{BoxFuture, Feature};
use crate::helpers::calc_execution_ms;
use crate::stats::Pusher;
use crate::tasks::Task;
use crate::types::method::Method;
use server::{ServerState, WriteCommand, WriteKind};

static ERR_FACTORY: Factory = Factory::new("httpServer");
static INSTANCE: OnceLock<HttpFeature> = OnceLock::new();

/// The feature's registries, owned by the Core so a fork discards them.
/// `server_states` maps a server flow key to its state, so stop_accepting can
/// find the listener to close on graceful shutdown.
fn registries() -> &'static server::Registries {
    crate::core::get().http()
}

pub struct HttpFeature;

pub fn get() -> &'static HttpFeature {
    INSTANCE.get_or_init(|| HttpFeature)
}

impl Feature for HttpFeature {
    fn handle(&self, task: Task) -> BoxFuture {
        Box::pin(async move {
            match task.message().method {
                Method::HttpServe => handle_serve(task).await,
                Method::HttpRespond => {
                    if let Some(result) = respond(&task).await {
                        task.add_result(result).await;
                    }
                }
                _ => {
                    task.add_result(Result::error(
                        task.message(),
                        ERR_FACTORY.by_text("unknown method"),
                    )).await;
                }
            }
        })
    }

    fn handle_detached(&self, task: Task) {
        if let Some(result) = respond_detached(&task) {
            task.add_result_detached(result);
        }
    }
}

/// Opens the listener and starts the request stream: each accepted request is
/// delivered to PHP as the next result of this task.
///
/// The stream is self-pumping — every accepted request is published as soon as
/// the previous one is consumed, so PHP never pays a next() crossing (plus a
/// task) per request. Backpressure is layered: add_result blocks on the shared
/// results buffer, the requests channel buffers accepts, and beyond that the
/// connection task blocks on its send. The stream ends with the first no-next
/// result (server stopped).
async fn handle_serve(task: Task) {
    let message = task.message();
    let start_time = Instant::now();

    let payload: payloads::ServePayload = match rmp_serde::from_slice(&message.payload) {
        Ok(payload) => payload,
        Err(error) => {
            task.add_result(Result::error(
                message,
                ERR_FACTORY.by_err("parse serve payload", error),
            )).await;

            return;
        }
    };

    let listener = match listen::listen(&payload.address, payload.reuse_port) {
        Ok(listener) => listener,
        Err(error) => {
            task.add_result(Result::error(message, ERR_FACTORY.by_err("listen", error))).await;

            return;
        }
    };

    let stop_accepting = CancellationToken::new();

    // Counted only when somebody is collecting. Without a telemetry socket the
    // pusher is a no-op and nothing would ever read these numbers, so an
    // unwatched server keeps its request path free of the bookkeeping entirely.
    let request_stats = if payload.telemetry_socket.is_empty() {
        None
    } else {
        Some(Arc::new(requeststats::RequestStats::new()))
    };

    let pusher = match &request_stats {
        Some(stats) => Pusher::start(
            payload.server_name.clone(),
            payload.telemetry_socket.clone(),
            payload.telemetry_interval_ms,
            start_time,
            stats.clone(),
        ),
        None => Pusher::disabled(),
    };

    registries().server_states.lock().unwrap().insert(
        message.flow_key.clone(),
        ServerState {
            stop_accepting: stop_accepting.clone(),
            _pusher: pusher,
        },
    );

    let (requests_tx, mut requests_rx) = mpsc::channel(server::request_queue_size());

    // Held for the life of the serve task: see the socket server's note. The
    // stream must outlive the accept loop, or a graceful stop ends it while
    // requests are still in flight and the PHP serve loop unwinds them.
    let _stream_open = requests_tx.clone();

    tokio::spawn(server::accept_loop(
        registries(),
        listener,
        message.flow_key.clone(),
        requests_tx,
        payload.handler_timeout_ms,
        payload.max_request_body,
        payload.max_concurrency,
        request_stats,
        task.context().clone(),
        stop_accepting,
    ));

    // The pump runs as this task's own body rather than in a second spawn: the
    // serve task has nothing else to do, and keeping it here means the stream
    // lives exactly as long as the task PHP is awaiting.
    loop {
        let received = tokio::select! {
            biased;

            // The flow was stopped: nobody is waiting for this stream any more,
            // so there is no final result to publish — just release the
            // listener registration and end the task.
            _ = task.context().cancelled() => {
                registries().server_states.lock().unwrap().remove(&message.flow_key);

                return;
            }
            received = requests_rx.recv() => received,
        };

        match received {
            Some(event) => {
                task.add_result(Result::success_with_next(
                    message,
                    event.encode(),
                    calc_execution_ms(start_time),
                )).await;
            }
            None => {
                registries().server_states.lock().unwrap().remove(&message.flow_key);

                task.add_result(Result::success(
                    message,
                    Vec::new(),
                    calc_execution_ms(start_time),
                )).await;

                return;
            }
        }
    }
}

/// Routes one write command from a PHP handler to the waiting connection. It
/// never leaves the connection hanging: as long as the request id resolves, the
/// client always gets an answer.
async fn respond(task: &Task) -> Option<Result> {
    let message = task.message();
    let start_time = Instant::now();

    // The payload carries the request id, so the happy path decodes once. A
    // malformed payload falls back to the id-only struct below, which ignores
    // every other key — the connection is still answered rather than left
    // hanging, which is the property the two-decode order used to buy on every
    // single response.
    let mut payload: payloads::RespondPayload = match rmp_serde::from_slice(&message.payload) {
        Ok(payload) => payload,
        Err(parse_error) => {
            let id_only: payloads::RespondRequestId = match rmp_serde::from_slice(&message.payload) {
                Ok(id_only) => id_only,
                Err(error) => {
                    return Some(fail_respond(task, ERR_FACTORY.by_err("parse respond requestId", error)));
                }
            };

            let Some(writer) = registries().writer(&id_only.request_id) else {
                return Some(fail_respond(
                    task,
                    ERR_FACTORY.by_text(&format!("unknown requestId {}", id_only.request_id)),
                ));
            };

            // Answer the client with a 500 instead of hanging.
            let (done, _) = tokio::sync::oneshot::channel();

            let _ = writer
                .send(WriteCommand {
                    kind: WriteKind::Full,
                    status: 500,
                    headers: HashMap::new(),
                    body: b"Internal Server Error".to_vec(),
                    done,
                })
                .await;

            return Some(fail_respond(task, ERR_FACTORY.by_err("parse respond payload", parse_error)));
        }
    };

    if payload.request_id.is_empty() {
        return Some(fail_respond(task, ERR_FACTORY.by_text("empty respond requestId")));
    }

    let Some(writer) = registries().writer(&payload.request_id) else {
        // The connection is already gone (answered or disconnected).
        return Some(fail_respond(
            task,
            ERR_FACTORY.by_text(&format!("unknown requestId {}", payload.request_id)),
        ));
    };

    let Some(kind) = WriteKind::from_code(payload.op) else {
        return Some(fail_respond(
            task,
            ERR_FACTORY.by_text(&format!("unknown respond op {}", payload.op)),
        ));
    };

    // The end of a response releases its registration; a head or a chunk leaves
    // it in place for what follows.
    if matches!(kind, WriteKind::Full | WriteKind::End) {
        registries().forget(&payload.request_id);
    }

    let (done, outcome) = tokio::sync::oneshot::channel();

    let command = WriteCommand {
        kind,
        status: payload.status.clamp(100, 599) as u16,
        body: payload.take_body_bytes(),
        headers: payload.headers,
        done,
    };

    if writer.send(command).await.is_err() {
        return Some(fail_respond(
            task,
            ERR_FACTORY.by_text("write response: request abandoned"),
        ));
    }

    // A fire-and-forget write (the final write of a full response): the PHP
    // coroutine does not await this task, so no result is published — success
    // or failure.
    if payload.no_result {
        return None;
    }

    match outcome.await {
        Ok(Ok(())) => Some(Result::success(
            message,
            Vec::new(),
            calc_execution_ms(start_time),
        )),
        Ok(Err(error)) => Some(fail_respond(task, ERR_FACTORY.by_text(&format!("write response: {error}")))),
        Err(_) => Some(fail_respond(
            task,
            ERR_FACTORY.by_text("write response: request abandoned"),
        )),
    }
}

/// The detached path, on the PHP thread: the write is handed over without
/// waiting for it to be applied.
///
/// A one-slot channel, not a rendezvous: it gives the same guarantee that the
/// connection task has the command before push returns — it is in the channel
/// the receiver is already waiting on — without blocking the thread that drains
/// the results.
fn respond_detached(task: &Task) -> Option<Result> {
    let message = task.message();

    // One decode on the happy path, the id-only fallback only when it fails —
    // the same order as the awaited respond above. Nothing is written back to a
    // malformed detached response: this path has no oneshot to wait on and the
    // coroutine that sent it awaits no result either.
    let mut payload: payloads::RespondPayload = match rmp_serde::from_slice(&message.payload) {
        Ok(payload) => payload,
        Err(parse_error) => {
            if rmp_serde::from_slice::<payloads::RespondRequestId>(&message.payload).is_err() {
                return Some(fail_respond(
                    task,
                    ERR_FACTORY.by_err("parse respond requestId", parse_error),
                ));
            }

            return Some(fail_respond(task, ERR_FACTORY.by_err("parse respond payload", parse_error)));
        }
    };

    let Some(writer) = registries().writer(&payload.request_id) else {
        return Some(fail_respond(
            task,
            ERR_FACTORY.by_text(&format!("unknown requestId {}", payload.request_id)),
        ));
    };

    let Some(kind) = WriteKind::from_code(payload.op) else {
        return Some(fail_respond(
            task,
            ERR_FACTORY.by_text(&format!("unknown respond op {}", payload.op)),
        ));
    };

    if matches!(kind, WriteKind::Full | WriteKind::End) {
        registries().forget(&payload.request_id);
    }

    let (done, _) = tokio::sync::oneshot::channel();

    let sent = writer.try_send(WriteCommand {
        kind,
        status: payload.status.clamp(100, 599) as u16,
        body: payload.take_body_bytes(),
        headers: payload.headers,
        done,
    });

    if payload.no_result {
        return None;
    }

    match sent {
        Ok(()) => Some(Result::success(message, Vec::new(), 0)),
        Err(_) => Some(fail_respond(
            task,
            ERR_FACTORY.by_text("write response: request abandoned"),
        )),
    }
}

/// Builds a respond failure result. A detached task carries no flow, so the
/// handler finds none and drops it before it ever crosses to PHP. The result is
/// all there is to carry it, so it is noted rather than silently lost.
fn fail_respond(task: &Task, text: String) -> Result {
    Result::error(task.message(), text)
}

/// Closes the listener of the given server flow without cancelling in-flight
/// requests. On a SO_REUSEPORT pool this lets the kernel route new connections
/// to sibling processes while this one drains. No-op if unknown.
pub fn stop_accepting(flow_key: &str) {
    if let Some(state) = registries().server_states.lock().unwrap().get(flow_key) {
        state.stop_accepting.cancel();
    }
}

/// Mirrors the feature's share of features.Shutdown.
pub fn shutdown() {
    let mut states = registries().server_states.lock().unwrap();

    for state in states.values() {
        state.stop_accepting.cancel();
    }

    states.clear();
}
