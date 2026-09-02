//! Mirrors ext-go-legacy/internal/features/amqp/{feature,connect,topology,publish,get,acks,confirms}.go:
//! one handler per AMQP method the PHP feature exposes.
//!
//! Every command on an open channel is the same five steps — decode the
//! parameters, resolve the channel, bound the call, run it serialized against
//! the channel's other commands, answer the task — and differs only in the
//! driver call and in what it reports. `on_channel` holds the steps, so a new
//! command cannot get the deadline, the failure scope or the execution time
//! subtly wrong by being copied from its neighbour.

use std::sync::Arc;
use std::time::Instant;

use lapin::ExchangeKind;
use lapin::options::*;
use lapin::protocol::basic::AMQPProperties;
use lapin::types::{FieldTable, ShortString};

use crate::dto::Result;
use crate::tasks::Task;

use super::channels::{ChannelEntry, CommandError};
use super::connections::{DEFAULT_RPC_TIMEOUT, DEFAULT_WRITE_TIMEOUT, ms_or_default};
use super::consumerstats;
use super::payloads::{self, ChannelCommand};
use super::{
    ERRORS, SCOPE_COMMAND, channels, connections, error_payload, fail, network_error_payload,
    respond, respond_done,
};

pub async fn dispatch(task: &Task, envelope: &payloads::Envelope) {
    match envelope.command.as_str() {
        "con" => handle_connect(task, &envelope.params).await,
        "dis" => handle_disconnect(task, &envelope.params).await,
        "usc" => handle_used_channels(task, &envelope.params).await,
        "cho" => handle_channel_open(task, &envelope.params).await,
        "chc" => handle_channel_close(task, &envelope.params).await,
        "qos" => handle_qos(task, &envelope.params).await,
        "exd" => handle_exchange_declare(task, &envelope.params).await,
        "exx" => handle_exchange_delete(task, &envelope.params).await,
        "exb" => handle_exchange_binding(task, &envelope.params, true).await,
        "exu" => handle_exchange_binding(task, &envelope.params, false).await,
        "qud" => handle_queue_declare(task, &envelope.params).await,
        "qux" => handle_queue_delete(task, &envelope.params).await,
        "qub" => handle_queue_binding(task, &envelope.params, true).await,
        "quu" => handle_queue_binding(task, &envelope.params, false).await,
        "qup" => handle_queue_purge(task, &envelope.params).await,
        "pub" => handle_publish(task, &envelope.params).await,
        "get" => handle_get(task, &envelope.params).await,
        "csm" => super::consume_state::handle_consume(task, &envelope.params).await,
        "csv" => super::consume_serve::handle_consume_serve(task, &envelope.params).await,
        "cnl" => handle_cancel(task, &envelope.params).await,
        "ack" => handle_ack(task, &envelope.params).await,
        "nck" => handle_nack(task, &envelope.params).await,
        "rej" => handle_reject(task, &envelope.params).await,
        "cfs" => handle_confirm_select(task, &envelope.params).await,
        "cfw" => handle_confirm_wait(task, &envelope.params).await,
        _ => {
            task.add_result(Result::error(
                task.message(),
                ERRORS.by_text("unknown command"),
            ))
            .await;
        }
    }
}

/// Releases a resource whose owner is gone. Nothing is answered: the caller is
/// a destructor that cannot wait, and by the time this runs its coroutine may
/// not exist.
pub async fn dispatch_detached(envelope: &payloads::Envelope) {
    match envelope.command.as_str() {
        "chc" => {
            if let Ok(params) = decode::<payloads::ChannelParams>(&envelope.params) {
                channels().close(&params.channel_id).await;
            }
        }
        "dis" => {
            if let Ok(params) = decode::<payloads::ConnectionParams>(&envelope.params) {
                connections().release(&params.connection_id).await;
            }
        }
        "cnl" => {
            // A consume loop that was unwound cancels its consumer on the way
            // out. The coroutine's flow is already gone, so there is nothing to
            // answer on — the method goes to the broker and the stream is
            // dropped, with nobody waiting.
            if let Ok(params) = decode::<payloads::CancelParams>(&envelope.params) {
                cancel_detached(&params).await;
            }
        }
        other => {
            crate::logger::write(format!(
                "amqp: command {other} cannot be pushed detached\n"
            ));
        }
    }
}

