//! Converting between BSON and PHP in both directions.
//!
//! Inbound, PHP sends a document as MessagePack: values BSON has and MessagePack
//! does not — an id, a date, a decimal — ride in the object envelope
//! `ext-msgpack` uses for PHP objects, and `docs/msgpack-objects.md` is that
//! contract. Outbound, a document is built as the PHP value the result carries
//! (`crate::dto::Value`): an associative array whose BSON-only values are
//! `SConcur\Bson\*` objects with their declared properties, so nothing decodes
//! on the PHP side.

pub mod from_msgpack;
pub mod to_value;

pub use from_msgpack::{
    PayloadConverter, document_from_msgpack, documents_from_msgpack, read_payload_value,
};
pub use to_value::{document_value, documents_value};

/// The classes an inbound envelope may name and an outbound object is created
/// as. Kept together because both directions have to agree on them exactly, and
/// a typo would surface as a silently mistyped value rather than an error.
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
