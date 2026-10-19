//! The PHP value a task was given -> BSON.
//!
//! The document arrives as the `crate::dto::Value` tree the push export read
//! off the PHP array: a PHP array is a `Map` or a `List`, a scalar is itself,
//! a `stdClass` is already a `Map`, and a `SConcur\Bson\*` value object is an
//! `Object` whose class names the BSON type and whose public properties carry
//! its parts. The walk is a plain recursion over that tree: there is no
//! envelope to recognise and nothing to count, because a value object that
//! appears twice in a document is simply read twice.

use mongodb::bson::spec::BinarySubtype;
use mongodb::bson::{Binary, Bson, DateTime, Decimal128, Document, JavaScriptCodeWithScope};
use std::str::FromStr;

use crate::dto::{Key, Object, Value};

use super::classes;

pub type Error = String;

/// The outermost document of a payload. PHP's `null` and an empty array are
/// both the empty document.
pub fn document_from_value(value: &Value) -> Result<Document, Error> {
    match value {
        Value::Map(entries) => document_from_entries(entries),
        // A PHP list is a document keyed by position, which is what the driver
        // expects for a document built from a list.
        Value::List(items) => {
            let mut document = Document::new();

            for (position, item) in items.iter().enumerate() {
                document.insert(position.to_string(), bson_from_value(item)?);
            }

            Ok(document)
        }
        Value::Object(object) => Err(format!("a document cannot be a {}", object.class)),
        Value::Nil => Ok(Document::new()),
        other => Err(format!("expected a document, got {}", describe(other))),
    }
}

/// A list of documents (insertMany, and the pipeline's stages).
pub fn documents_from_value(value: &Value) -> Result<Vec<Document>, Error> {
    if value.is_nil() {
        return Ok(Vec::new());
    }

    let Some(items) = value.as_list() else {
        return Err("expected an array of documents".to_string());
    };

    items.iter().map(document_from_value).collect()
}

/// Any value of a document, by the PHP type it arrived as.
pub fn bson_from_value(value: &Value) -> Result<Bson, Error> {
    match value {
        Value::Nil => Ok(Bson::Null),
        Value::Bool(flag) => Ok(Bson::Boolean(*flag)),
        Value::Int(number) => Ok(int_to_bson(*number)),
        Value::Float(number) => Ok(Bson::Double(*number)),
        Value::Str(bytes) => match std::str::from_utf8(bytes) {
            Ok(text) => Ok(Bson::String(text.to_string())),
            // A PHP string that is not valid UTF-8 cannot be a BSON string; it
            // becomes generic binary, which is what the bytes are.
            Err(_) => Ok(Bson::Binary(Binary {
                subtype: BinarySubtype::Generic,
                bytes: bytes.clone(),
            })),
        },
        Value::List(items) => {
            let mut array = Vec::with_capacity(items.len());

            for item in items {
                array.push(bson_from_value(item)?);
            }

            Ok(Bson::Array(array))
        }
        Value::Map(entries) => Ok(Bson::Document(document_from_entries(entries)?)),
        Value::Object(object) => bson_from_object(object),
    }
}

fn document_from_entries(entries: &[(Key, Value)]) -> Result<Document, Error> {
    let mut document = Document::new();

    for (key, item) in entries {
        document.insert(map_key(key)?, bson_from_value(item)?);
    }

    Ok(document)
}

