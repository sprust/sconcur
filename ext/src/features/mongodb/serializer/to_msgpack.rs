//! BSON -> MessagePack.
//!
//! Values BSON has and MessagePack does not are written in the object envelope
//! `ext-msgpack` reads back as a PHP object: a map whose first key is nil and
//! whose value is the class name, followed by property/value pairs. The nil key
//! is unambiguous — a PHP array cannot hold a null key — so the C unpacker
//! recognises the shape while parsing and builds the object at that point,
//! leaving PHP nothing to walk.

use mongodb::bson::{Bson, Document};
use rmp::encode;

use super::classes;

pub type Error = String;

/// Encodes one document for the wire.
pub fn document_to_msgpack(document: &Document) -> Result<Vec<u8>, Error> {
    let mut buffer = Vec::with_capacity(128);

    write_document(&mut buffer, document)?;

    Ok(buffer)
}

/// Encodes a cursor batch as an array of documents.
pub fn documents_to_msgpack(documents: &[Document]) -> Result<Vec<u8>, Error> {
    let mut buffer = Vec::with_capacity(128 * documents.len().max(1));

    encode::write_array_len(&mut buffer, documents.len() as u32).map_err(describe)?;

    for document in documents {
        write_document(&mut buffer, document)?;
    }

    Ok(buffer)
}

fn write_document(buffer: &mut Vec<u8>, document: &Document) -> Result<(), Error> {
    // An empty document goes out as an empty MessagePack array rather than an
    // empty map: ext-msgpack decodes an empty map into a stdClass, while every
    // other document decodes into an array, and a top-level one would then fail
    // DocumentSerializer's array check outright. PHP cannot tell {} from []
    // anyway, and the ext-mongodb path this replaces mapped both to [] as well.
    if document.is_empty() {
        return encode::write_array_len(buffer, 0).map_err(describe).map(|_| ());
    }

    encode::write_map_len(buffer, document.len() as u32).map_err(describe)?;

    for (key, value) in document {
        write_str(buffer, key)?;
        write_value(buffer, value)?;
    }

    Ok(())
}

/// The envelope header: a map of `properties + 1` pairs whose first key is nil
/// and whose value names the class.
fn write_object_header(buffer: &mut Vec<u8>, class: &str, properties: u32) -> Result<(), Error> {
    encode::write_map_len(buffer, properties + 1).map_err(describe)?;
    encode::write_nil(buffer).map_err(describe)?;
    write_str(buffer, class)
}

fn write_value(buffer: &mut Vec<u8>, value: &Bson) -> Result<(), Error> {
    match value {
        Bson::Double(number) => encode::write_f64(buffer, *number).map_err(describe),
        Bson::String(text) => write_str(buffer, text),
        Bson::Document(document) => write_document(buffer, document),
        Bson::Array(items) => {
            encode::write_array_len(buffer, items.len() as u32).map_err(describe)?;

            for item in items {
                write_value(buffer, item)?;
            }

            Ok(())
        }
        Bson::Boolean(flag) => encode::write_bool(buffer, *flag).map_err(|error| format!("{error}")),
        Bson::Null | Bson::Undefined => encode::write_nil(buffer).map_err(describe),
        Bson::Int32(number) => encode::write_sint(buffer, *number as i64).map_err(describe).map(|_| ()),
        // Wrapped, exactly as the native driver hands an int64 to PHP: the type
        // must survive a read-modify-write, and a plain integer would come back
        // as an int32 whenever the value happens to fit.
        Bson::Int64(number) => {
            write_object_header(buffer, classes::INT64, 1)?;
            write_str(buffer, "value")?;
            encode::write_sint(buffer, *number).map_err(describe).map(|_| ())
        }
        Bson::ObjectId(id) => {
            write_object_header(buffer, classes::OBJECT_ID, 1)?;
            write_str(buffer, "oid")?;
            write_str(buffer, &id.to_hex())
        }
        Bson::DateTime(stamp) => {
            write_object_header(buffer, classes::UTC_DATE_TIME, 1)?;
            write_str(buffer, "epochMs")?;
            encode::write_sint(buffer, stamp.timestamp_millis())
                .map_err(describe)
                .map(|_| ())
        }
        Bson::Binary(binary) => {
            write_object_header(buffer, classes::BINARY, 2)?;
            write_str(buffer, "data")?;
            encode::write_bin(buffer, &binary.bytes).map_err(describe)?;
            write_str(buffer, "subType")?;
            encode::write_sint(buffer, u8::from(binary.subtype) as i64)
                .map_err(describe)
                .map(|_| ())
        }
        Bson::RegularExpression(regex) => {
            write_object_header(buffer, classes::REGEX, 2)?;
            write_str(buffer, "pattern")?;
            write_str(buffer, regex.pattern.as_str())?;
            write_str(buffer, "flags")?;
            write_str(buffer, regex.options.as_str())
        }
        Bson::Timestamp(stamp) => {
            write_object_header(buffer, classes::TIMESTAMP, 2)?;
            write_str(buffer, "increment")?;
            encode::write_sint(buffer, stamp.increment as i64).map_err(describe)?;
            write_str(buffer, "epochSeconds")?;
            encode::write_sint(buffer, stamp.time as i64)
                .map_err(describe)
                .map(|_| ())
        }
        Bson::Decimal128(decimal) => {
            write_object_header(buffer, classes::DECIMAL128, 1)?;
            write_str(buffer, "value")?;
            write_str(buffer, &decimal.to_string())
        }
        Bson::JavaScriptCode(code) => {
            write_object_header(buffer, classes::JAVASCRIPT, 2)?;
            write_str(buffer, "code")?;
            write_str(buffer, code)?;
            write_str(buffer, "scope")?;
            encode::write_nil(buffer).map_err(describe)
        }
        Bson::JavaScriptCodeWithScope(code) => {
            write_object_header(buffer, classes::JAVASCRIPT, 2)?;
            write_str(buffer, "code")?;
            write_str(buffer, &code.code)?;
            write_str(buffer, "scope")?;
            write_document(buffer, &code.scope)
        }
        Bson::MinKey => write_object_header(buffer, classes::MIN_KEY, 0),
        Bson::MaxKey => write_object_header(buffer, classes::MAX_KEY, 0),
        // Deprecated BSON types the driver can still surface. They have no PHP
        // class here, and inventing one would be a wire change; they degrade to
        // the value the type is built on, which is what a reader can use.
        Bson::Symbol(text) => write_str(buffer, text),
        Bson::DbPointer(_) => encode::write_nil(buffer).map_err(describe),
    }
}

fn write_str(buffer: &mut Vec<u8>, text: &str) -> Result<(), Error> {
    encode::write_str(buffer, text).map_err(describe)
}

fn describe<E: std::fmt::Display>(error: E) -> Error {
    format!("MessagePack encoding failed: {error}")
}