async fn cancel_detached(params: &payloads::CancelParams) {
    let Some(entry) = channels().find(&params.channel_id) else {
        return;
    };

    // Claimed before the method goes out, the way `cancel_consumer` claims it:
    // the registry entry is what says the tag has not been cancelled yet, and
    // sending first leaves a window in which the consumer's own teardown puts a
    // second basic.cancel on the wire for a tag the broker no longer has.
    let Some(task_key) = entry.forget_consumer(&params.consumer_tag) else {
        return;
    };

    if !task_key.is_empty() {
        crate::states::get().delete_state(&task_key).await;
    }

    entry
        .send_cancel(&params.consumer_tag, params.no_wait)
        .await;
}

// --- connection and channel lifecycle -----------------------------------------

async fn handle_connect(task: &Task, raw: &rmpv::Value) {
    let start_time = Instant::now();

    let Some(params) = decoded::<payloads::ConnectParams>(task, raw, "connect params").await else {
        return;
    };

    match connections().open(&params).await {
        Ok(handle) => {
            respond(
                task,
                payloads::encode_connect_result(
                    &handle.id,
                    handle.pooled.max_channels,
                    handle.pooled.max_frame_size,
                    handle.pooled.heartbeat,
                ),
                start_time,
            )
            .await
        }
        // Connection refused, DNS failure, a rejected login, a dial timeout:
        // all of them mean the application cannot reach this broker.
        Err(error) => {
            task.add_result(Result::error(
                task.message(),
                network_error_payload(&format!("connect: {error}")),
            ))
            .await
        }
    }
}

async fn handle_disconnect(task: &Task, raw: &rmpv::Value) {
    let start_time = Instant::now();

    let Some(params) = decoded::<payloads::ConnectionParams>(task, raw, "disconnect params").await
    else {
        return;
    };

    connections().release(&params.connection_id).await;

    respond_done(task, start_time).await;
}

async fn handle_used_channels(task: &Task, raw: &rmpv::Value) {
    let start_time = Instant::now();

    let Some(params) =
        decoded::<payloads::ConnectionParams>(task, raw, "used channels params").await
    else {
        return;
    };

    let used = match connections().find(&params.connection_id) {
        Some(handle) => channels().used_channels(&handle),
        None => 0,
    };

    respond(task, payloads::encode_used_channels_result(used), start_time).await;
}

async fn handle_channel_open(task: &Task, raw: &rmpv::Value) {
    let start_time = Instant::now();

    let Some(params) =
        decoded::<payloads::ChannelOpenParams>(task, raw, "channel open params").await
    else {
        return;
    };

    let Some(handle) = connections().find(&params.connection_id) else {
        // Scoped as the connection being gone — which is what it is: the handle
        // was released, or the connection behind it died and took its handle
        // with it. An unscoped error would reach PHP as a plain command
        // failure, leaving the Connection object reporting itself open and
        // every later call failing the same way instead of saying to reconnect.
        task.add_result(Result::error(
            task.message(),
            network_error_payload("No connection available."),
        ))
        .await;

        return;
    };

    if params.prefetch_size_bytes > 0 {
        task.add_result(Result::error(
            task.message(),
            error_payload(super::SCOPE_COMMAND, 0, super::PREFETCH_SIZE_TEXT),
        ))
        .await;

        return;
    }

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_RPC_TIMEOUT);

    match channels().open(&handle, &params, timeout).await {
        Ok(entry) => {
            respond(
                task,
                payloads::encode_channel_open_result(&entry.id, entry.number),
                start_time,
            )
            .await
        }
        Err(error) => fail(task, None, "channel open", error).await,
    }
}

async fn handle_channel_close(task: &Task, raw: &rmpv::Value) {
    let start_time = Instant::now();

    let Some(params) = decoded::<payloads::ChannelParams>(task, raw, "channel close params").await
    else {
        return;
    };

    channels().close(&params.channel_id).await;

    respond_done(task, start_time).await;
}

