//! One feature handling every Redis command.
//!
//! What a connection is used for decides how it may be shared, and that is the
//! whole shape of this module: ordinary commands ride a multiplexed connection
//! together (which is where the gain is — many coroutines, one socket, one
//! round trip), a blocking command takes a connection of its own for the call,
//! and a subscription owns one outright. See pools.rs.

pub mod commands;
pub mod dsn;
pub mod errors;
pub mod payloads;
pub mod pools;
pub mod scan_state;
pub mod subscribe_state;
pub mod subscriptions;
pub mod values;

use std::sync::Arc;
use std::time::{Duration, Instant};
use tokio_util::sync::CancellationToken;

use redis::aio::ConnectionLike;
use redis::Value;

use crate::dto::Result;
use crate::features::{BoxFuture, Feature};
use crate::helpers::calc_execution_ms;
use crate::states;
use crate::states::StateContract;
use crate::tasks::Task;

use errors::{message as fail, Kind};
use scan_state::ScanState;
use subscribe_state::SubscribeState;
use subscriptions::Subscription;

/// How many elements a batch carries when the caller names no size.
const DEFAULT_BATCH_SIZE: usize = 50;
const MAX_BATCH_SIZE: usize = 10_000;

/// The feature's process-wide registries, owned by the Core so a fork discards
/// them.
pub struct Registries {
    pools: pools::Pools,
    subscriptions: subscriptions::Subscriptions,
}

impl Registries {
    pub fn new() -> Self {
        Registries {
            pools: pools::Pools::new(),
            subscriptions: subscriptions::Subscriptions::new(),
        }
    }

    pub fn pools(&self) -> &pools::Pools {
        &self.pools
    }

    pub fn subscriptions(&self) -> &subscriptions::Subscriptions {
        &self.subscriptions
    }
}

fn registry_subscriptions() -> &'static subscriptions::Subscriptions {
    crate::core::get().redis().subscriptions()
}

pub struct RedisFeature;

static INSTANCE: RedisFeature = RedisFeature;

pub fn get() -> &'static RedisFeature {
    &INSTANCE
}

/// Releases what the feature holds. Called on extension shutdown.
pub fn shutdown() {
    registry_subscriptions().close_all();
    pools::get().close_all();
}

impl Feature for RedisFeature {
    fn handle(&self, task: Task) -> BoxFuture {
        Box::pin(async move {
            let message = task.message();

            let envelope: payloads::Envelope = match rmp_serde::from_slice(&message.payload) {
                Ok(envelope) => envelope,
                Err(error) => {
                    task.add_result(Result::error(
                        message,
                        fail(Kind::Argument, &format!("parse envelope: {error}")),
                    ))
                    .await;

                    return;
                }
            };

            // The sweeper needs a runtime, so it is armed on first use rather
            // than when the registry is built.
            pools::start_sweeper();

            let feature = get();

            match envelope.command.as_str() {
                "cmd" => feature.handle_command(&task, &envelope).await,
                "pip" => feature.handle_pipeline(&task, &envelope).await,
                "scn" => feature.handle_scan(&task, &envelope).await,
                "sub" => feature.handle_subscribe(&task, &envelope).await,
                "sup" => feature.handle_subscription_update(&task, &envelope).await,
                "suc" => feature.handle_subscription_close(&task, &envelope).await,
                _ => {
                    task.add_result(Result::error(message, fail(Kind::Argument, "unknown command")))
                        .await
                }
            }
        })
    }
}

