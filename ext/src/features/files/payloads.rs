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

/// The body of a Stat (`st`).
/// PHP: SConcur\Features\Files\Files::stat().
#[derive(Deserialize, Default)]
pub struct StatParams {
    #[serde(rename = "p", default)]
    pub path: String,
    /// Whether a symlink is followed to what it points at (stat) or described
    /// as itself (lstat).
    #[serde(rename = "fs", default)]
    pub follow_symlinks: bool,
}

/// The body of a Chmod (`chm`).
/// PHP: SConcur\Features\Files\Files::chmod().
#[derive(Deserialize, Default)]
pub struct ChmodParams {
    #[serde(rename = "p", default)]
    pub path: String,
    #[serde(rename = "pm", default)]
    pub permissions: i64,
}

/// The body of a Touch (`tch`).
/// PHP: SConcur\Features\Files\Files::touch().
#[derive(Deserialize, Default)]
pub struct TouchParams {
    #[serde(rename = "p", default)]
    pub path: String,
    /// 0 — now.
    #[serde(rename = "mt", default)]
    pub modified_at_ms: i64,
    #[serde(rename = "pm", default)]
    pub permissions: i64,
}

/// The body of a RealPath (`rp`).
/// PHP: SConcur\Features\Files\Files::realPath().
#[derive(Deserialize, Default)]
pub struct RealPathParams {
    #[serde(rename = "p", default)]
    pub path: String,
}

/// The body of a TemporaryFile (`tmp`).
/// PHP: SConcur\Features\Files\Files::temporaryFile().
#[derive(Deserialize, Default)]
pub struct TemporaryFileParams {
    /// Empty — the system temporary directory.
    #[serde(rename = "d", default)]
    pub directory: String,
    #[serde(rename = "pf", default)]
    pub prefix: String,
    #[serde(rename = "sf", default)]
    pub suffix: String,
    #[serde(rename = "pm", default)]
    pub permissions: i64,
}

/// The body of a MakeDirectory (`mkd`).
/// PHP: SConcur\Features\Files\Files::makeDirectory().
#[derive(Deserialize, Default)]
pub struct MakeDirectoryParams {
    #[serde(rename = "p", default)]
    pub path: String,
    #[serde(rename = "pm", default)]
    pub permissions: i64,
    #[serde(rename = "rc", default)]
    pub recursive: bool,
}

/// The body of a RemoveDirectory (`rmd`).
/// PHP: SConcur\Features\Files\Files::removeDirectory().
#[derive(Deserialize, Default)]
pub struct RemoveDirectoryParams {
    #[serde(rename = "p", default)]
    pub path: String,
    #[serde(rename = "rc", default)]
    pub recursive: bool,
    #[serde(rename = "mo", default)]
    pub missing_ok: bool,
}

/// The body of a List (`ls`).
/// PHP: SConcur\Features\Files\Files::list().
#[derive(Deserialize, Default)]
pub struct ListParams {
    #[serde(rename = "p", default)]
    pub path: String,
    /// Empty — everything.
    #[serde(rename = "pt", default)]
    pub pattern: String,
    /// Whether each entry is stat'ed. Off, the listing is names and types only,
    /// which is one syscall instead of one per entry.
    #[serde(rename = "wm", default)]
    pub with_metadata: bool,
}

/// The body of a HashFile (`hsh`).
/// PHP: SConcur\Features\Files\Files::hashFile().
#[derive(Deserialize, Default)]
pub struct HashFileParams {
    #[serde(rename = "p", default)]
    pub path: String,
    /// A FileHashAlgorithm wire value: sha256, sha512, sha1 or md5.
    #[serde(rename = "a", default)]
    pub algorithm: String,
}

/// The body of a ReadChunks (`rdc`).
/// PHP: SConcur\Features\Files\Files::readChunks().
#[derive(Deserialize, Default)]
pub struct ReadChunksParams {
    #[serde(rename = "p", default)]
    pub path: String,
    /// 0 — the feature's default.
    #[serde(rename = "bs", default)]
    pub buffer_size_bytes: i64,
}

/// The body of a ReadLines (`rdl`).
/// PHP: SConcur\Features\Files\Files::readLines().
#[derive(Deserialize, Default)]
pub struct ReadLinesParams {
    #[serde(rename = "p", default)]
    pub path: String,
    /// Lines per batch. 0 — the feature's default.
    #[serde(rename = "b", default)]
    pub batch_size: i64,
    #[serde(rename = "bs", default)]
    pub buffer_size_bytes: i64,
    /// 0 — the feature's default.
    #[serde(rename = "ml", default)]
    pub max_line_bytes: i64,
}

/// The body of a Walk (`lst`).
/// PHP: SConcur\Features\Files\Files::walk().
#[derive(Deserialize, Default)]
pub struct WalkParams {
    #[serde(rename = "p", default)]
    pub path: String,
    #[serde(rename = "pt", default)]
    pub pattern: String,
    #[serde(rename = "wm", default)]
    pub with_metadata: bool,
    /// Entries per batch. 0 — the feature's default.
    #[serde(rename = "b", default)]
    pub batch_size: i64,
}

/// The body of a WriteOpen (`wro`).
/// PHP: SConcur\Features\Files\Files::openWriter().
#[derive(Deserialize, Default)]
pub struct WriteOpenParams {
    /// The id PHP drew for this writer; the name all three commands share.
    #[serde(rename = "i", default)]
    pub id: String,
    #[serde(rename = "p", default)]
    pub path: String,
    #[serde(rename = "m", default)]
    pub mode: String,
    #[serde(rename = "pm", default)]
    pub permissions: i64,
}

/// The body of a WriteChunk (`wrc`).
/// PHP: SConcur\Features\Files\Dto\FileWriter::write().
#[derive(Deserialize)]
pub struct WriteChunkParams {
    #[serde(rename = "i", default)]
    pub id: String,
    #[serde(rename = "c", default = "nil_value")]
    pub chunk: rmpv::Value,
}

/// The body of a WriteClose (`wrx`).
/// PHP: SConcur\Features\Files\Dto\FileWriter::close().
#[derive(Deserialize, Default)]
pub struct WriteCloseParams {
    #[serde(rename = "i", default)]
    pub id: String,
}
