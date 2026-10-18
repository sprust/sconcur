//! The message a push carries in.

use crate::dto::Value;
use crate::types::method::Method;

pub struct Message {
    pub flow_key: String,
    pub method: Method,
    pub task_key: String,
    /// The task's parameters, read off the PHP array the push was given: the
    /// feature decodes them with `Value::decode` or reads them field by field.
    pub payload: Value,
    pub is_next: bool,
    /// The opaque PHP-side coroutine id awaiting this task's result (0 =
    /// nobody). Carried into the result frame so the PHP scheduler routes the
    /// result without its own task-to-fiber map.
    pub owner_id: i64,
}