impl RedisFeature {
    /// One command. An ordinary one goes onto a shared multiplexed connection;
    /// a blocking one onto a connection of its own, because on a shared socket
    /// it would stall everything queued behind it.
    async fn handle_command(&'static self, task: &Task, envelope: &payloads::Envelope) {
        let message = task.message();
        let start_time = Instant::now();

        let params: payloads::CommandParams = match rmpv::ext::from_value(envelope.data.clone()) {
            Ok(params) => params,
            Err(error) => {
                task.add_result(Result::error(
                    message,
                    fail(Kind::Argument, &format!("parse command params: {error}")),
                ))
                .await;

                return;
            }
        };

        let name = commands::normalize_name(&params.name);

        if name.is_empty() {
            task.add_result(Result::error(message, fail(Kind::Argument, "empty command name")))
                .await;

            return;
        }

        let args = match values::decode_args(&params.args) {
            Ok(args) => args,
            Err(error) => {
                task.add_result(Result::error(message, fail(Kind::Argument, &error)))
                    .await;

                return;
            }
        };

        if let Some(refusal) = commands::refusal(&name, &args) {
            task.add_result(Result::error(message, fail(Kind::Refused, &refusal)))
                .await;

            return;
        }

        let is_blocking = commands::is_blocking(&name, &args, params.blocking);

        if is_blocking {
            if let Some(error) = deadline_conflict(&name, &args, envelope.timeout_ms) {
                task.add_result(Result::error(message, fail(Kind::Argument, &error)))
                    .await;

                return;
            }
        }

        let command = commands::build(&name, &args);

        let outcome = if is_blocking {
            let dsn = envelope.dsn.clone();

            // The dial is inside the deadline, not before it: a caller that asked
            // for 1500 ms means the whole thing, and a connect that outlives that
            // is exactly the case where the difference shows.
            run_bounded(task, envelope.timeout_ms, async move {
                let mut dedicated = pools::get().dedicated(&dsn).await?;

                let outcome = run_one(dedicated.connection(), &command).await;

                // A connection whose command the server answered — well or badly
                // — is clean, and only then is it lent on. One cut off by the
                // deadline or by a stop never reaches this line at all, because
                // run_bounded drops this future: that is exactly why the handle
                // starts unreusable rather than being marked so here.
                dedicated.keep_if_clean(&outcome);

                outcome
            })
            .await
        } else {
            let acquired = match self.acquire(envelope) {
                Ok(acquired) => acquired,
                Err(error) => {
                    task.add_result(Result::error(message, error)).await;

                    return;
                }
            };

            let mut connection = acquired.connection();

            run_bounded(task, envelope.timeout_ms, async move {
                run_one(&mut connection, &command).await
            })
            .await
        };

        match outcome.and_then(|value| {
            values::encode_value(&value).map_err(|error| (Kind::Command, error))
        }) {
            Ok(payload) => {
                task.add_result(Result::success(message, payload, calc_execution_ms(start_time)))
                    .await
            }
            Err((kind, error)) => {
                task.add_result(Result::error(message, fail(kind, &error))).await
            }
        }
    }

