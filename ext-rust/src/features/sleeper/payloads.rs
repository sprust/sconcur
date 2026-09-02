//! Mirrors ext/internal/features/sleeper/payloads: the Rust counterpart of the
//! PHP Sleeper payload objects. The field renames are the short keys emitted by
//! the PHP getData() methods.

use serde::Deserialize;

#[derive(Deserialize)]
pub struct SleeperPayload {
    #[serde(rename = "us")]
    pub microseconds: i64,
}
