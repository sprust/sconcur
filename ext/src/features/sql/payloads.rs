//! The Rust counterparts of the PHP
//! SQL payload objects. The renames are the short keys emitted by the PHP
//! getData()/getCommandData() methods.

use serde::Deserialize;

/// A missing command body decodes to nil rather than failing: the sub-operation
/// handler reports what it actually needs, which is a better message than
/// "missing field dt".
fn nil_value() -> rmpv::Value {
    rmpv::Value::Nil
}

/// Wraps every SQL command: the sub-operation, the connection settings and the
/// command body. PHP: SConcur\Features\Sql\Payloads\Base\BaseSqlPayload.
#[derive(Deserialize)]
pub struct Envelope {
    #[serde(rename = "cm", default)]
    pub command: String,
    #[serde(rename = "dsn", default)]
    pub dsn: String,
    #[serde(rename = "to", default)]
    pub timeout_ms: i64,
    #[serde(rename = "mo", default)]
    pub max_open_conns: i64,
    #[serde(rename = "mi", default)]
    pub max_idle_conns: i64,
    #[serde(rename = "cl", default)]
    pub conn_max_lifetime_ms: i64,
    /// The command body, kept as a decoded-but-untyped value until the
    /// sub-operation is known. The PHP side nests a map rather than an encoded
    /// blob, so this is the value that is actually on the wire and there is no
    /// second decode.
    #[serde(rename = "dt", default = "nil_value")]
    pub data: rmpv::Value,
}

/// The body of a Query command (the `dt`).
/// PHP: SConcur\Features\Sql\Payloads\QueryPayload.
#[derive(Deserialize, Default)]
pub struct QueryParams {
    #[serde(rename = "q", default)]
    pub sql: String,
    #[serde(rename = "b", default)]
    pub bindings: Vec<rmpv::Value>,
    #[serde(rename = "tx", default)]
    pub transaction_id: String,
    #[serde(rename = "bs", default)]
    pub batch_size: i64,
}

/// The body of an Exec command (the `dt`).
/// PHP: SConcur\Features\Sql\Payloads\ExecPayload.
#[derive(Deserialize, Default)]
pub struct ExecParams {
    #[serde(rename = "q", default)]
    pub sql: String,
    #[serde(rename = "b", default)]
    pub bindings: Vec<rmpv::Value>,
    #[serde(rename = "tx", default)]
    pub transaction_id: String,
}

/// The body of a Begin command (the `dt`).
/// PHP: SConcur\Features\Sql\Payloads\BeginPayload.
#[derive(Deserialize, Default)]
pub struct BeginParams {
    #[serde(rename = "iso", default)]
    pub isolation_level: i64,
    #[serde(rename = "ro", default)]
    pub read_only: bool,
}

/// The body of a Commit/Rollback command (the `dt`).
/// PHP: SConcur\Features\Sql\Payloads\CommitPayload, RollbackPayload.
#[derive(Deserialize, Default)]
pub struct TransactionRefParams {
    #[serde(rename = "tx", default)]
    pub transaction_id: String,
}