    /// A pipeline: every command written before any answer is read, so the
    /// whole batch costs one round trip. With `atomic` the batch is wrapped in
    /// MULTI/EXEC and the server runs it as one unit.
    ///
    /// The failure of one command does not fail the answer — the server ran the
    /// others, and throwing their results away because of one typo would be
    /// worse than reporting the failure in its place.
    async fn handle_pipeline(&'static self, task: &Task, envelope: &payloads::Envelope) {
        let message = task.message();
        let start_time = Instant::now();

        let params: payloads::PipelineParams = match rmpv::ext::from_value(envelope.data.clone()) {
            Ok(params) => params,
            Err(error) => {
                task.add_result(Result::error(
                    message,
                    fail(Kind::Argument, &format!("parse pipeline params: {error}")),
                ))
                .await;

                return;
            }
        };

        if params.commands.is_empty() {
            task.add_result(Result::error(message, fail(Kind::Argument, "empty pipeline")))
                .await;

            return;
        }

        let mut pipeline = redis::pipe();

        if params.atomic {
            pipeline.atomic();
        }

        for entry in &params.commands {
            let name = commands::normalize_name(&entry.name);

            if name.is_empty() {
                task.add_result(Result::error(message, fail(Kind::Argument, "empty command name")))
                    .await;

                return;
            }

            // The arguments come first, because both checks below read them: a
            // refusal can hang on a subcommand, and whether a stream read blocks
            // is only visible in its BLOCK option.
            let args = match values::decode_args(&entry.args) {
                Ok(args) => args,
                Err(error) => {
                    task.add_result(Result::error(message, fail(Kind::Argument, &error)))
                        .await;

                    return;
                }
            };

            if let Some(refusal) = commands::refusal(&name, &args) {
                task.add_result(Result::error(message, fail(Kind::Refused, &refusal)))
                    .await;

                return;
            }

            if commands::is_blocking(&name, &args, commands::BLOCKING_BY_NAME) {
                task.add_result(Result::error(
                    message,
                    fail(
                        Kind::Argument,
                        &format!("{name} waits on the server and cannot run inside a pipeline"),
                    ),
                ))
                .await;

                return;
            }

            pipeline.add_command(commands::build(&name, &args));
        }

        let acquired = match self.acquire(envelope) {
            Ok(acquired) => acquired,
            Err(error) => {
                task.add_result(Result::error(message, error)).await;

                return;
            }
        };

        let mut connection = acquired.connection();
        let command_count = params.commands.len();
        let atomic = params.atomic;

        let outcome = run_bounded(task, envelope.timeout_ms, async move {
            // req_packed_commands rather than Pipeline::query_async, with the
            // offsets query_async itself uses: the typed call turns a failed
            // command into one error for the whole batch, and what is wanted
            // here is the raw answers, errors among them, one per command.
            let (offset, count) = if atomic {
                (command_count + 1, 1)
            } else {
                (0, command_count)
            };

            connection
                .req_packed_commands(&pipeline, offset, count)
                .await
                .map_err(|error| values::classify_error(&error))
        })
        .await;

        let values = match outcome {
            Ok(values) => values,
            Err((kind, error)) => {
                task.add_result(Result::error(message, fail(kind, &error))).await;

                return;
            }
        };

        let encoded = if atomic {
            match values.into_iter().next() {
                // The transaction was not run: a watched key changed under it.
                // PHP sees null, which is what EXEC answered.
                Some(Value::Nil) => values::encode_value(&Value::Nil),
                Some(Value::Array(replies)) => values::encode_pipeline(&replies),
                // EXEC itself failed — a command was refused while it was being
                // queued, so the server ran none of them.
                Some(Value::ServerError(error)) => Err(format!(
                    "{} {}",
                    error.code(),
                    error.details().unwrap_or("")
                )),
                Some(other) => values::encode_value(&other),
                None => values::encode_pipeline(&[]),
            }
        } else {
            values::encode_pipeline(&values)
        };

        let payload = match encoded {
            Ok(payload) => payload,
            Err(error) => {
                task.add_result(Result::error(message, fail(Kind::Command, &error)))
                    .await;

                return;
            }
        };

        task.add_result(Result::success(message, payload, calc_execution_ms(start_time)))
            .await;
    }

    /// A cursor command. The cursor stays here and PHP pulls batches, so a keyspace
    /// bigger than memory is walked without ever holding it whole.
    async fn handle_scan(&'static self, task: &Task, envelope: &payloads::Envelope) {
        let message = task.message();

        let params: payloads::ScanParams = match rmpv::ext::from_value(envelope.data.clone()) {
            Ok(params) => params,
            Err(error) => {
                task.add_result(Result::error(
                    message,
                    fail(Kind::Argument, &format!("parse scan params: {error}")),
                ))
                .await;

                return;
            }
        };

        let name = commands::normalize_name(&params.name);

        if !matches!(name.as_str(), "SCAN" | "HSCAN" | "SSCAN" | "ZSCAN") {
            task.add_result(Result::error(
                message,
                fail(Kind::Argument, &format!("{name} is not a cursor command")),
            ))
            .await;

            return;
        }

        let args = match values::decode_args(&params.args) {
            Ok(args) => args,
            Err(error) => {
                task.add_result(Result::error(message, fail(Kind::Argument, &error)))
                    .await;

                return;
            }
        };

        let acquired = match self.acquire(envelope) {
            Ok(acquired) => acquired,
            Err(error) => {
                task.add_result(Result::error(message, error)).await;

                return;
            }
        };

        let state = Arc::new(ScanState::new(
            name,
            args,
            normalize_batch_size(params.batch_size),
            envelope.timeout_ms,
            task.message_arc(),
            acquired,
        ));

        match states::get()
            .start(task.context().clone(), &message.task_key, state.clone())
            .await
        {
            Ok(result) => task.add_result(result).await,
            Err(error) => {
                state.close().await;

                task.add_result(Result::error(message, fail(Kind::State, &format!("start scan: {error}"))))
                    .await;
            }
        }
    }