async fn handle_qos(task: &Task, raw: &rmpv::Value) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::QosParams>(task, raw, "qos").await
    else {
        return;
    };

    if params.prefetch_size_bytes > 0 {
        fail(
            task,
            Some(&entry),
            "qos",
            CommandError::Message(super::PREFETCH_SIZE_TEXT.to_string()),
        )
        .await;

        return;
    }

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_RPC_TIMEOUT);

    let outcome = entry
        .run(
            timeout,
            entry.channel().basic_qos(
                clamp_u16(params.prefetch_count),
                BasicQosOptions {
                    global: params.global,
                },
            ),
        )
        .await;

    finish(task, &entry, "qos", start_time, outcome.map(|_| None)).await;
}

// --- topology -----------------------------------------------------------------

async fn handle_exchange_declare(task: &Task, raw: &rmpv::Value) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::ExchangeDeclareParams>(task, raw, "exchange declare").await
    else {
        return;
    };

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_RPC_TIMEOUT);

    let outcome = entry
        .run(
            timeout,
            entry.channel().exchange_declare(
                short(&params.name),
                exchange_kind(&params.kind),
                ExchangeDeclareOptions {
                    passive: params.passive,
                    durable: params.durable,
                    auto_delete: params.auto_delete,
                    internal: params.internal,
                    nowait: params.no_wait,
                },
                arguments(&params.arguments),
            ),
        )
        .await;

    finish(task, &entry, "exchange declare", start_time, outcome.map(|_| None)).await;
}

async fn handle_exchange_delete(task: &Task, raw: &rmpv::Value) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::ExchangeDeleteParams>(task, raw, "exchange delete").await
    else {
        return;
    };

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_RPC_TIMEOUT);

    let outcome = entry
        .run(
            timeout,
            entry.channel().exchange_delete(
                short(&params.name),
                ExchangeDeleteOptions {
                    if_unused: params.if_unused,
                    nowait: params.no_wait,
                },
            ),
        )
        .await;

    finish(task, &entry, "exchange delete", start_time, outcome.map(|_| None)).await;
}

async fn handle_exchange_binding(task: &Task, raw: &rmpv::Value, bind: bool) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::ExchangeBindParams>(task, raw, "exchange binding").await
    else {
        return;
    };

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_RPC_TIMEOUT);

    let outcome = if bind {
        entry
            .run(
                timeout,
                entry.channel().exchange_bind(
                    short(&params.destination),
                    short(&params.source),
                    short(&params.routing_key),
                    ExchangeBindOptions {
                        nowait: params.no_wait,
                    },
                    arguments(&params.arguments),
                ),
            )
            .await
    } else {
        entry
            .run(
                timeout,
                entry.channel().exchange_unbind(
                    short(&params.destination),
                    short(&params.source),
                    short(&params.routing_key),
                    ExchangeUnbindOptions {
                        nowait: params.no_wait,
                    },
                    arguments(&params.arguments),
                ),
            )
            .await
    };

    finish(task, &entry, "exchange binding", start_time, outcome.map(|_| None)).await;
}

async fn handle_queue_declare(task: &Task, raw: &rmpv::Value) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::QueueDeclareParams>(task, raw, "queue declare").await
    else {
        return;
    };

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_RPC_TIMEOUT);

    let outcome = entry
        .run(
            timeout,
            entry.channel().queue_declare(
                short(&params.name),
                QueueDeclareOptions {
                    passive: params.passive,
                    durable: params.durable,
                    exclusive: params.exclusive,
                    auto_delete: params.auto_delete,
                    nowait: params.no_wait,
                },
                arguments(&params.arguments),
            ),
        )
        .await
        .map(|queue| {
            Some(payloads::encode_queue_declare_result(
                queue.name().as_str(),
                queue.message_count(),
                queue.consumer_count(),
            ))
        });

    finish(task, &entry, "queue declare", start_time, outcome).await;
}

