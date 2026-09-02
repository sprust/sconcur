//! Mirrors ext-go-legacy/internal/features/mongodb/payloads.
//!
//! Every command arrives as an envelope (`ul`/`db`/`cl`/`to`/`sst`/`cm`/`dt`).
//! `dt` is one MessagePack blob: for some commands the document itself, for
//! others a map of named parameters whose values are **ordinary nested values**,
//! not blobs of their own — `'f' => $this->filter` on the PHP side.
//!
//! Go declares those parameters as `[]byte` and fills each from the raw bytes of
//! its field (`payloads.UnmarshalParams`), converting them one at a time. Here
//! the whole map is converted once under a single counter instead, which is what
//! keeps object references resolvable: ext-msgpack numbers containers across the
//! entire blob, so a per-field conversion would count them wrong.

use mongodb::bson::{Bson, Document};
use rmpv::Value;

use super::serializer::{PayloadConverter, read_payload_value};

pub type Error = String;

pub struct Envelope {
    pub url: String,
    pub database: String,
    pub collection: String,
    pub timeout_ms: i64,
    pub server_selection_timeout_ms: i64,
    pub command: String,
    /// The command body, still MessagePack — decoded by the handler that knows
    /// what shape it is.
    pub data: Vec<u8>,
}

pub fn decode_envelope(payload: &[u8]) -> Result<Envelope, Error> {
    let value = read_payload_value(payload)?;

    let Value::Map(pairs) = value else {
        return Err("expected a MessagePack map".to_string());
    };

    let map: Vec<(String, Value)> = pairs
        .into_iter()
        .filter_map(|(key, value)| key_name(&key).map(|name| (name, value)))
        .collect();

    Ok(Envelope {
        url: field_string(&map, "ul").unwrap_or_default(),
        database: field_string(&map, "db").unwrap_or_default(),
        collection: field_string(&map, "cl").unwrap_or_default(),
        timeout_ms: field_int(&map, "to").unwrap_or(0),
        server_selection_timeout_ms: field_int(&map, "sst").unwrap_or(0),
        command: field_string(&map, "cm").unwrap_or_default(),
        // The envelope's own `dt` really is a blob: the PHP side puts a
        // DocumentSerializer::serialize() result there.
        data: field_bytes(&map, "dt").unwrap_or_default(),
    })
}

/// The `dt` of a command that carries named parameters, already converted.
pub struct Params {
    fields: Vec<(String, Bson)>,
}

pub fn decode_params(data: &[u8]) -> Result<Params, Error> {
    if data.is_empty() {
        return Ok(Params { fields: Vec::new() });
    }

    let value = read_payload_value(data)?;

    let Value::Map(pairs) = value else {
        return Err("expected a MessagePack map of parameters".to_string());
    };

    let mut converter = PayloadConverter::new();

    // The params map is itself a container and takes the first number.
    converter.count_container();

    let mut fields = Vec::with_capacity(pairs.len());

    for (key, value) in &pairs {
        let Some(name) = key_name(key) else {
            continue;
        };

        // Converted in wire order, so the shared counter stays in step with the
        // order ext-msgpack wrote the containers in.
        fields.push((name, converter.value(value)?));
    }

    Ok(Params { fields })
}

impl Params {
    fn get(&self, name: &str) -> Option<&Bson> {
        self.fields
            .iter()
            .find(|(key, _)| key == name)
            .map(|(_, value)| value)
    }

    /// A nested document. `None` when the field is absent or empty — which is
    /// how the PHP side says "no projection", "no collation".
    pub fn document(&self, name: &str) -> Option<Document> {
        match self.get(name)? {
            Bson::Document(document) if !document.is_empty() => Some(document.clone()),
            _ => None,
        }
    }

    /// A filter-shaped field: absent means "match everything", which is an empty
    /// document rather than no document.
    pub fn filter(&self, name: &str) -> Document {
        self.document(name).unwrap_or_default()
    }

    /// A required document that may legitimately be empty — an update or a
    /// replacement the caller really did send as `{}`.
    pub fn document_or_empty(&self, name: &str) -> Document {
        match self.get(name) {
            Some(Bson::Document(document)) => document.clone(),
            _ => Document::new(),
        }
    }