    /// A subscription. It gets a connection of its own — the protocol puts the
    /// connection into subscriber mode — and streams messages to PHP in
    /// batches.
    async fn handle_subscribe(&'static self, task: &Task, envelope: &payloads::Envelope) {
        let message = task.message();
        let start_time = Instant::now();

        let params: payloads::SubscribeParams = match rmpv::ext::from_value(envelope.data.clone()) {
            Ok(params) => params,
            Err(error) => {
                task.add_result(Result::error(
                    message,
                    fail(Kind::Argument, &format!("parse subscribe params: {error}")),
                ))
                .await;

                return;
            }
        };

        let channels = match values::decode_args(&params.channels) {
            Ok(channels) => channels,
            Err(error) => {
                task.add_result(Result::error(message, fail(Kind::Argument, &error)))
                    .await;

                return;
            }
        };

        let patterns = match values::decode_args(&params.patterns) {
            Ok(patterns) => patterns,
            Err(error) => {
                task.add_result(Result::error(message, fail(Kind::Argument, &error)))
                    .await;

                return;
            }
        };

        if channels.is_empty() && patterns.is_empty() {
            task.add_result(Result::error(
                message,
                fail(Kind::Argument, "a subscription needs at least one channel or pattern"),
            ))
            .await;

            return;
        }

        let client = match pools::get().client(&envelope.dsn) {
            Ok(client) => client,
            Err(error) => {
                task.add_result(Result::error(message, fail(Kind::Dsn, &error)))
                    .await;

                return;
            }
        };

        // The dial is bounded and cancellable like every other one: get_async_pubsub
        // takes no configuration of its own, so the bound is applied here or it does
        // not exist. Without it a subscription to an unreachable host waits on the
        // kernel, and a stopped flow cannot end it.
        let dial = tokio::select! {
            biased;

            _ = task.context().cancelled() => Err((Kind::Stopped, "closed by task stop".to_string())),
            dial = tokio::time::timeout(pools::connect_timeout(), client.get_async_pubsub()) => match dial {
                Ok(Ok(pubsub)) => Ok(pubsub),
                Ok(Err(error)) => Err(values::classify_error(&error)),
                Err(_) => Err((Kind::Connection, "connect: timed out".to_string())),
            },
        };

        let pubsub = match dial {
            Ok(pubsub) => pubsub,
            Err((kind, error)) => {
                task.add_result(Result::error(message, fail(kind, &error))).await;

                return;
            }
        };

        let (sink, stream) = pubsub.split();

        let subscription = Arc::new(Subscription::new(sink));

        // Subscribing is a command on the wire, so the payload's deadline covers it
        // as it covers any other.
        let subscribed = run_bounded(task, envelope.timeout_ms, async {
            subscription
                .subscribe(&channels, &patterns)
                .await
                .map_err(|error| (Kind::Connection, error))
        })
        .await;

        if let Err((kind, error)) = subscribed {
            task.add_result(Result::error(message, fail(kind, &error))).await;

            return;
        }

        let subscription_id = message.task_key.clone();

        registry_subscriptions().store(subscription_id.clone(), subscription);

        let state = Arc::new(SubscribeState::new(
            stream,
            normalize_batch_size(params.batch_size),
            task.message_arc(),
            subscription_id.clone(),
            CancellationToken::new(),
        ));

        // register, not start: start would read the first batch, and the first
        // batch of a subscription is the first message somebody publishes.
        // Subscribing must answer as soon as the server confirms it.
        if let Err(error) = states::get().register(subscription_id.clone(), state.clone()) {
            registry_subscriptions().remove(&subscription_id);
            state.close().await;

            task.add_result(Result::error(
                message,
                fail(Kind::State, &format!("register subscription: {error}")),
            ))
            .await;

            return;
        }

        // Registering by hand means hooking the cleanup by hand too: PHP may
        // abandon the subscription without closing it, and then the flow ending
        // is the only thing that ever releases the connection.
        let flow_cancel = task.context().clone();
        let stop_id = subscription_id.clone();

        tokio::spawn(async move {
            flow_cancel.cancelled().await;

            registry_subscriptions().remove(&stop_id);
            states::get().delete_state(&stop_id).await;
        });

        // The first result carries the subscription id, not a message: PHP needs
        // it to add channels or close, and the messages start at the next pull.
        task.add_result(Result::success_with_next(
            message,
            encode_subscription_id(&subscription_id),
            calc_execution_ms(start_time),
        ))
        .await;
    }