async fn handle_queue_delete(task: &Task, raw: &rmpv::Value) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::QueueDeleteParams>(task, raw, "queue delete").await
    else {
        return;
    };

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_RPC_TIMEOUT);

    let outcome = entry
        .run(
            timeout,
            entry.channel().queue_delete(
                short(&params.name),
                QueueDeleteOptions {
                    if_unused: params.if_unused,
                    if_empty: params.if_empty,
                    nowait: params.no_wait,
                },
            ),
        )
        .await
        .map(|deleted| Some(payloads::encode_message_count_result(deleted)));

    finish(task, &entry, "queue delete", start_time, outcome).await;
}

async fn handle_queue_binding(task: &Task, raw: &rmpv::Value, bind: bool) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::QueueBindParams>(task, raw, "queue binding").await
    else {
        return;
    };

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_RPC_TIMEOUT);

    let outcome = if bind {
        entry
            .run(
                timeout,
                entry.channel().queue_bind(
                    short(&params.queue_name),
                    short(&params.exchange_name),
                    short(&params.routing_key),
                    QueueBindOptions {
                        nowait: params.no_wait,
                    },
                    arguments(&params.arguments),
                ),
            )
            .await
    } else {
        // queue.unbind has no no-wait form in AMQP 0-9-1, so the driver takes
        // none.
        entry
            .run(
                timeout,
                entry.channel().queue_unbind(
                    short(&params.queue_name),
                    short(&params.exchange_name),
                    short(&params.routing_key),
                    arguments(&params.arguments),
                ),
            )
            .await
    };

    finish(task, &entry, "queue binding", start_time, outcome.map(|_| None)).await;
}

async fn handle_queue_purge(task: &Task, raw: &rmpv::Value) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::QueuePurgeParams>(task, raw, "queue purge").await
    else {
        return;
    };

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_RPC_TIMEOUT);

    let outcome = entry
        .run(
            timeout,
            entry.channel().queue_purge(
                short(&params.name),
                QueuePurgeOptions {
                    nowait: params.no_wait,
                },
            ),
        )
        .await
        .map(|purged| Some(payloads::encode_message_count_result(purged)));

    finish(task, &entry, "queue purge", start_time, outcome).await;
}

// --- publishing ---------------------------------------------------------------

/// Publishes one message. `basic.publish` carries no reply, so a message the
/// broker cannot route is only reported when it was published as mandatory and
/// the application waits for the returns.
async fn handle_publish(task: &Task, raw: &rmpv::Value) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::PublishParams>(task, raw, "publish").await
    else {
        return;
    };

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_WRITE_TIMEOUT);

    let body = payloads::bytes_of(&params.body).to_vec();
    let properties = properties_from(&params.properties);

    let outcome = entry
        .publish(
            timeout,
            short(&params.exchange_name),
            short(&params.routing_key),
            BasicPublishOptions {
                mandatory: params.mandatory,
                immediate: params.immediate,
            },
            &body,
            properties,
        )
        .await;

    match outcome {
        Ok((delivery_tag, confirm)) => {
            if let Some(delivery_tag) = delivery_tag {
                collect_confirmation(entry.clone(), delivery_tag, confirm);
            }

            respond_done(task, start_time).await;
        }
        Err(error) => fail(task, Some(&entry), "publish", error).await,
    }
}

/// Awaits one publisher confirm and records it on the channel.
///
/// Go reads a single `NotifyPublish` listener per channel and matches the
/// broker's tags to its own count. lapin resolves a confirm per publish
/// instead, which is stronger — the returned message that belongs to it arrives
/// with it, so a return can never be recorded after the confirmation of the
/// same message and be missed by a wait that already saw the ack.
///
/// It is also where the reason a channel closed comes from: the broker answers
/// a publish to a missing exchange by closing the channel, and lapin rejects
/// the pending confirms with that very error.
fn collect_confirmation(
    entry: Arc<ChannelEntry>,
    delivery_tag: u64,
    confirm: lapin::PublisherConfirm,
) {
    crate::core::get().runtime().spawn(async move {
        match confirm.await {
            Ok(confirmation) => {
                let acked = confirmation.is_ack();

                if let Some(returned) = confirmation.take_message() {
                    entry.record_return(super::channels::StoredReturn {
                        reply_code: returned.reply_code as i64,
                        reply_text: returned.reply_text.as_str().to_string(),
                        exchange: returned.delivery.exchange.as_str().to_string(),
                        routing_key: returned.delivery.routing_key.as_str().to_string(),
                        body: returned.delivery.data.clone(),
                        properties: returned.delivery.properties.clone(),
                    });
                }

                entry.record_confirmation(delivery_tag, acked);
            }
            Err(error) => {
                // The channel died under the message — a publish to an exchange
                // that is not there is answered exactly this way, because
                // basic.publish carries no reply of its own.
                //
                // No confirmation is recorded: the broker sent none, and one
                // invented here would reach PHP as a nack and raise
                // PublishNackedException for a message the broker never judged.
                // What ends the wait is the channel going, which is also what
                // carries the reason the broker gave — so the reason is recorded
                // first and the channel dropped after.
                entry.note_protocol_failure(&error);

                super::channels().close(&entry.id).await;
            }
        }
    });
}

