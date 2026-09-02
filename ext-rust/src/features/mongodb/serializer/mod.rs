//! Mirrors ext/internal/features/mongodb/serializer.
//!
//! PHP and the extension exchange documents as MessagePack. Values BSON has and
//! MessagePack does not — an id, a date, a decimal — ride in the object envelope
//! `ext-msgpack` uses for PHP objects, and become `SConcur\Bson\*` instances on
//! the way back. `docs/msgpack-objects.md` is the contract; this module is its
//! Rust half.

pub mod from_msgpack;
pub mod to_msgpack;

pub use from_msgpack::{
    PayloadConverter, document_from_msgpack, documents_from_msgpack, read_payload_value,
};
pub use to_msgpack::{document_to_msgpack, documents_to_msgpack};

/// The classes an envelope may name. Kept together because both directions have
/// to agree on them exactly, and a typo would surface as a silently mistyped
/// value rather than an error.
pub mod classes {
    pub const OBJECT_ID: &str = r"SConcur\Bson\ObjectId";
    pub const UTC_DATE_TIME: &str = r"SConcur\Bson\UTCDateTime";
    pub const BINARY: &str = r"SConcur\Bson\Binary";
    pub const REGEX: &str = r"SConcur\Bson\Regex";
    pub const TIMESTAMP: &str = r"SConcur\Bson\Timestamp";
    pub const DECIMAL128: &str = r"SConcur\Bson\Decimal128";
    pub const JAVASCRIPT: &str = r"SConcur\Bson\Javascript";
    pub const MIN_KEY: &str = r"SConcur\Bson\MinKey";
    pub const MAX_KEY: &str = r"SConcur\Bson\MaxKey";
    pub const INT64: &str = r"SConcur\Bson\Int64";

    /// PHP's plain object. It names no BSON type — it is simply how an
    /// object-shaped value reaches a document (a `(object)` cast, `json_decode`
    /// without associative arrays) — so it converts to an ordinary
    /// sub-document.
    pub const STD_CLASS: &str = "stdClass";
}