    /// Adds or removes channels on a live subscription, on the connection that
    /// subscription is on.
    async fn handle_subscription_update(&'static self, task: &Task, envelope: &payloads::Envelope) {
        let message = task.message();
        let start_time = Instant::now();

        let params: payloads::SubscriptionUpdateParams =
            match rmpv::ext::from_value(envelope.data.clone()) {
                Ok(params) => params,
                Err(error) => {
                    task.add_result(Result::error(
                        message,
                        fail(Kind::Argument, &format!("parse subscription update params: {error}")),
                    ))
                    .await;

                    return;
                }
            };

        let Some(subscription) = registry_subscriptions().load(&params.subscription_id) else {
            task.add_result(Result::error(
                message,
                fail(Kind::State, &format!("unknown subscription {}", params.subscription_id)),
            ))
            .await;

            return;
        };

        let channels = match values::decode_args(&params.channels) {
            Ok(channels) => channels,
            Err(error) => {
                task.add_result(Result::error(message, fail(Kind::Argument, &error)))
                    .await;

                return;
            }
        };

        let patterns = match values::decode_args(&params.patterns) {
            Ok(patterns) => patterns,
            Err(error) => {
                task.add_result(Result::error(message, fail(Kind::Argument, &error)))
                    .await;

                return;
            }
        };

        // Bounded and cancellable: changing the channels of a subscription is a
        // command on its connection, and a connection that stopped answering must
        // not park this task for ever.
        let outcome = run_bounded(task, envelope.timeout_ms, async {
            match params.operation.as_str() {
                "add" => subscription
                    .subscribe(&channels, &patterns)
                    .await
                    .map_err(|error| (Kind::Connection, error)),
                "rem" => subscription
                    .unsubscribe(&channels, &patterns)
                    .await
                    .map_err(|error| (Kind::Connection, error)),
                other => Err((
                    Kind::Argument,
                    format!("unknown subscription operation {other}"),
                )),
            }
        })
        .await;

        match outcome {
            Ok(()) => {
                task.add_result(Result::success(
                    message,
                    Vec::new(),
                    calc_execution_ms(start_time),
                ))
                .await
            }
            Err((kind, error)) => {
                task.add_result(Result::error(message, fail(kind, &error))).await
            }
        }
    }

    /// Closes a subscription. Idempotent: an explicit close and the flow ending
    /// both arrive, in whichever order.
    async fn handle_subscription_close(&'static self, task: &Task, envelope: &payloads::Envelope) {
        let message = task.message();
        let start_time = Instant::now();

        let params: payloads::SubscriptionRefParams = match rmpv::ext::from_value(envelope.data.clone())
        {
            Ok(params) => params,
            Err(error) => {
                task.add_result(Result::error(
                    message,
                    fail(Kind::Argument, &format!("parse subscription ref: {error}")),
                ))
                .await;

                return;
            }
        };

        // Only a subscription this registry knows is closed through here. The id
        // arrives off the wire, and States is one process-wide map shared with the
        // MongoDB cursors, the SQL row sets and the request-body streams — deleting
        // whatever key it names would close somebody else's stream mid-iteration.
        // A miss answers success rather than a failure: the flow hook may have got
        // there first, and closing twice is not an error.
        if registry_subscriptions().remove(&params.subscription_id).is_some() {
            // Deleting the state closes the stream, which closes the connection:
            // the socket was the subscription.
            states::get().delete_state(&params.subscription_id).await;
        }

        task.add_result(Result::success(
            message,
            Vec::new(),
            calc_execution_ms(start_time),
        ))
        .await;
    }

    fn acquire(&'static self, envelope: &payloads::Envelope) -> std::result::Result<pools::Acquired, String> {
        pools::get()
            .acquire(&envelope.dsn, envelope.pool_size, envelope.conn_max_lifetime_ms)
            .map_err(|error| fail(Kind::Dsn, &error))
    }
}

/// One command, with a server error turned into a failure.
///
/// req_packed_command hands the reply back raw, error replies included — the
/// typed query_async is what normally converts them, and it is not on this path
/// because a pipeline needs those errors kept as values. A single command is the
/// other case: its failure is the call's failure, and PHP raises it.
async fn run_one<C>(
    connection: &mut C,
    command: &redis::Cmd,
) -> std::result::Result<Value, (Kind, String)>
where
    C: ConnectionLike,
{
    let value = connection
        .req_packed_command(command)
        .await
        .map_err(|error| values::classify_error(&error))?;

    value
        .extract_error()
        .map_err(|error| values::classify_error(&error))
}