/// The BSON value a `SConcur\Bson\*` object names. The property names are the
/// declared public properties of the PHP classes, the same ones to_value.rs
/// writes.
fn bson_from_object(object: &Object) -> Result<Bson, Error> {
    match object.class.as_ref() {
        classes::OBJECT_ID => {
            let hexadecimal = property_string(object, "oid")?;

            mongodb::bson::oid::ObjectId::parse_str(&hexadecimal)
                .map(Bson::ObjectId)
                .map_err(|error| format!("invalid ObjectId: {error}"))
        }
        classes::UTC_DATE_TIME => Ok(Bson::DateTime(DateTime::from_millis(property_int(
            object, "epochMs",
        )?))),
        classes::BINARY => {
            let subtype = property_int(object, "subType")?;

            if !(0..=u8::MAX as i64).contains(&subtype) {
                return Err(format!("Binary subType {subtype} out of range"));
            }

            Ok(Bson::Binary(Binary {
                subtype: BinarySubtype::from(subtype as u8),
                bytes: property_bytes(object, "data")?,
            }))
        }
        // A BSON regex is stored as two C strings, so neither half may hold an
        // interior NUL — the conversion is where that is caught.
        classes::REGEX => {
            let pattern = cstring(property_string(object, "pattern")?, "Regex pattern")?;
            let options = cstring(property_string(object, "flags")?, "Regex flags")?;

            Ok(Bson::RegularExpression(mongodb::bson::Regex { pattern, options }))
        }
        classes::TIMESTAMP => {
            let seconds = property_range(object, "epochSeconds", u32::MAX as i64)?;
            let increment = property_range(object, "increment", u32::MAX as i64)?;

            Ok(Bson::Timestamp(mongodb::bson::Timestamp {
                time: seconds as u32,
                increment: increment as u32,
            }))
        }
        classes::DECIMAL128 => {
            let value = property_string(object, "value")?;

            Decimal128::from_str(&value)
                .map(Bson::Decimal128)
                .map_err(|error| format!("invalid Decimal128: {error:?}"))
        }
        classes::JAVASCRIPT => javascript_from(object),
        classes::MIN_KEY => Ok(Bson::MinKey),
        classes::MAX_KEY => Ok(Bson::MaxKey),
        classes::INT64 => Ok(Bson::Int64(property_int(object, "value")?)),
        other => Err(format!("unsupported object class \"{other}\"")),
    }
}

/// A plain javascript element, or a code-with-scope one when the object
/// carries a non-empty scope.
fn javascript_from(object: &Object) -> Result<Bson, Error> {
    let code = property_string(object, "code")?;

    let scope = match object.property("scope") {
        None | Some(Value::Nil) => None,
        // An empty PHP array is a list, so an empty scope arrives as either.
        Some(scope) if scope.as_map().is_some_and(|entries| entries.is_empty()) => None,
        Some(Value::Map(entries)) => Some(document_from_entries(entries)?),
        Some(other) => {
            return Err(format!(
                "Javascript scope must be a map, got {}",
                describe(other)
            ));
        }
    };

    Ok(match scope {
        Some(scope) => Bson::JavaScriptCodeWithScope(JavaScriptCodeWithScope { code, scope }),
        None => Bson::JavaScriptCode(code),
    })
}

/// BSON has no unsized integer: a value that fits in 32 bits is an int32, the
/// rest are int64. Mirrors appendInt.
fn int_to_bson(value: i64) -> Bson {
    if value >= i32::MIN as i64 && value <= i32::MAX as i64 {
        return Bson::Int32(value as i32);
    }

    Bson::Int64(value)
}

/// A PHP array key is a string or an integer; both reach BSON as a field name.
fn map_key(key: &Key) -> Result<String, Error> {
    match key {
        Key::Str(bytes) => String::from_utf8(bytes.clone())
            .map_err(|_| "map key is not valid UTF-8".to_string()),
        Key::Int(number) => Ok(number.to_string()),
    }
}

fn property_string(object: &Object, name: &str) -> Result<String, Error> {
    match object.property(name) {
        Some(Value::Str(bytes)) => String::from_utf8(bytes.clone())
            .map_err(|_| format!("{} property {name} is not valid UTF-8", object.class)),
        Some(Value::Int(number)) => Ok(number.to_string()),
        Some(_) => Err(format!("{} property {name} is not a string", object.class)),
        None => Err(format!("{} is missing property {name}", object.class)),
    }
}

