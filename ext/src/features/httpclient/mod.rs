//! The HTTP client feature: the command dispatch behind request, upload and
//! download.
//!
//! One request per command envelope: a buffered body is sent when PHP first
//! pulls the response, a streamed one is already in flight so the upload chunks
//! have somewhere to go, and a download never crosses into PHP at all.

pub mod client;
pub mod payloads;
pub mod response_state;

use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};
use tokio::io::AsyncWriteExt;
use tokio::sync::{mpsc, oneshot};

use crate::dto::Result;
use crate::errs::Factory;
use crate::features::{BoxFuture, Feature};
use crate::helpers::calc_execution_ms;
use crate::states as core_states;
use crate::states::StateContract;
use crate::tasks::Task;

use payloads::{DownloadMeta, collect_headers};
use response_state::{Pending, ResponseState};

static ERRORS: Factory = Factory::new("httpClient");

/// Error-class markers prefixed onto an error payload so the PHP side can map it
/// to the right exception. `net` is a transport failure, `req` a malformed or
/// unusable request; the default is a generic client error.
const NETWORK_ERROR_MARKER: &str = "net";
const REQUEST_ERROR_MARKER: &str = "req";

pub fn network_error(text: &str) -> String {
    format!("{NETWORK_ERROR_MARKER}: {}", ERRORS.by_text(text))
}

pub fn request_error(text: &str) -> String {
    format!("{REQUEST_ERROR_MARKER}: {}", ERRORS.by_text(text))
}

/// One open streamed upload: the channel its body is read from, plus why the
/// request died if it did.
///
/// The failure is recorded rather than the session being dropped. A request that
/// never connected still has chunks on the way, and answering those with
/// "unknown requestId" would tell PHP the id was wrong when the truth is that
/// the connection was refused — a request error where it should be a network
/// one.
struct UploadSession {
    chunks: mpsc::Sender<std::result::Result<bytes::Bytes, std::io::Error>>,
    failure: Arc<Mutex<Option<String>>>,
}

pub struct Registries {
    clients: client::Clients,
    uploads: Mutex<HashMap<String, Arc<UploadSession>>>,
}

impl Registries {
    pub fn new() -> Self {
        Registries {
            clients: client::Clients::new(),
            uploads: Mutex::new(HashMap::new()),
        }
    }
}

fn registries() -> &'static Registries {
    crate::core::get().httpclient()
}

pub fn shutdown() {
    registries().clients.close_idle();
}

pub struct HttpClientFeature;

static INSTANCE: HttpClientFeature = HttpClientFeature;

pub fn get() -> &'static HttpClientFeature {
    &INSTANCE
}

impl Feature for HttpClientFeature {
    fn handle(&self, task: Task) -> BoxFuture {
        Box::pin(async move {
            let message = task.message();

            let envelope: payloads::Envelope = match rmp_serde::from_slice(&message.payload) {
                Ok(envelope) => envelope,
                Err(error) => {
                    task.add_result(Result::error(
                        message,
                        request_error(&format!("parse envelope: {error}")),
                    ))
                    .await;

                    return;
                }
            };

            match envelope.command.as_str() {
                "req" => handle_request(&task, envelope.params).await,
                "upc" => handle_upload(&task, envelope.params, false).await,
                "upe" => handle_upload(&task, envelope.params, true).await,
                _ => {
                    task.add_result(Result::error(message, ERRORS.by_text("unknown command")))
                        .await;
                }
            }
        })
    }
}

async fn handle_request(task: &Task, params: rmpv::Value) {
    let message = task.message();

    let mut params: payloads::RequestParams = match rmpv::ext::from_value(params) {
        Ok(params) => params,
        Err(error) => {
            task.add_result(Result::error(
                message,
                request_error(&format!("parse request params: {error}")),
            ))
            .await;

            return;
        }
    };

    // A streamed body cannot be replayed, so a redirect would fail opaquely
    // mid-upload, so following is disabled for a streamed one.
    let follow_redirects = params.follow_redirects && !params.stream_body;

    let http = match registries().clients.get(&params, follow_redirects) {
        Ok(http) => http,
        Err(error) => {
            task.add_result(Result::error(
                message,
                request_error(&format!("build client: {error}")),
            ))
            .await;

            return;
        }
    };

    let Ok(method) = reqwest::Method::from_bytes(params.method.as_bytes()) else {
        task.add_result(Result::error(
            message,
            request_error(&format!("invalid method {}", params.method)),
        ))
        .await;

        return;
    };

    let mut builder = http.request(method, &params.url);

    for (name, values) in &params.headers {
        for value in values {
            builder = builder.header(name, value);
        }
    }

    // The hard limit on the whole operation, as every feature is required to
    // carry. 0 leaves only the flow's own cancellation.
    if params.request_timeout_ms > 0 {
        builder = builder.timeout(Duration::from_millis(params.request_timeout_ms as u64));
    }

    if !params.sink_path.is_empty() {
        download(task, builder, &mut params).await;

        return;
    }

    if params.stream_body {
        start_streamed(task, &params, builder).await;

        return;
    }

    let state = Arc::new(ResponseState::new(
        task.message_arc(),
        Pending::Buffered(builder.body(params.take_body_bytes())),
        params.chunk_size,
        params.max_response_body,
        params.response_header_timeout_ms,
    ));

    match core_states::get()
        .start(task.context().clone(), &message.task_key, state.clone())
        .await
    {
        Ok(result) => task.add_result(result).await,
        Err(error) => {
            state.close().await;

            task.add_result(Result::error(
                message,
                request_error(&format!("start request: {error}")),
            ))
            .await;
        }
    }
}