async fn handle_confirm_select(task: &Task, raw: &rmpv::Value) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::ConfirmSelectParams>(task, raw, "confirm select").await
    else {
        return;
    };

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_RPC_TIMEOUT);

    match entry.start_confirm_mode(timeout, params.no_wait).await {
        Ok(()) => respond_done(task, start_time).await,
        Err(error) => fail(task, Some(&entry), "confirm select", error).await,
    }
}

/// Waits until every message published since the last wait has been confirmed
/// or rejected, and hands back what arrived, the returned messages included.
async fn handle_confirm_wait(task: &Task, raw: &rmpv::Value) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::ChannelParams>(task, raw, "confirm wait").await
    else {
        return;
    };

    // A zero timeout means "wait until the broker answers", so the wait rides
    // the flow alone — a stopped coroutine still ends it.
    let timeout = std::time::Duration::from_millis(params.timeout_ms.max(0) as u64);

    match entry.wait_for_confirms(task.context(), timeout).await {
        Ok((confirmations, returns)) => {
            let rendered: Vec<payloads::ReturnOut<'_>> = returns
                .iter()
                .map(|returned| payloads::ReturnOut {
                    reply_code: returned.reply_code,
                    reply_text: &returned.reply_text,
                    exchange_name: &returned.exchange,
                    routing_key: &returned.routing_key,
                    body: &returned.body,
                    properties: &returned.properties,
                })
                .collect();

            respond(
                task,
                payloads::encode_wait_result(&confirmations, &rendered),
                start_time,
            )
            .await;
        }
        Err(error) => fail(task, Some(&entry), "confirm wait", error).await,
    }
}

// --- one message --------------------------------------------------------------

/// Pulls one message from a queue. An empty queue answers with nil, which PHP
/// hands back as null — it never waits for a message to arrive.
async fn handle_get(task: &Task, raw: &rmpv::Value) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::GetParams>(task, raw, "get").await
    else {
        return;
    };

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_RPC_TIMEOUT);

    let outcome = entry
        .run(
            timeout,
            entry.channel().basic_get(
                short(&params.queue_name),
                BasicGetOptions {
                    no_ack: params.auto_ack,
                },
            ),
        )
        .await;

    match outcome {
        // An empty payload, not a msgpack nil: several commands answer with
        // nothing at all, and the PHP side reads an empty payload as an empty
        // answer rather than decoding it.
        Ok(None) => respond_done(task, start_time).await,
        Ok(Some(message)) => {
            let delivery = &message.delivery;

            respond(
                task,
                payloads::encode_delivery(&payloads::DeliveryOut {
                    channel_id: &entry.id,
                    consumer_tag: "",
                    delivery_tag: delivery.delivery_tag,
                    redelivered: delivery.redelivered,
                    exchange_name: delivery.exchange.as_str(),
                    routing_key: delivery.routing_key.as_str(),
                    body: &delivery.data,
                    properties: &delivery.properties,
                }),
                start_time,
            )
            .await;
        }
        Err(error) => fail(task, Some(&entry), "get", error).await,
    }
}

// --- settling -----------------------------------------------------------------

