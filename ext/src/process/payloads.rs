//! The Rust counterparts of the parameters the process commands take. PHP builds
//! them as short-key maps in SConcur\Process\ProcessControl; the case of
//! SConcur\Process\ProcessCommandEnum that sends each one names the struct.

use serde::Deserialize;

/// PHP: ProcessCommandEnum::Fork.
#[derive(Deserialize)]
pub struct ForkParams {
    /// Descriptors the child keeps as they are. Every other one above stderr is
    /// pointed at /dev/null in the child.
    #[serde(rename = "kp", default)]
    pub keep: Vec<i64>,
    /// Whether the child's stdout and stderr become pipes the parent reads.
    #[serde(rename = "io", default)]
    pub capture_output: bool,
    /// Whether a third pipe is opened, for the child's liveness mark.
    #[serde(rename = "hb", default)]
    pub heartbeat: bool,
}

/// PHP: ProcessCommandEnum::Exit.
#[derive(Deserialize)]
pub struct ExitParams {
    #[serde(rename = "cd")]
    pub code: i64,
}

/// PHP: ProcessCommandEnum::Kill.
#[derive(Deserialize)]
pub struct KillParams {
    #[serde(rename = "pid")]
    pub pid: i64,
    /// The signal by name (`TERM`, `KILL`, …): the numbers differ between
    /// platforms, so they are resolved here.
    #[serde(rename = "sg")]
    pub signal: String,
}

/// PHP: ProcessCommandEnum::Reap.
#[derive(Deserialize)]
pub struct ReapParams {
    #[serde(rename = "pids", default)]
    pub pids: Vec<i64>,
}

/// PHP: ProcessCommandEnum::WatchSignals.
#[derive(Deserialize)]
pub struct WatchParams {
    #[serde(rename = "sg", default)]
    pub signals: Vec<String>,
}

/// PHP: ProcessCommandEnum::Bind.
#[derive(Deserialize)]
pub struct BindParams {
    #[serde(rename = "ad")]
    pub address: String,
    #[serde(rename = "bl", default)]
    pub backlog: i64,
}

/// PHP: ProcessCommandEnum::Close and ProcessCommandEnum::Adopted.
#[derive(Deserialize)]
pub struct CloseParams {
    #[serde(rename = "fd")]
    pub descriptor: i64,
}
