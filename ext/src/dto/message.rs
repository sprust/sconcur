//! Mirrors ext-go-legacy/internal/dto/message.go.

use crate::types::method::Method;

pub struct Message {
    pub flow_key: String,
    pub method: Method,
    pub task_key: String,
    pub payload: Vec<u8>,
    pub is_next: bool,
    /// The opaque PHP-side coroutine id awaiting this task's result (0 =
    /// nobody). Carried into the result frame so the PHP scheduler routes the
    /// result without its own task-to-fiber map.
    pub owner_id: i64,
}
