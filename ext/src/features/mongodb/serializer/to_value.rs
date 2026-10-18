//! BSON -> the PHP value a result carries.
//!
//! A document becomes an associative array and a BSON value PHP has no scalar
//! for — an id, a date, a decimal — becomes an instance of the matching
//! `SConcur\Bson\*` class, with its declared properties written in place. The
//! class constants and the property names are the contract the PHP classes are
//! read with, so a change here is a change on the PHP side too.

use mongodb::bson::{Bson, Document};

use crate::dto::{Map, Value};

use super::classes;

/// One document as the PHP array it becomes.
pub fn document_value(document: &Document) -> Value {
    // An empty document is an empty PHP array. PHP cannot tell {} from []
    // anyway, and the ext-mongodb path this replaces mapped both to [] as well.
    let mut map = Map::with_capacity(document.len());

    for (key, value) in document {
        map.push(key, bson_value(value));
    }

    map.into()
}

/// A cursor batch as a list of documents.
pub fn documents_value(documents: &[Document]) -> Value {
    Value::List(documents.iter().map(document_value).collect())
}

fn bson_value(value: &Bson) -> Value {
    match value {
        Bson::Double(number) => Value::Float(*number),
        Bson::String(text) => text.into(),
        Bson::Document(document) => document_value(document),
        Bson::Array(items) => Value::List(items.iter().map(bson_value).collect()),
        Bson::Boolean(flag) => Value::Bool(*flag),
        Bson::Null | Bson::Undefined => Value::Nil,
        Bson::Int32(number) => Value::Int(i64::from(*number)),
        // Wrapped, exactly as the native driver hands an int64 to PHP: the type
        // must survive a read-modify-write, and a plain integer would come back
        // as an int32 whenever the value happens to fit.
        Bson::Int64(number) => Value::object(classes::INT64, vec![("value", Value::Int(*number))]),
        Bson::ObjectId(id) => Value::object(classes::OBJECT_ID, vec![("oid", id.to_hex().into())]),
        Bson::DateTime(stamp) => Value::object(
            classes::UTC_DATE_TIME,
            vec![("epochMs", Value::Int(stamp.timestamp_millis()))],
        ),
        Bson::Binary(binary) => Value::object(
            classes::BINARY,
            vec![
                ("data", binary.bytes.as_slice().into()),
                ("subType", Value::Int(i64::from(u8::from(binary.subtype)))),
            ],
        ),
        Bson::RegularExpression(regex) => Value::object(
            classes::REGEX,
            vec![
                ("pattern", regex.pattern.as_str().into()),
                ("flags", regex.options.as_str().into()),
            ],
        ),
        Bson::Timestamp(stamp) => Value::object(
            classes::TIMESTAMP,
            vec![
                ("increment", Value::Int(i64::from(stamp.increment))),
                ("epochSeconds", Value::Int(i64::from(stamp.time))),
            ],
        ),
        Bson::Decimal128(decimal) => {
            Value::object(classes::DECIMAL128, vec![("value", decimal.to_string().into())])
        }
        Bson::JavaScriptCode(code) => Value::object(
            classes::JAVASCRIPT,
            vec![("code", code.into()), ("scope", Value::Nil)],
        ),
        Bson::JavaScriptCodeWithScope(code) => Value::object(
            classes::JAVASCRIPT,
            vec![("code", code.code.as_str().into()), ("scope", document_value(&code.scope))],
        ),
        Bson::MinKey => Value::object(classes::MIN_KEY, vec![]),
        Bson::MaxKey => Value::object(classes::MAX_KEY, vec![]),
        // Deprecated BSON types the driver can still surface. They have no PHP
        // class here, and inventing one would be a wire change; they degrade to
        // the value the type is built on, which is what a reader can use.
        Bson::Symbol(text) => text.into(),
        Bson::DbPointer(_) => Value::Nil,
    }
}

#[cfg(test)]
mod tests {
    use mongodb::bson::raw::CString;
    use mongodb::bson::spec::BinarySubtype;
    use mongodb::bson::{Binary, DateTime, Document, JavaScriptCodeWithScope, Regex, Timestamp, doc, oid::ObjectId};

    use super::*;
    use crate::dto::Key;
    use crate::dto::value::Object;

    fn object_of(value: &Value) -> &Object {
        match value {
            Value::Object(object) => object,
            other => panic!("expected an object, got {other:?}"),
        }
    }

    #[test]
    fn a_document_becomes_an_associative_array_in_field_order() {
        let value = document_value(&doc! { "name": "x", "count": 3_i32, "ratio": 1.5, "flag": true, "none": Bson::Null });

        let entries = value.as_map().expect("a map");

        assert_eq!(entries.len(), 5);
        assert_eq!(entries[0].0, Key::Str(b"name".to_vec()));
        assert_eq!(value.get("name"), Some(&Value::Str(b"x".to_vec())));
        assert_eq!(value.get("count"), Some(&Value::Int(3)));
        assert_eq!(value.get("ratio"), Some(&Value::Float(1.5)));
        assert_eq!(value.get("flag"), Some(&Value::Bool(true)));
        assert_eq!(value.get("none"), Some(&Value::Nil));
    }