async fn handle_ack(task: &Task, raw: &rmpv::Value) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::AckParams>(task, raw, "ack").await
    else {
        return;
    };

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_RPC_TIMEOUT);

    let outcome = entry
        .run(
            timeout,
            entry.channel().basic_ack(
                params.delivery_tag,
                BasicAckOptions {
                    multiple: params.multiple,
                },
            ),
        )
        .await;

    // Recorded only once the driver took it, so a settle that never reached the
    // broker is never counted.
    if outcome.is_ok() {
        consumerstats::stats().delivery_settled(
            &params.channel_id,
            params.delivery_tag,
            params.multiple,
            true,
        );
    }

    finish(task, &entry, "ack", start_time, outcome.map(|_| None)).await;
}

async fn handle_nack(task: &Task, raw: &rmpv::Value) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::NackParams>(task, raw, "nack").await
    else {
        return;
    };

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_RPC_TIMEOUT);

    let outcome = entry
        .run(
            timeout,
            entry.channel().basic_nack(
                params.delivery_tag,
                BasicNackOptions {
                    multiple: params.multiple,
                    requeue: params.requeue,
                },
            ),
        )
        .await;

    if outcome.is_ok() {
        consumerstats::stats().delivery_settled(
            &params.channel_id,
            params.delivery_tag,
            params.multiple,
            false,
        );
    }

    finish(task, &entry, "nack", start_time, outcome.map(|_| None)).await;
}

async fn handle_reject(task: &Task, raw: &rmpv::Value) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::RejectParams>(task, raw, "reject").await
    else {
        return;
    };

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_RPC_TIMEOUT);

    let outcome = entry
        .run(
            timeout,
            entry.channel().basic_reject(
                params.delivery_tag,
                BasicRejectOptions {
                    requeue: params.requeue,
                },
            ),
        )
        .await;

    if outcome.is_ok() {
        consumerstats::stats().delivery_settled(
            &params.channel_id,
            params.delivery_tag,
            false,
            false,
        );
    }

    finish(task, &entry, "reject", start_time, outcome.map(|_| None)).await;
}

/// Cancels a consumer. The channel stays open: it outlives its consumers.
async fn handle_cancel(task: &Task, raw: &rmpv::Value) {
    let Some((entry, params, start_time)) =
        resolve::<payloads::CancelParams>(task, raw, "cancel").await
    else {
        return;
    };

    // The stream the consumer was read through goes with it. Claimed before the
    // basic.cancel rather than after it: PHP swallows a failed cancel, so a tag
    // left behind by an error return would keep its stream and its state for
    // good — and keep the channel looking busy, which is the one thing the idle
    // sweeper goes by. The broker ignores a cancel for a tag it does not know,
    // so the reverse mistake costs nothing.
    if let Some(task_key) = entry.forget_consumer(&params.consumer_tag) {
        if !task_key.is_empty() {
            crate::states::get().delete_state(&task_key).await;
        }
    }

    let timeout = ms_or_default(params.timeout_ms, DEFAULT_RPC_TIMEOUT);

    let outcome = entry
        .run(
            timeout,
            entry.channel().basic_cancel(
                short(&params.consumer_tag),
                BasicCancelOptions {
                    nowait: params.no_wait,
                },
            ),
        )
        .await;

    finish(task, &entry, "cancel", start_time, outcome.map(|_| None)).await;
}

// --- shared steps -------------------------------------------------------------

/// Answers a command that either reports a value or reports nothing but its
/// success.
async fn finish(
    task: &Task,
    entry: &Arc<ChannelEntry>,
    what: &str,
    start_time: Instant,
    outcome: std::result::Result<Option<Vec<u8>>, CommandError>,
) {
    match outcome {
        Ok(Some(payload)) => respond(task, payload, start_time).await,
        Ok(None) => respond_done(task, start_time).await,
        Err(error) => fail(task, Some(entry), what, error).await,
    }
}

/// Decodes a channel command's parameters and finds the channel it names,
/// answering the task itself on either failure.
pub async fn resolve<P>(
    task: &Task,
    raw: &rmpv::Value,
    what: &str,
) -> Option<(Arc<ChannelEntry>, P, Instant)>
where
    P: serde::de::DeserializeOwned + ChannelCommand,
{
    let start_time = Instant::now();

    let params = decoded::<P>(task, raw, &format!("{what} params")).await?;

    let entry = channel_of(task, params.channel_id()).await?;

    Some((entry, params, start_time))
}

