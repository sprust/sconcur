//! The parameters of the MongoDB commands.
//!
//! Every command arrives as an envelope (`ul`/`db`/`cl`/`to`/`sst`/`cm`/`dt`):
//! the PHP array the task was called with, as the push export read it. `dt` is
//! the command body, nested in place: for some commands the document itself,
//! for others a map of named parameters whose values are ordinary nested
//! values — `'f' => $this->filter` on the PHP side. The handler that knows what
//! shape the body has is the one that converts it.

use mongodb::bson::{Bson, Document};

use crate::dto::Value;

use super::serializer::bson_from_value;

pub type Error = String;

/// The envelope borrows the body from the message rather than copying it: a
/// document can be large, and the message outlives the command.
#[derive(Debug)]
pub struct Envelope<'a> {
    pub url: String,
    pub database: String,
    pub collection: String,
    pub timeout_ms: i64,
    pub server_selection_timeout_ms: i64,
    pub command: String,
    /// The command body, still a PHP value — converted by the handler that
    /// knows what shape it is.
    pub data: &'a Value,
}

pub fn decode_envelope(payload: &Value) -> Result<Envelope<'_>, Error> {
    if payload.as_map().is_none() {
        return Err("expected a map".to_string());
    }

    Ok(Envelope {
        url: field_string(payload, "ul").unwrap_or_default(),
        database: field_string(payload, "db").unwrap_or_default(),
        collection: field_string(payload, "cl").unwrap_or_default(),
        timeout_ms: field_int(payload, "to").unwrap_or(0),
        server_selection_timeout_ms: field_int(payload, "sst").unwrap_or(0),
        command: field_string(payload, "cm").unwrap_or_default(),
        data: payload.get("dt").unwrap_or(&Value::Nil),
    })
}

/// The `dt` of a command that carries named parameters, already converted.
#[derive(Debug)]
pub struct Params {
    fields: Vec<(String, Bson)>,
}

pub fn decode_params(data: &Value) -> Result<Params, Error> {
    if data.is_nil() {
        return Ok(Params { fields: Vec::new() });
    }

    let Some(entries) = data.as_map() else {
        return Err("expected a map of parameters".to_string());
    };

    let mut fields = Vec::with_capacity(entries.len());

    for (key, value) in entries {
        fields.push((key.text(), bson_from_value(value)?));
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
            // A PHP array whose keys are not sequential arrives as a map; the
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

fn field_string(payload: &Value, name: &str) -> Option<String> {
    let value = payload.get(name)?;

    match value {
        Value::Str(_) => value.as_str().map(str::to_string),
        Value::Int(number) => Some(number.to_string()),
        _ => None,
    }
}

fn field_int(payload: &Value, name: &str) -> Option<i64> {
    match payload.get(name)? {
        Value::Int(number) => Some(*number),
        Value::Float(number) => Some(*number as i64),
        _ => None,
    }
}

#[cfg(test)]
mod tests {
    use mongodb::bson::doc;

    use super::*;
    use crate::dto::Map;

    #[test]
    fn the_envelope_reads_its_fields_and_borrows_the_body() {
        let body: Value = Map::new().set("f", Map::new().set("a", 1_i64)).into();
        let payload: Value = Map::new()
            .set("ul", "mongodb://localhost")
            .set("db", "shop")
            .set("cl", "orders")
            .set("to", 1500_i64)
            .set("sst", 3000_i64)
            .set("cm", "fnd")
            .set("dt", body.clone())
            .into();

        let envelope = decode_envelope(&payload).expect("an envelope");

        assert_eq!(envelope.url, "mongodb://localhost");
        assert_eq!(envelope.database, "shop");
        assert_eq!(envelope.collection, "orders");
        assert_eq!(envelope.timeout_ms, 1500);
        assert_eq!(envelope.server_selection_timeout_ms, 3000);
        assert_eq!(envelope.command, "fnd");
        assert_eq!(envelope.data, &body);
    }

    #[test]
    fn a_missing_field_takes_its_default_and_a_missing_body_is_null() {
        let payload: Value = Map::new().set("cm", "drp").into();

        let envelope = decode_envelope(&payload).expect("an envelope");

        assert_eq!(envelope.url, "");
        assert_eq!(envelope.timeout_ms, 0);
        assert_eq!(envelope.data, &Value::Nil);
        assert_eq!(decode_envelope(&Value::Int(1)).unwrap_err(), "expected a map");
    }

    #[test]
    fn params_read_their_fields_through_bson() {
        let data: Value = Map::new()
            .set("f", Map::new().set("a", 1_i64))
            .set("op", Value::List(vec![]))
            .set("u", Value::List(vec![]))
            .set("af", Value::List(vec![Map::new().set("x", 1_i64).into()]))
            .set("bs", 10_i64)
            .set("ou", true)
            .set("fn", "name")
            .set("hn", Map::new().set("v", "index_name"))
            .into();

        let params = decode_params(&data).expect("params");

        assert_eq!(params.filter("f"), doc! { "a": 1_i32 });
        assert_eq!(params.filter("missing"), doc! {});
        assert_eq!(params.document("op"), None);
        assert_eq!(params.document_or_empty("u"), doc! {});
        assert_eq!(params.documents("af"), vec![doc! { "x": 1_i32 }]);
        assert_eq!(params.int("bs"), 10);
        assert!(params.bool("ou"));
        assert_eq!(params.string("fn"), "name");
        assert!(matches!(params.hint("hn"), Some(mongodb::options::Hint::Name(name)) if name == "index_name"));
        assert!(params.hint("missing").is_none());
    }

    #[test]
    fn an_absent_or_empty_body_has_no_params_and_a_scalar_is_an_error() {
        assert!(decode_params(&Value::Nil).expect("params").fields.is_empty());
        assert!(decode_params(&Value::List(vec![])).expect("params").fields.is_empty());
        assert_eq!(decode_params(&Value::Int(1)).unwrap_err(), "expected a map of parameters");
    }
}
