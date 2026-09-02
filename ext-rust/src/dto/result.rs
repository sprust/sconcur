//! Mirrors ext/internal/dto/result.go.

use crate::dto::Message;
use crate::types::method::Method;

pub struct Result {
    pub flow_key: String,
    pub method: Method,
    pub task_key: String,
    pub is_error: bool,
    /// The feature payload, already encoded. Bytes rather than Go's `string`:
    /// the frame writes it verbatim and nothing here treats it as text.
    pub payload: Vec<u8>,
    pub has_next: bool,
    pub execution_ms: u32,
    /// Mirrors Message::owner_id: the PHP coroutine awaiting this result
    /// (0 = none), carried in the binary result frame.
    pub owner_id: i64,
}

impl Result {
    pub fn success(message: &Message, payload: Vec<u8>, execution_ms: u32) -> Self {
        Result {
            flow_key: message.flow_key.clone(),
            method: message.method,
            task_key: message.task_key.clone(),
            is_error: false,
            payload,
            has_next: false,
            execution_ms,
            owner_id: message.owner_id,
        }
    }

    pub fn success_with_next(message: &Message, payload: Vec<u8>, execution_ms: u32) -> Self {
        Result {
            flow_key: message.flow_key.clone(),
            method: message.method,
            task_key: message.task_key.clone(),
            is_error: false,
            payload,
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
            payload: payload.into_bytes(),
            has_next: false,
            execution_ms: 0,
            owner_id: message.owner_id,
        }
    }
}