/// Opens a streamed request: the send goes out now with a body PHP fills, the
/// state is registered *without* reading its first batch, and PHP is acked at
/// once.
///
/// Reading the first batch here would wait for the response, and the response
/// cannot arrive until PHP has pushed the body it is being kept from pushing —
/// a deadlock, which is why the state is registered rather than started.
/// HasNext keeps it alive so the response can be pulled afterwards.
async fn start_streamed(
    task: &Task,
    params: &payloads::RequestParams,
    builder: reqwest::RequestBuilder,
) {
    let message = task.message();
    let start_time = Instant::now();

    let pending = match open_streamed(params, builder) {
        Ok(pending) => pending,
        Err(error) => {
            task.add_result(Result::error(message, request_error(&error)))
                .await;

            return;
        }
    };

    let state = Arc::new(ResponseState::new(
        task.message_arc(),
        pending,
        params.chunk_size,
        params.max_response_body,
        params.response_header_timeout_ms,
    ));

    if let Err(error) = core_states::get().register(message.task_key.clone(), state) {
        registries().uploads.lock().unwrap().remove(&params.request_id);

        task.add_result(Result::error(
            message,
            request_error(&format!("register request: {error}")),
        ))
        .await;

        return;
    }

    // On flow stop: drop the state and forget the session, so an upload still
    // waiting to be written unblocks instead of hanging on a dead request.
    let flow_ctx = task.context().clone();
    let task_key = message.task_key.clone();
    let request_id = params.request_id.clone();

    tokio::spawn(async move {
        flow_ctx.cancelled().await;

        registries().uploads.lock().unwrap().remove(&request_id);
        core_states::get().delete_state(&task_key).await;
    });

    task.add_result(Result::success_with_next(
        message,
        Vec::new(),
        calc_execution_ms(start_time),
    ))
    .await;
}

/// Starts a streamed upload: the request goes out now, with a body fed by the
/// upload commands PHP sends next, and its response is handed to the state when
/// it arrives.
fn open_streamed(
    params: &payloads::RequestParams,
    builder: reqwest::RequestBuilder,
) -> std::result::Result<Pending, String> {
    if params.request_id.is_empty() {
        return Err("a streamed body needs a request id".to_string());
    }

    let (chunks, receiver) = mpsc::channel(4);

    // The failure slot is shared, the session is not: the sending task must hold
    // no clone of the session, or it would hold the body sender with it and the
    // stream would never end — PHP's end marker drops the last sender, and that
    // is what completes the request.
    let failure = Arc::new(Mutex::new(None));

    registries().uploads.lock().unwrap().insert(
        params.request_id.clone(),
        Arc::new(UploadSession {
            chunks,
            failure: failure.clone(),
        }),
    );

    // An unknown length means a chunked request encoding, which is what a body
    // arriving in pieces has to use.
    let body = reqwest::Body::wrap_stream(futures_util::stream::unfold(
        receiver,
        |mut receiver| async move { receiver.recv().await.map(|chunk| (chunk, receiver)) },
    ));

    let (done, response) = oneshot::channel();

    tokio::spawn(async move {
        let outcome = builder.body(body).send().await;

        // The session stays registered either way: on the happy path PHP's end
        // marker takes it, and on a failure the chunks still coming need to find
        // the reason rather than an empty registry.
        if let Err(error) = &outcome {
            *failure.lock().unwrap() = Some(error.to_string());
        }

        let _ = done.send(outcome);
    });

    Ok(Pending::Streamed(response))
}