fn encode_subscription_id(subscription_id: &str) -> Vec<u8> {
    let mut buffer = Vec::new();

    let _ = rmp::encode::write_map_len(&mut buffer, 1);
    let _ = rmp::encode::write_str(&mut buffer, "sid");
    let _ = rmp::encode::write_str(&mut buffer, subscription_id);

    buffer
}

fn normalize_batch_size(batch_size: i64) -> usize {
    if batch_size <= 0 {
        return DEFAULT_BATCH_SIZE;
    }

    (batch_size as usize).min(MAX_BATCH_SIZE)
}

/// A blocking command whose own wait outlasts the task deadline would be cut
/// off before the server was ever going to answer, and the caller would read
/// that as an unexplained timeout. Refuse it instead, naming both numbers.
fn deadline_conflict(name: &str, args: &[Vec<u8>], timeout_ms: i64) -> Option<String> {
    let blocking_ms = commands::blocking_timeout_ms(name, args)?;

    if blocking_ms == 0 {
        // Waiting forever is only coherent without a deadline.
        return if timeout_ms > 0 {
            Some(format!(
                "{name} was told to wait forever while the task deadline is {timeout_ms} ms: pass timeoutMs 0 to wait without one"
            ))
        } else {
            None
        };
    }

    if timeout_ms > 0 && timeout_ms <= blocking_ms {
        return Some(format!(
            "{name} waits up to {blocking_ms} ms while the task deadline is {timeout_ms} ms: raise timeoutMs above the command's own timeout"
        ));
    }

    None
}

/// Runs the work under the task deadline and the flow's cancellation. Both are
/// mandatory for every handler here: the deadline bounds it, the token stops it.
async fn run_bounded<F, T>(
    task: &Task,
    timeout_ms: i64,
    work: F,
) -> std::result::Result<T, (Kind, String)>
where
    F: std::future::Future<Output = std::result::Result<T, (Kind, String)>>,
{
    tokio::pin!(work);

    if timeout_ms <= 0 {
        return tokio::select! {
            biased;

            _ = task.context().cancelled() => Err((Kind::Stopped, "closed by task stop".to_string())),
            outcome = &mut work => outcome,
        };
    }

    let deadline = tokio::time::sleep(Duration::from_millis(timeout_ms as u64));

    tokio::select! {
        biased;

        _ = task.context().cancelled() => Err((Kind::Stopped, "closed by task stop".to_string())),
        _ = deadline => Err((
            Kind::Timeout,
            format!("the command did not answer within {timeout_ms} ms"),
        )),
        outcome = &mut work => outcome,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn args(values: &[&str]) -> Vec<Vec<u8>> {
        values.iter().map(|value| value.as_bytes().to_vec()).collect()
    }

    #[test]
    fn a_missing_batch_size_falls_back_to_the_default() {
        assert_eq!(normalize_batch_size(0), DEFAULT_BATCH_SIZE);
        assert_eq!(normalize_batch_size(-1), DEFAULT_BATCH_SIZE);
        assert_eq!(normalize_batch_size(10), 10);
        assert_eq!(normalize_batch_size(1_000_000), MAX_BATCH_SIZE);
    }

    #[test]
    fn a_deadline_shorter_than_the_wait_is_refused() {
        let error = deadline_conflict("BLPOP", &args(&["queue", "5"]), 1000).expect("refused");

        assert!(error.contains("raise timeoutMs"), "{error}");
    }

    #[test]
    fn a_deadline_above_the_wait_is_accepted() {
        assert!(deadline_conflict("BLPOP", &args(&["queue", "5"]), 6000).is_none());
    }

    #[test]
    fn waiting_forever_needs_no_deadline() {
        assert!(deadline_conflict("BLPOP", &args(&["queue", "0"]), 0).is_none());
        assert!(deadline_conflict("BLPOP", &args(&["queue", "0"]), 5000).is_some());
    }

    #[test]
    fn a_command_without_a_wait_never_conflicts() {
        assert!(deadline_conflict("GET", &args(&["key"]), 1).is_none());
    }
}
