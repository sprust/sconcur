//! The result a task hands back, handed to the glue's result builder field by field (lib.rs).

use crate::dto::{Message, Value};
use crate::types::method::Method;

pub struct Result {
    pub flow_key: String,
    pub method: Method,
    pub task_key: String,
    pub is_error: bool,
    /// The feature's answer, as the PHP value it becomes: the wait export walks
    /// it on the PHP thread and builds the zvals. An error carries its message
    /// as a string here.
    pub payload: Value,
    pub has_next: bool,
    pub execution_ms: u32,
    /// Mirrors Message::owner_id: the PHP coroutine awaiting this result
    /// (0 = none), carried into the TaskResultDto.
    pub owner_id: i64,
}

impl Result {
    pub fn success(message: &Message, payload: impl Into<Value>, execution_ms: u32) -> Self {
        Result {
            flow_key: message.flow_key.clone(),
            method: message.method,
            task_key: message.task_key.clone(),
            is_error: false,
            payload: payload.into(),
            has_next: false,
            execution_ms,
            owner_id: message.owner_id,
        }
    }

    pub fn success_with_next(message: &Message, payload: impl Into<Value>, execution_ms: u32) -> Self {
        Result {
            flow_key: message.flow_key.clone(),
            method: message.method,
            task_key: message.task_key.clone(),
            is_error: false,
            payload: payload.into(),
            has_next: true,
            execution_ms,
            owner_id: message.owner_id,
        }
    }

    pub fn error(message: &Message, payload: String) -> Self {
        Result {
            flow_key: message.flow_key.clone(),
            method: message.method,
            task_key: message.task_key.clone(),
            is_error: true,
            payload: Value::Str(payload.into_bytes()),
            has_next: false,
            execution_ms: 0,
            owner_id: message.owner_id,
        }
    }
}