    pub fn documents(&self, name: &str) -> Vec<Document> {
        match self.get(name) {
            Some(Bson::Array(items)) => items
                .iter()
                .filter_map(|item| match item {
                    Bson::Document(document) => Some(document.clone()),
                    _ => None,
                })
                .collect(),
            // A PHP list whose keys are not sequential packs as a map; the
            // values are still the entries.
            Some(Bson::Document(document)) => document
                .values()
                .filter_map(|value| match value {
                    Bson::Document(document) => Some(document.clone()),
                    _ => None,
                })
                .collect(),
            _ => Vec::new(),
        }
    }

    pub fn string(&self, name: &str) -> String {
        match self.get(name) {
            Some(Bson::String(text)) => text.clone(),
            Some(Bson::Int32(number)) => number.to_string(),
            Some(Bson::Int64(number)) => number.to_string(),
            _ => String::new(),
        }
    }

    pub fn int(&self, name: &str) -> i64 {
        match self.get(name) {
            Some(Bson::Int32(number)) => *number as i64,
            Some(Bson::Int64(number)) => *number,
            Some(Bson::Double(number)) => *number as i64,
            _ => 0,
        }
    }

    pub fn bool(&self, name: &str) -> bool {
        match self.get(name) {
            Some(Bson::Boolean(flag)) => *flag,
            Some(Bson::Int32(number)) => *number != 0,
            Some(Bson::Int64(number)) => *number != 0,
            _ => false,
        }
    }

    /// A hint is either an index name or a key document; PHP may send either,
    /// and wraps whichever it is in `{v: ...}` so the two shapes travel under
    /// one field (OptionsPayloadParameters::getData). Unwrapping is not
    /// optional: sending the wrapper itself asks the server to use an index
    /// literally named `v`, which is the "hint provided does not correspond to
    /// an existing index" error.
    pub fn hint(&self, name: &str) -> Option<mongodb::options::Hint> {
        let Bson::Document(wrapper) = self.get(name)? else {
            return None;
        };

        match wrapper.get("v")? {
            Bson::String(index) if !index.is_empty() => {
                Some(mongodb::options::Hint::Name(index.clone()))
            }
            Bson::Document(keys) if !keys.is_empty() => {
                Some(mongodb::options::Hint::Keys(keys.clone()))
            }
            _ => None,
        }
    }

    /// The collation option, decoded from the document PHP sent.
    pub fn collation(&self, name: &str) -> Result<Option<mongodb::options::Collation>, Error> {
        let Some(document) = self.document(name) else {
            return Ok(None);
        };

        mongodb::bson::deserialize_from_document(document)
            .map(Some)
            .map_err(|error| format!("parse collation: {error}"))
    }
}

fn key_name(value: &Value) -> Option<String> {
    match value {
        Value::String(text) => text.as_str().map(str::to_string),
        Value::Integer(number) => Some(number.to_string()),
        _ => None,
    }
}

fn find<'a>(pairs: &'a [(String, Value)], name: &str) -> Option<&'a Value> {
    pairs
        .iter()
        .find(|(key, _)| key == name)
        .map(|(_, value)| value)
}

fn field_string(pairs: &[(String, Value)], name: &str) -> Option<String> {
    match find(pairs, name)? {
        Value::String(text) => text.as_str().map(str::to_string),
        Value::Integer(number) => Some(number.to_string()),
        _ => None,
    }
}

fn field_int(pairs: &[(String, Value)], name: &str) -> Option<i64> {
    match find(pairs, name)? {
        Value::Integer(number) => number.as_i64(),
        Value::F64(number) => Some(*number as i64),
        _ => None,
    }
}

/// `ext-msgpack` may write a PHP string as either a str or a bin, so both are
/// accepted for the one field that really is a nested blob.
fn field_bytes(pairs: &[(String, Value)], name: &str) -> Option<Vec<u8>> {
    match find(pairs, name)? {
        Value::String(text) => Some(text.as_bytes().to_vec()),
        Value::Binary(bytes) => Some(bytes.clone()),
        _ => None,
    }
}
