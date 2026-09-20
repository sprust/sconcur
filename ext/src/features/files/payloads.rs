//! The Rust counterparts of the PHP Files payload objects. The renames are the
//! short keys the PHP getData()/command builders emit.
//!
//! Every struct here has a FilesCommandEnum case naming it
//! (src/Features/Files/FilesCommandEnum.php), and every field defaults, so a
//! body that omits an optional parameter decodes rather than failing with
//! "missing field".

use serde::Deserialize;

/// A missing command body decodes to nil rather than failing: the sub-operation
/// handler reports what it actually needs, which is a better message than
/// "missing field dt".
fn nil_value() -> rmpv::Value {
    rmpv::Value::Nil
}

/// Wraps every file operation: the sub-operation, the execution deadline and
/// the body. PHP: SConcur\Features\Files\Payloads\FilesPayload.
#[derive(Deserialize)]
pub struct Envelope {
    #[serde(rename = "cm", default)]
    pub command: String,
    #[serde(rename = "to", default)]
    pub timeout_ms: i64,
    /// The operation's body, decoded once the sub-operation is known.
    #[serde(rename = "dt", default = "nil_value")]
    pub data: rmpv::Value,
}

/// The body of a Read (`rd`).
/// PHP: SConcur\Features\Files\Files::read().
#[derive(Deserialize, Default)]
pub struct ReadParams {
    #[serde(rename = "p", default)]
    pub path: String,
    #[serde(rename = "of", default)]
    pub offset_bytes: i64,
    /// 0 — to the end of the file.
    #[serde(rename = "ln", default)]
    pub length_bytes: i64,
    /// 0 — no limit.
    #[serde(rename = "mx", default)]
    pub max_read_bytes: i64,
}

/// The body of a Write (`wr`).
/// PHP: SConcur\Features\Files\Files::write().
#[derive(Deserialize)]
pub struct WriteParams {
    #[serde(rename = "p", default)]
    pub path: String,
    /// Untyped on purpose: whether PHP's packer emits a msgpack `str` or a
    /// `bin` for a string is its business, and contents::bytes_of reads both.
    /// Declaring Vec<u8> here would rest the wire format on how serde maps one
    /// of them onto a byte sequence.
    #[serde(rename = "c", default = "nil_value")]
    pub contents: rmpv::Value,
    /// A FileWriteMode wire value: rpl, crt or app.
    #[serde(rename = "m", default)]
    pub mode: String,
    /// The permission bits a newly created file is given. 0 — the default.
    #[serde(rename = "pm", default)]
    pub permissions: i64,
}

/// The body of a WriteAtomic (`wra`).
/// PHP: SConcur\Features\Files\Files::writeAtomic().
#[derive(Deserialize)]
pub struct WriteAtomicParams {
    #[serde(rename = "p", default)]
    pub path: String,
    /// Untyped on purpose: whether PHP's packer emits a msgpack `str` or a
    /// `bin` for a string is its business, and contents::bytes_of reads both.
    /// Declaring Vec<u8> here would rest the wire format on how serde maps one
    /// of them onto a byte sequence.
    #[serde(rename = "c", default = "nil_value")]
    pub contents: rmpv::Value,
    #[serde(rename = "pm", default)]
    pub permissions: i64,
}

/// The body of a Truncate (`tr`).
/// PHP: SConcur\Features\Files\Files::truncate().
#[derive(Deserialize, Default)]
pub struct TruncateParams {
    #[serde(rename = "p", default)]
    pub path: String,
    #[serde(rename = "sz", default)]
    pub size_bytes: i64,
}

/// The body of a Copy (`cp`).
/// PHP: SConcur\Features\Files\Files::copy().
#[derive(Deserialize, Default)]
pub struct CopyParams {
    #[serde(rename = "s", default)]
    pub source: String,
    #[serde(rename = "d", default)]
    pub destination: String,
    #[serde(rename = "m", default)]
    pub mode: String,
    #[serde(rename = "pm", default)]
    pub permissions: i64,
    /// The copy granularity inside the extension. 0 — the default.
    #[serde(rename = "bs", default)]
    pub buffer_size_bytes: i64,
}

/// The body of a Move (`mv`).
/// PHP: SConcur\Features\Files\Files::move().
#[derive(Deserialize, Default)]
pub struct MoveParams {
    #[serde(rename = "s", default)]
    pub source: String,
    #[serde(rename = "d", default)]
    pub destination: String,
}

/// The body of a Delete (`dl`).
/// PHP: SConcur\Features\Files\Files::delete().
#[derive(Deserialize, Default)]
pub struct DeleteParams {
    #[serde(rename = "p", default)]
    pub path: String,
    #[serde(rename = "mo", default)]
    pub missing_ok: bool,
}
