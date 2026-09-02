//! Mirrors ext/internal/features/sleeper.

pub mod payloads;

use std::sync::OnceLock;
use std::time::Instant;

use crate::dto::Result;
use crate::errs::Factory;
use crate::features::{BoxFuture, Feature};
use crate::helpers::calc_execution_ms;
use crate::tasks::Task;

static ERR_FACTORY: Factory = Factory::new("sleep");
static INSTANCE: OnceLock<SleepFeature> = OnceLock::new();

pub struct SleepFeature;

pub fn get() -> &'static SleepFeature {
    INSTANCE.get_or_init(|| SleepFeature)
}

impl Feature for SleepFeature {
    fn handle(&self, task: Task) -> BoxFuture {
        Box::pin(async move {
            let start_time = Instant::now();
            let message = task.message();

            let payload: payloads::SleeperPayload = match rmp_serde::from_slice(&message.payload) {
                Ok(payload) => payload,
                Err(error) => {
                    task.add_result(Result::error(
                        message,
                        ERR_FACTORY.by_err("parse error", error),
                    )).await;

                    return;
                }
            };

            if payload.microseconds <= 0 {
                task.add_result(Result::error(
                    message,
                    ERR_FACTORY.by_text("microseconds must be greater than zero"),
                )).await;

                return;
            }

            let sleep = tokio::time::sleep(std::time::Duration::from_micros(
                payload.microseconds as u64,
            ));

            tokio::select! {
                _ = task.context().cancelled() => {
                    task.add_result(Result::error(
                        message,
                        ERR_FACTORY.by_text("closed by task stop"),
                    )).await;
                }
                _ = sleep => {
                    task.add_result(Result::success(
                        message,
                        Vec::new(),
                        calc_execution_ms(start_time),
                    )).await;
                }
            }
        })
    }
}