async fn handle_upload(task: &Task, params: rmpv::Value, end: bool) {
    let message = task.message();
    let start_time = Instant::now();

    let mut params: payloads::UploadParams = match rmpv::ext::from_value(params) {
        Ok(params) => params,
        Err(error) => {
            task.add_result(Result::error(
                message,
                request_error(&format!("parse upload params: {error}")),
            ))
            .await;

            return;
        }
    };

    let session = if end {
        // Taken, not read: closing the sender is what ends the body, and the
        // sender lives in the session.
        registries()
            .uploads
            .lock()
            .unwrap()
            .remove(&params.request_id)
    } else {
        registries()
            .uploads
            .lock()
            .unwrap()
            .get(&params.request_id)
            .cloned()
    };

    let Some(session) = session else {
        task.add_result(Result::error(
            message,
            request_error(&format!("unknown requestId {}", params.request_id)),
        ))
        .await;

        return;
    };

    if !end {
        let chunk = bytes::Bytes::from(params.take_body_bytes());

        if session.chunks.send(Ok(chunk)).await.is_err() {
            // The request is gone. Report why, so PHP raises a network error
            // rather than being told its own id was wrong.
            let reason = session
                .failure
                .lock()
                .unwrap()
                .clone()
                .unwrap_or_else(|| "upload was closed by the remote side".to_string());

            registries()
                .uploads
                .lock()
                .unwrap()
                .remove(&params.request_id);

            task.add_result(Result::error(message, network_error(&reason)))
                .await;

            return;
        }
    }

    task.add_result(Result::success(
        message,
        Vec::new(),
        calc_execution_ms(start_time),
    ))
    .await;
}

/// Copies the response body straight into a file; it never crosses into PHP.
/// Only a 2xx is written — a non-2xx returns its status without touching the
/// file, and PHP turns that into a DownloadException.
async fn download(task: &Task, builder: reqwest::RequestBuilder, params: &mut payloads::RequestParams) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(mut options) = sink_options(&params.sink_mode) else {
        task.add_result(Result::error(message, request_error("invalid sink mode")))
            .await;

        return;
    };

    let response = match builder.body(params.take_body_bytes()).send().await {
        Ok(response) => response,
        Err(error) => {
            task.add_result(Result::error(message, network_error(&error.to_string())))
                .await;

            return;
        }
    };

    let status = response.status();
    let headers = collect_headers(response.headers());

    if !status.is_success() {
        task.add_result(Result::success(
            message,
            DownloadMeta {
                status: status.as_u16(),
                headers,
                written: 0,
            }
            .encode(),
            calc_execution_ms(start_time),
        ))
        .await;

        return;
    }

    // The PHP side's create permission, or the usual default.
    // tokio's OpenOptions carries mode() itself, so the unix extension trait is
    // not imported for it.
    options.mode(if params.sink_perm > 0 {
        params.sink_perm as u32
    } else {
        0o644
    });

    let mut file = match options.open(&params.sink_path).await {
        Ok(file) => file,
        Err(error) => {
            task.add_result(Result::error(
                message,
                ERRORS.by_err("open sink", error),
            ))
            .await;

            return;
        }
    };

    let mut stream = response.bytes_stream();
    let mut written: i64 = 0;

    use futures_util::StreamExt;

    while let Some(chunk) = stream.next().await {
        let chunk = match chunk {
            Ok(chunk) => chunk,
            Err(error) => {
                drop_partial(&params.sink_path, &params.sink_mode).await;

                task.add_result(Result::error(message, network_error(&error.to_string())))
                    .await;

                return;
            }
        };

        if let Err(error) = file.write_all(&chunk).await {
            drop_partial(&params.sink_path, &params.sink_mode).await;

            task.add_result(Result::error(message, ERRORS.by_err("write sink", error)))
                .await;

            return;
        }

        written += chunk.len() as i64;
    }

    if let Err(error) = file.flush().await {
        task.add_result(Result::error(message, ERRORS.by_err("close sink", error)))
            .await;

        return;
    }

    task.add_result(Result::success(
        message,
        DownloadMeta {
            status: status.as_u16(),
            headers,
            // The bytes actually written, not a Content-Length header.
            written,
        }
        .encode(),
        calc_execution_ms(start_time),
    ))
    .await;
}

/// Removes a half-written download. Append is left alone: the file already held
/// something before the request, and truncating it would destroy data the caller
/// never asked to replace.
async fn drop_partial(path: &str, mode: &str) {
    if mode == "app" {
        return;
    }

    let _ = tokio::fs::remove_file(path).await;
}

/// Maps a PHP DownloadFileMode to open options — the single place those flags
/// live.
fn sink_options(mode: &str) -> Option<tokio::fs::OpenOptions> {
    let mut options = tokio::fs::OpenOptions::new();

    options.write(true);

    match mode {
        "rpl" => {
            options.create(true).truncate(true);
        }
        "crt" => {
            options.create_new(true);
        }
        "app" => {
            options.create(true).append(true);
        }
        _ => return None,
    }

    Some(options)
}