    #[test]
    fn an_empty_document_is_an_empty_array() {
        let value = document_value(&Document::new());

        assert_eq!(value, Value::Map(vec![]));
        assert_eq!(
            document_value(&doc! { "scope": {} }).get("scope"),
            Some(&Value::Map(vec![]))
        );
    }

    #[test]
    fn a_batch_is_a_list_of_documents() {
        let value = documents_value(&[doc! { "a": 1_i32 }, doc! { "b": "x" }]);

        let items = value.as_list().expect("a list");

        assert_eq!(items.len(), 2);
        assert_eq!(items[0].get("a"), Some(&Value::Int(1)));
        assert_eq!(items[1].get("b"), Some(&Value::Str(b"x".to_vec())));
        assert_eq!(documents_value(&[]), Value::List(vec![]));
    }

    #[test]
    fn an_object_id_becomes_the_bson_class_with_its_hex() {
        let id = ObjectId::new();

        let value = document_value(&doc! { "_id": id });

        let object = object_of(value.get("_id").expect("an _id"));

        assert_eq!(object.class, classes::OBJECT_ID);
        assert_eq!(object.properties, vec![("oid", Value::Str(id.to_hex().into_bytes()))]);
    }

    #[test]
    fn an_int64_is_wrapped_even_when_it_fits_an_int32() {
        let value = document_value(&doc! { "n": 7_i64, "big": i64::MAX });

        let small = object_of(value.get("n").expect("n"));
        let big = object_of(value.get("big").expect("big"));

        assert_eq!(small.class, classes::INT64);
        assert_eq!(small.properties, vec![("value", Value::Int(7))]);
        assert_eq!(big.properties, vec![("value", Value::Int(i64::MAX))]);
    }

    #[test]
    fn the_other_bson_types_become_their_classes_with_the_declared_properties() {
        let document = doc! {
            "date": DateTime::from_millis(1_700_000_000_123),
            "binary": Binary { subtype: BinarySubtype::Uuid, bytes: vec![1, 2, 3] },
            "regex": Regex {
                pattern: CString::try_from("^a".to_string()).unwrap(),
                options: CString::try_from("i".to_string()).unwrap(),
            },
            "stamp": Timestamp { time: 42, increment: 7 },
            "code": Bson::JavaScriptCode("f()".to_string()),
            "scoped": JavaScriptCodeWithScope { code: "g()".to_string(), scope: doc! { "x": 1_i32 } },
            "min": Bson::MinKey,
            "max": Bson::MaxKey,
        };

        let value = document_value(&document);

        let date = object_of(value.get("date").unwrap());
        assert_eq!(date.class, classes::UTC_DATE_TIME);
        assert_eq!(date.properties, vec![("epochMs", Value::Int(1_700_000_000_123))]);

        let binary = object_of(value.get("binary").unwrap());
        assert_eq!(binary.class, classes::BINARY);
        assert_eq!(
            binary.properties,
            vec![("data", Value::Str(vec![1, 2, 3])), ("subType", Value::Int(4))]
        );

        let regex = object_of(value.get("regex").unwrap());
        assert_eq!(regex.class, classes::REGEX);
        assert_eq!(regex.properties, vec![("pattern", "^a".into()), ("flags", "i".into())]);

        let stamp = object_of(value.get("stamp").unwrap());
        assert_eq!(stamp.class, classes::TIMESTAMP);
        assert_eq!(
            stamp.properties,
            vec![("increment", Value::Int(7)), ("epochSeconds", Value::Int(42))]
        );

        let code = object_of(value.get("code").unwrap());
        assert_eq!(code.class, classes::JAVASCRIPT);
        assert_eq!(code.properties, vec![("code", "f()".into()), ("scope", Value::Nil)]);

        let scoped = object_of(value.get("scoped").unwrap());
        assert_eq!(scoped.properties[0], ("code", "g()".into()));
        assert_eq!(scoped.properties[1].1.get("x"), Some(&Value::Int(1)));

        assert_eq!(object_of(value.get("min").unwrap()).class, classes::MIN_KEY);
        assert!(object_of(value.get("min").unwrap()).properties.is_empty());
        assert_eq!(object_of(value.get("max").unwrap()).class, classes::MAX_KEY);
    }

    #[test]
    fn a_decimal_travels_as_its_text() {
        let decimal: mongodb::bson::Decimal128 = "12.50".parse().expect("a decimal");

        let value = document_value(&doc! { "price": decimal });

        let object = object_of(value.get("price").unwrap());

        assert_eq!(object.class, classes::DECIMAL128);
        assert_eq!(object.properties, vec![("value", "12.50".into())]);
    }

    #[test]
    fn deprecated_types_degrade_to_their_base_value() {
        let value = document_value(&doc! { "symbol": Bson::Symbol("s".to_string()), "nested": [1_i32, "two"] });

        assert_eq!(value.get("symbol"), Some(&Value::Str(b"s".to_vec())));
        assert_eq!(
            value.get("nested"),
            Some(&Value::List(vec![Value::Int(1), "two".into()]))
        );
    }
}