fn property_int(object: &Object, name: &str) -> Result<i64, Error> {
    match object.property(name) {
        Some(Value::Int(number)) => Ok(*number),
        // PHP may hand an integer-valued float here.
        Some(Value::Float(number)) => Ok(*number as i64),
        Some(Value::Str(bytes)) => std::str::from_utf8(bytes)
            .ok()
            .and_then(|text| text.parse::<i64>().ok())
            .ok_or_else(|| format!("{} property {name} is not an integer", object.class)),
        Some(_) => Err(format!("{} property {name} is not an integer", object.class)),
        None => Err(format!("{} is missing property {name}", object.class)),
    }
}

fn property_range(object: &Object, name: &str, maximum: i64) -> Result<i64, Error> {
    let value = property_int(object, name)?;

    if value < 0 || value > maximum {
        return Err(format!(
            "{} property {name} is out of range: {value}",
            object.class
        ));
    }

    Ok(value)
}

/// A PHP string is bytes whatever it holds, so the property is taken as is.
fn property_bytes(object: &Object, name: &str) -> Result<Vec<u8>, Error> {
    match object.property(name) {
        Some(Value::Str(bytes)) => Ok(bytes.clone()),
        Some(_) => Err(format!("{} property {name} is not a string", object.class)),
        None => Err(format!("{} is missing property {name}", object.class)),
    }
}

fn cstring(text: String, label: &str) -> Result<mongodb::bson::raw::CString, Error> {
    mongodb::bson::raw::CString::try_from(text)
        .map_err(|error| format!("{label} cannot contain a NUL byte: {error}"))
}

fn describe(value: &Value) -> &'static str {
    match value {
        Value::Nil => "null",
        Value::Bool(_) => "a boolean",
        Value::Int(_) => "an integer",
        Value::Float(_) => "a float",
        Value::Str(_) => "a string",
        Value::List(_) => "a list",
        Value::Map(_) => "a map",
        Value::Object(_) => "an object",
    }
}

#[cfg(test)]
mod tests {
    use mongodb::bson::oid::ObjectId;
    use mongodb::bson::{Bson, doc};

    use super::*;
    use crate::dto::Map;

    fn object_id_value(id: &ObjectId) -> Value {
        Value::object(classes::OBJECT_ID, vec![("oid", id.to_hex().into())])
    }

    #[test]
    fn a_php_array_becomes_a_document_with_scalars_mapped_one_to_one() {
        let value: Value = Map::new()
            .set("name", "x")
            .set("count", 3_i64)
            .set("big", i64::MAX)
            .set("ratio", 1.5)
            .set("flag", true)
            .set("none", Value::Nil)
            .set(7_i64, "by position")
            .into();

        let document = document_from_value(&value).expect("a document");

        assert_eq!(
            document,
            doc! {
                "name": "x",
                "count": 3_i32,
                "big": i64::MAX,
                "ratio": 1.5,
                "flag": true,
                "none": Bson::Null,
                "7": "by position",
            }
        );
    }

    #[test]
    fn null_and_an_empty_array_are_the_empty_document() {
        assert_eq!(document_from_value(&Value::Nil).unwrap(), Document::new());
        assert_eq!(document_from_value(&Value::List(vec![])).unwrap(), Document::new());
        assert_eq!(document_from_value(&Value::Map(vec![])).unwrap(), Document::new());
    }

    #[test]
    fn a_list_is_a_document_keyed_by_position_and_an_array_inside_stays_an_array() {
        let value = Value::List(vec!["a".into(), "b".into()]);

        assert_eq!(document_from_value(&value).unwrap(), doc! { "0": "a", "1": "b" });

        let nested: Value = Map::new().set("tags", Value::List(vec!["a".into(), 2_i64.into()])).into();

        assert_eq!(
            document_from_value(&nested).unwrap(),
            doc! { "tags": ["a", 2_i32] }
        );
    }

    #[test]
    fn a_scalar_is_not_a_document() {
        let error = document_from_value(&Value::Int(1)).unwrap_err();

        assert_eq!(error, "expected a document, got an integer");
    }