/// Resolves a channel id, answering the task with an error when nothing answers
/// to it (it was closed, its connection died, or the sweeper collected it).
pub async fn channel_of(task: &Task, channel_id: &str) -> Option<Arc<ChannelEntry>> {
    match channels().find(channel_id) {
        Some(entry) => Some(entry),
        None => {
            task.add_result(Result::error(
                task.message(),
                error_payload(super::SCOPE_CHANNEL, 0, "No channel available."),
            ))
            .await;

            None
        }
    }
}

fn decode<P: serde::de::DeserializeOwned>(raw: &rmpv::Value) -> std::result::Result<P, String> {
    // The envelope is decoded once into a value, so the parameters are decoded
    // out of that value rather than from a second slice of the payload.
    let mut buffer = Vec::new();

    rmpv::encode::write_value(&mut buffer, raw).map_err(|error| error.to_string())?;

    rmp_serde::from_slice(&buffer).map_err(|error| error.to_string())
}

async fn decoded<P: serde::de::DeserializeOwned>(
    task: &Task,
    raw: &rmpv::Value,
    what: &str,
) -> Option<P> {
    match decode::<P>(raw) {
        Ok(params) => Some(params),
        Err(error) => {
            task.add_result(Result::error(
                task.message(),
                ERRORS.by_err(&format!("parse {what}"), error),
            ))
            .await;

            None
        }
    }
}

pub fn short(text: &str) -> ShortString {
    ShortString::from(text.to_string())
}

pub fn arguments(value: &rmpv::Value) -> FieldTable {
    super::values::table_from_msgpack(Some(value)).unwrap_or_default()
}

fn clamp_u16(value: i64) -> u16 {
    value.clamp(0, u16::MAX as i64) as u16
}

fn exchange_kind(kind: &str) -> ExchangeKind {
    match kind {
        "direct" => ExchangeKind::Direct,
        "fanout" => ExchangeKind::Fanout,
        "topic" => ExchangeKind::Topic,
        "headers" => ExchangeKind::Headers,
        other => ExchangeKind::Custom(other.to_string()),
    }
}

/// Builds the properties of a published message. cluster-id is absent on
/// purpose: AMQP 0-9-1 excludes it from publishing, and so does the driver.
pub fn properties_from(properties: &payloads::Properties) -> AMQPProperties {
    let mut built = AMQPProperties::default();

    if !properties.content_type.is_empty() {
        built = built.with_content_type(short(&properties.content_type));
    }

    if !properties.content_encoding.is_empty() {
        built = built.with_content_encoding(short(&properties.content_encoding));
    }

    if let Some(headers) = super::values::table_from_msgpack(Some(&properties.headers)) {
        built = built.with_headers(headers);
    }

    if properties.delivery_mode != 0 {
        built = built.with_delivery_mode(properties.delivery_mode.clamp(0, 255) as u8);
    }

    if properties.priority != 0 {
        built = built.with_priority(properties.priority.clamp(0, 255) as u8);
    }

    if !properties.correlation_id.is_empty() {
        built = built.with_correlation_id(short(&properties.correlation_id));
    }

    if !properties.reply_to.is_empty() {
        built = built.with_reply_to(short(&properties.reply_to));
    }

    if !properties.expiration.is_empty() {
        built = built.with_expiration(short(&properties.expiration));
    }

    if !properties.message_id.is_empty() {
        built = built.with_message_id(short(&properties.message_id));
    }

    if properties.timestamp != 0 {
        built = built.with_timestamp(properties.timestamp.max(0) as u64);
    }

    if !properties.kind.is_empty() {
        built = built.with_type(short(&properties.kind));
    }

    if !properties.user_id.is_empty() {
        built = built.with_user_id(short(&properties.user_id));
    }

    if !properties.app_id.is_empty() {
        built = built.with_app_id(short(&properties.app_id));
    }

    built
}

/// The scope a command failure carries when nothing else fits.
pub const _SCOPE_COMMAND: &str = SCOPE_COMMAND;
