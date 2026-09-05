//! The MongoDB feature: the command dispatch over one pooled client.
//!
//! One feature handling every MongoDB command. The dispatch, the client pool,
//! the cursors and the msgpack<->BSON serializer are each their own module.

pub mod bulk;
pub mod clients;
pub mod commands;
pub mod payloads;
pub mod serializer;
pub mod states;

use std::sync::Arc;
use std::time::{Duration, Instant};

use crate::dto::Result;
use crate::errs::Factory;
use crate::features::{BoxFuture, Feature};
use crate::helpers::calc_execution_ms;
use crate::states as core_states;
use crate::tasks::Task;

use commands::Outcome;
use serializer::{document_to_msgpack, documents_to_msgpack};

static ERRORS: Factory = Factory::new("mongodb");

/// The feature's process-wide registry, owned by the Core so a fork discards it:
/// a driver client belongs to the process that opened its topology.
pub struct Registries {
    clients: clients::Clients,
}

impl Registries {
    pub fn new() -> Self {
        Registries {
            clients: clients::Clients::new(),
        }
    }

    pub fn clients(&self) -> &clients::Clients {
        &self.clients
    }
}

fn registries() -> &'static Registries {
    crate::core::get().mongodb()
}

pub struct MongodbFeature;

static INSTANCE: MongodbFeature = MongodbFeature;

pub fn get() -> &'static MongodbFeature {
    &INSTANCE
}

/// Mirrors the feature's share of features.Shutdown.
pub fn shutdown() {
    registries().clients().disconnect_all();
}

impl Feature for MongodbFeature {
    fn handle(&self, task: Task) -> BoxFuture {
        Box::pin(async move {
            let start_time = Instant::now();
            let message = task.message();

            let envelope = match payloads::decode_envelope(&message.payload) {
                Ok(envelope) => envelope,
                Err(error) => {
                    task.add_result(Result::error(
                        message,
                        ERRORS.by_err("parse payload", error),
                    ))
                    .await;

                    return;
                }
            };

            let client = match registries()
                .clients()
                .get(&envelope.url, envelope.server_selection_timeout_ms)
                .await
            {
                Ok(client) => client,
                Err(error) => {
                    task.add_result(Result::error(message, ERRORS.by_text(&error)))
                        .await;

                    return;
                }
            };

            // The per-call deadline the PHP side sends. A cursor's later batches
            // are not covered by it: they are pulled by their own next() call,
            // which is a task of its own.
            let outcome = match envelope.timeout_ms {
                timeout if timeout > 0 => {
                    match tokio::time::timeout(
                        Duration::from_millis(timeout as u64),
                        commands::run(&client, &envelope),
                    )
                    .await
                    {
                        Ok(outcome) => outcome,
                        Err(_) => Err("operation timed out".to_string()),
                    }
                }
                _ => commands::run(&client, &envelope).await,
            };

            let outcome = match outcome {
                Ok(outcome) => outcome,
                Err(error) => {
                    task.add_result(Result::error(
                        message,
                        ERRORS.by_text(&format!("{}: {error}", envelope.command)),
                    ))
                    .await;

                    return;
                }
            };

            let execution_ms = calc_execution_ms(start_time);

            match outcome {
                Outcome::Document(document) => match document_to_msgpack(&document) {
                    Ok(payload) => {
                        task.add_result(Result::success(message, payload, execution_ms))
                            .await;
                    }
                    Err(error) => {
                        task.add_result(Result::error(
                            message,
                            ERRORS.by_text(&format!("marshal result: {error}")),
                        ))
                        .await;
                    }
                },
                Outcome::Batch(documents) => match documents_to_msgpack(&documents) {
                    Ok(payload) => {
                        task.add_result(Result::success(message, payload, execution_ms))
                            .await;
                    }
                    Err(error) => {
                        task.add_result(Result::error(
                            message,
                            ERRORS.by_text(&format!("marshal batch: {error}")),
                        ))
                        .await;
                    }
                },
                // A count or an index name: PHP reads it straight off the task
                // payload without decoding, so it goes over as bare text.
                Outcome::Text(text) => {
                    task.add_result(Result::success(message, text.into_bytes(), execution_ms))
                        .await;
                }
                Outcome::Empty => {
                    task.add_result(Result::success(message, Vec::new(), execution_ms))
                        .await;
                }
                Outcome::Cursor(cursor, batch_size) => {
                    let state = Arc::new(self::states::CursorState::new(
                        task.message_arc(),
                        cursor,
                        batch_size,
                        &ERRORS,
                    ));

                    match core_states::get()
                        .start(task.context().clone(), &message.task_key, state)
                        .await
                    {
                        Ok(result) => task.add_result(result).await,
                        Err(error) => {
                            task.add_result(Result::error(
                                message,
                                ERRORS.by_text(&format!("start cursor: {error}")),
                            ))
                            .await;
                        }
                    }
                }
            }
        })
    }
}