    #[test]
    fn an_object_id_object_becomes_a_bson_object_id_at_any_depth() {
        let id = ObjectId::new();
        let other = ObjectId::new();

        let value: Value = Map::new()
            .set("_id", object_id_value(&id))
            .set(
                "parent",
                Map::new().set("ref", object_id_value(&other)).set("ids", Value::List(vec![object_id_value(&id)])),
            )
            .into();

        let document = document_from_value(&value).expect("a document");

        assert_eq!(
            document,
            doc! { "_id": id, "parent": { "ref": other, "ids": [id] } }
        );
    }

    #[test]
    fn the_same_value_object_twice_is_read_twice() {
        let id = ObjectId::new();

        let value: Value = Map::new()
            .set("first", object_id_value(&id))
            .set("second", object_id_value(&id))
            .into();

        assert_eq!(
            document_from_value(&value).unwrap(),
            doc! { "first": id, "second": id }
        );
    }

    #[test]
    fn the_other_bson_classes_become_their_types() {
        let value: Value = Map::new()
            .set(
                "date",
                Value::object(classes::UTC_DATE_TIME, vec![("epochMs", Value::Int(1_700_000_000_123))]),
            )
            .set(
                "binary",
                Value::object(
                    classes::BINARY,
                    vec![("data", Value::Str(vec![1, 2, 3])), ("subType", Value::Int(4))],
                ),
            )
            .set(
                "regex",
                Value::object(classes::REGEX, vec![("pattern", "^a".into()), ("flags", "i".into())]),
            )
            .set(
                "stamp",
                Value::object(
                    classes::TIMESTAMP,
                    vec![("increment", Value::Int(7)), ("epochSeconds", Value::Int(42))],
                ),
            )
            .set(
                "decimal",
                Value::object(classes::DECIMAL128, vec![("value", "12.50".into())]),
            )
            .set("min", Value::object(classes::MIN_KEY, vec![]))
            .set("max", Value::object(classes::MAX_KEY, vec![]))
            .set("long", Value::object(classes::INT64, vec![("value", Value::Int(7))]))
            .into();

        let document = document_from_value(&value).expect("a document");

        assert_eq!(document.get("date"), Some(&Bson::DateTime(DateTime::from_millis(1_700_000_000_123))));
        assert_eq!(
            document.get("binary"),
            Some(&Bson::Binary(Binary { subtype: BinarySubtype::Uuid, bytes: vec![1, 2, 3] }))
        );

        let Some(Bson::RegularExpression(regex)) = document.get("regex") else {
            panic!("expected a regex");
        };
        assert_eq!(regex.pattern.as_str(), "^a");
        assert_eq!(regex.options.as_str(), "i");

        assert_eq!(
            document.get("stamp"),
            Some(&Bson::Timestamp(mongodb::bson::Timestamp { time: 42, increment: 7 }))
        );
        assert_eq!(
            document.get("decimal"),
            Some(&Bson::Decimal128("12.50".parse().unwrap()))
        );
        assert_eq!(document.get("min"), Some(&Bson::MinKey));
        assert_eq!(document.get("max"), Some(&Bson::MaxKey));
        // An Int64 object keeps its width even when the number fits an int32.
        assert_eq!(document.get("long"), Some(&Bson::Int64(7)));
    }

    #[test]
    fn javascript_with_and_without_a_scope() {
        let plain = Value::object(classes::JAVASCRIPT, vec![("code", "f()".into()), ("scope", Value::Nil)]);
        let empty_scope =
            Value::object(classes::JAVASCRIPT, vec![("code", "f()".into()), ("scope", Value::List(vec![]))]);
        let scoped = Value::object(
            classes::JAVASCRIPT,
            vec![("code", "g()".into()), ("scope", Map::new().set("x", 1_i64).into())],
        );

        assert_eq!(bson_from_value(&plain).unwrap(), Bson::JavaScriptCode("f()".to_string()));
        assert_eq!(bson_from_value(&empty_scope).unwrap(), Bson::JavaScriptCode("f()".to_string()));
        assert_eq!(
            bson_from_value(&scoped).unwrap(),
            Bson::JavaScriptCodeWithScope(JavaScriptCodeWithScope {
                code: "g()".to_string(),
                scope: doc! { "x": 1_i32 },
            })
        );
    }

