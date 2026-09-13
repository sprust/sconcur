//! The Rust counterparts of the PHP Redis payload objects. The renames are the
//! short keys the PHP getData()/command builders emit.
//!
//! Command arguments arrive as untyped MessagePack values rather than byte
//! vectors: the PHP side has already refused everything that is not a string,
//! an integer or a float, and values::decode_arg turns what is left into the
//! bytes a command carries. Reading them as Vec<u8> here would rest on how
//! serde maps a msgpack `bin` onto a byte sequence, which is not a thing to
//! rest a wire format on.

use serde::Deserialize;

/// A missing command body decodes to nil rather than failing: the sub-operation
/// handler reports what it actually needs, which is a better message than
/// "missing field dt".
fn nil_value() -> rmpv::Value {
    rmpv::Value::Nil
}

/// Wraps every Redis command: the sub-operation, the connection settings and
/// the command body. PHP: SConcur\Features\Redis\Payloads\RedisPayload.
#[derive(Deserialize)]
pub struct Envelope {
    #[serde(rename = "cm", default)]
    pub command: String,
    #[serde(rename = "dsn", default)]
    pub dsn: String,
    #[serde(rename = "to", default)]
    pub timeout_ms: i64,
    #[serde(rename = "ps", default)]
    pub pool_size: i64,
    #[serde(rename = "cl", default)]
    pub conn_max_lifetime_ms: i64,
    /// The command body, decoded once the sub-operation is known.
    #[serde(rename = "dt", default = "nil_value")]
    pub data: rmpv::Value,
}

/// The body of a Command (`cmd`).
/// PHP: SConcur\Features\Redis\Connection::command().
#[derive(Deserialize, Default)]
pub struct CommandParams {
    #[serde(rename = "n", default)]
    pub name: String,
    #[serde(rename = "a", default)]
    pub args: Vec<rmpv::Value>,
    /// 0 — the core decides by name, 1 — blocking, 2 — not blocking.
    #[serde(rename = "bl", default)]
    pub blocking: i64,
}

/// One command inside a pipeline.
#[derive(Deserialize, Default)]
pub struct PipelineCommand {
    #[serde(rename = "n", default)]
    pub name: String,
    #[serde(rename = "a", default)]
    pub args: Vec<rmpv::Value>,
}

/// The body of a Pipeline (`pip`).
/// PHP: SConcur\Features\Redis\Pipeline::execute().
#[derive(Deserialize, Default)]
pub struct PipelineParams {
    #[serde(rename = "c", default)]
    pub commands: Vec<PipelineCommand>,
    #[serde(rename = "at", default)]
    pub atomic: bool,
}

/// The body of a Scan (`scn`).
/// PHP: SConcur\Features\Redis\Results\ScanResult.
#[derive(Deserialize, Default)]
pub struct ScanParams {
    #[serde(rename = "n", default)]
    pub name: String,
    /// The arguments after the cursor: the key for HSCAN/SSCAN/ZSCAN, then
    /// MATCH and COUNT. The cursor itself is the core's business.
    #[serde(rename = "a", default)]
    pub args: Vec<rmpv::Value>,
    #[serde(rename = "bs", default)]
    pub batch_size: i64,
}

/// The body of a Subscribe (`sub`).
/// PHP: SConcur\Features\Redis\Connection::subscribe().
#[derive(Deserialize, Default)]
pub struct SubscribeParams {
    #[serde(rename = "ch", default)]
    pub channels: Vec<rmpv::Value>,
    #[serde(rename = "pt", default)]
    pub patterns: Vec<rmpv::Value>,
    #[serde(rename = "bs", default)]
    pub batch_size: i64,
}

/// The body of a SubscriptionUpdate (`sup`).
/// PHP: SConcur\Features\Redis\Subscription::add(), remove().
#[derive(Deserialize, Default)]
pub struct SubscriptionUpdateParams {
    #[serde(rename = "sid", default)]
    pub subscription_id: String,
    /// "add" or "rem".
    #[serde(rename = "op", default)]
    pub operation: String,
    #[serde(rename = "ch", default)]
    pub channels: Vec<rmpv::Value>,
    #[serde(rename = "pt", default)]
    pub patterns: Vec<rmpv::Value>,
}

/// The body of a SubscriptionClose (`suc`).
/// PHP: SConcur\Features\Redis\Subscription::close().
#[derive(Deserialize, Default)]
pub struct SubscriptionRefParams {
    #[serde(rename = "sid", default)]
    pub subscription_id: String,
}