    #[test]
    fn a_string_that_is_not_utf8_becomes_generic_binary() {
        let value: Value = Map::new().set("blob", Value::Str(vec![0xff, 0xfe])).into();

        assert_eq!(
            document_from_value(&value).unwrap(),
            doc! { "blob": Binary { subtype: BinarySubtype::Generic, bytes: vec![0xff, 0xfe] } }
        );
    }

    #[test]
    fn a_list_of_documents() {
        let value = Value::List(vec![
            Map::new().set("a", 1_i64).into(),
            Map::new().set("b", "x").into(),
        ]);

        assert_eq!(
            documents_from_value(&value).unwrap(),
            vec![doc! { "a": 1_i32 }, doc! { "b": "x" }]
        );
        assert_eq!(documents_from_value(&Value::Nil).unwrap(), Vec::<Document>::new());
        assert_eq!(documents_from_value(&Value::List(vec![])).unwrap(), Vec::<Document>::new());
        assert_eq!(
            documents_from_value(&Map::new().set("a", 1_i64).into()).unwrap_err(),
            "expected an array of documents"
        );
    }

    #[test]
    fn an_unknown_class_is_an_error() {
        let value: Value = Map::new()
            .set("x", Value::object(r"App\Money", vec![("amount", Value::Int(1))]))
            .into();

        assert_eq!(
            document_from_value(&value).unwrap_err(),
            r#"unsupported object class "App\Money""#
        );
    }

    #[test]
    fn a_document_cannot_be_a_value_object() {
        let value = Value::object(classes::MIN_KEY, vec![]);

        assert_eq!(
            document_from_value(&value).unwrap_err(),
            format!("a document cannot be a {}", classes::MIN_KEY)
        );
    }

    #[test]
    fn a_missing_property_is_an_error() {
        let value = Value::object(classes::OBJECT_ID, vec![]);

        assert_eq!(
            bson_from_value(&value).unwrap_err(),
            format!("{} is missing property oid", classes::OBJECT_ID)
        );
    }

    #[test]
    fn a_property_of_the_wrong_type_is_an_error() {
        let date = Value::object(classes::UTC_DATE_TIME, vec![("epochMs", "soon".into())]);
        let binary = Value::object(classes::BINARY, vec![("data", Value::Int(1)), ("subType", Value::Int(0))]);
        let scoped = Value::object(
            classes::JAVASCRIPT,
            vec![("code", "f()".into()), ("scope", Value::List(vec![Value::Int(1)]))],
        );

        assert_eq!(
            bson_from_value(&date).unwrap_err(),
            format!("{} property epochMs is not an integer", classes::UTC_DATE_TIME)
        );
        assert_eq!(
            bson_from_value(&binary).unwrap_err(),
            format!("{} property data is not a string", classes::BINARY)
        );
        assert_eq!(
            bson_from_value(&scoped).unwrap_err(),
            "Javascript scope must be a map, got a list"
        );
    }

    #[test]
    fn a_property_out_of_range_is_an_error() {
        let stamp = Value::object(
            classes::TIMESTAMP,
            vec![("increment", Value::Int(-1)), ("epochSeconds", Value::Int(42))],
        );
        let binary = Value::object(classes::BINARY, vec![("data", "x".into()), ("subType", Value::Int(300))]);

        assert_eq!(
            bson_from_value(&stamp).unwrap_err(),
            format!("{} property increment is out of range: -1", classes::TIMESTAMP)
        );
        assert_eq!(bson_from_value(&binary).unwrap_err(), "Binary subType 300 out of range");
    }
}
