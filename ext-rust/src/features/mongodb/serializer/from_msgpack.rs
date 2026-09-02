//! MessagePack -> BSON. Mirrors ext/internal/features/mongodb/serializer/msgpack_values.go.
//!
//! Go streams the bytes straight into a BSON buffer. Here the payload is decoded
//! into an `rmpv::Value` tree first and walked afterwards. That costs one
//! intermediate allocation per document which the Go side does not pay — a real
//! difference, named rather than hidden — and buys the thing that matters most
//! for this converter: the container counter below is trivially correct on a
//! tree walk, where on a hand-rolled streaming decoder it is the single easiest
//! thing in the whole feature to get subtly, silently wrong.

use mongodb::bson::{Binary, Bson, DateTime, Decimal128, Document, JavaScriptCodeWithScope};
use mongodb::bson::spec::BinarySubtype;
use rmpv::Value;
use std::collections::HashMap;
use std::str::FromStr;

use super::classes;

/// The value ext-msgpack writes under the nil key instead of a class name when
/// the same object instance appears more than once in one payload: the repeat is
/// encoded as `{nil: 4, 0: <index>}` rather than repeating the object.
const REFERENCE_MARKER: i64 = 4;

pub type Error = String;

/// Decodes the outermost document of a payload.
pub fn document_from_msgpack(data: &[u8]) -> Result<Document, Error> {
    if data.is_empty() {
        return Ok(Document::new());
    }

    let value = read_value(data)?;
    let mut converter = Converter::new();

    converter.document_from(&value)
}

/// Decodes an array of documents (insertMany, and the pipeline's stages).
pub fn documents_from_msgpack(data: &[u8]) -> Result<Vec<Document>, Error> {
    if data.is_empty() {
        return Ok(Vec::new());
    }

    let value = read_value(data)?;
    let mut converter = Converter::new();

    let Value::Array(items) = &value else {
        return Err("expected an array of documents".to_string());
    };

    // The array itself is a container and takes a number, exactly as it does in
    // the streaming decoder.
    converter.next_index();

    items
        .iter()
        .map(|item| converter.document_from_walked(item))
        .collect()
}

/// A pipeline is an array of stage documents; kept as its own entry point
/// because that is how the Go side names it.
pub fn pipeline_from_msgpack(data: &[u8]) -> Result<Vec<Document>, Error> {
    documents_from_msgpack(data)
}

/// Converts a whole payload under one shared container counter.
///
/// The counter cannot be restarted per field: ext-msgpack numbers every
/// container it writes across the entire blob, and an object reference names one
/// of those numbers. Converting each field with a fresh converter would resolve
/// a reference to the wrong object — or to none at all.
pub struct PayloadConverter {
    inner: Converter,
}

impl PayloadConverter {
    pub fn new() -> Self {
        PayloadConverter {
            inner: Converter::new(),
        }
    }

    /// Accounts for a container the caller has already unwrapped — the params
    /// map itself — so the numbering stays in step.
    pub fn count_container(&mut self) {
        self.inner.next_index();
    }

    pub fn value(&mut self, value: &Value) -> Result<Bson, Error> {
        self.inner.value_from(value)
    }
}

pub fn read_payload_value(data: &[u8]) -> Result<Value, Error> {
    read_value(data)
}

fn read_value(data: &[u8]) -> Result<Value, Error> {
    let mut cursor = std::io::Cursor::new(data);

    rmpv::decode::read_value(&mut cursor).map_err(|error| format!("invalid MessagePack: {error}"))
}

/// A decoded object envelope, kept so a later reference to the same instance can
/// be resolved.
#[derive(Clone)]
struct ObjectEnvelope {
    class: String,
    properties: Vec<(String, Value)>,
}

impl ObjectEnvelope {
    fn property(&self, name: &str) -> Option<&Value> {
        self.properties
            .iter()
            .find(|(key, _)| key == name)
            .map(|(_, value)| value)
    }
}

/// Carries the state one payload's decoding needs: the object index that makes
/// references resolvable. ext-msgpack numbers every container it writes — a map,
/// an array, an object, and a reference itself — from 1, and a reference names
/// one of those numbers, so the walk has to count in exactly the same order.
struct Converter {
    counter: i64,
    objects: HashMap<i64, ObjectEnvelope>,
}

impl Converter {
    fn new() -> Self {
        Converter {
            counter: 0,
            objects: HashMap::new(),
        }
    }

    fn next_index(&mut self) -> i64 {
        self.counter += 1;

        self.counter
    }

    /// The top-level document of a payload, whose own container number is taken
    /// here.
    fn document_from(&mut self, value: &Value) -> Result<Document, Error> {
        self.document_from_walked(value)
    }

    fn document_from_walked(&mut self, value: &Value) -> Result<Document, Error> {
        match value {
            Value::Map(pairs) => {
                let index = self.next_index();

                if let Some(envelope) = self.read_envelope(index, pairs)? {
                    // A stdClass envelope is a document too — that is what a
                    // plain PHP object packs as — and any other envelope is a
                    // value, not a document.
                    if envelope.class != classes::STD_CLASS {
                        return Err(format!("a document cannot be a {}", envelope.class));
                    }

                    return self.document_from_pairs(&envelope.properties);
                }

                let mut document = Document::new();

                for (key, item) in pairs {
                    let name = map_key(key)?;
                    let converted = self.value_from(item)?;

                    document.insert(name, converted);
                }

                Ok(document)
            }
            // A PHP list packs as an array; BSON keys it by position, which is
            // what the driver expects for a document built from a list.
            Value::Array(items) => {
                self.next_index();

                let mut document = Document::new();

                for (position, item) in items.iter().enumerate() {
                    document.insert(position.to_string(), self.value_from(item)?);
                }

                Ok(document)
            }
            Value::Nil => Ok(Document::new()),
            other => Err(format!("expected a document, got {}", describe(other))),
        }
    }

    fn document_from_pairs(&mut self, pairs: &[(String, Value)]) -> Result<Document, Error> {
        let mut document = Document::new();

        for (key, value) in pairs {
            document.insert(key.clone(), self.value_from(value)?);
        }

        Ok(document)
    }

    fn value_from(&mut self, value: &Value) -> Result<Bson, Error> {
        match value {
            Value::Nil => Ok(Bson::Null),
            Value::Boolean(flag) => Ok(Bson::Boolean(*flag)),
            Value::Integer(number) => match number.as_i64() {
                Some(number) => Ok(int_to_bson(number)),
                None => match number.as_u64() {
                    // Past i64 the value cannot be a BSON integer; the driver
                    // has no unsigned type, so it goes as a double the way any
                    // out-of-range number would.
                    Some(number) => Ok(Bson::Double(number as f64)),
                    None => Err("unrepresentable integer".to_string()),
                },
            },
            Value::F32(number) => Ok(Bson::Double(*number as f64)),
            Value::F64(number) => Ok(Bson::Double(*number)),
            Value::String(text) => match text.as_str() {
                Some(text) => Ok(Bson::String(text.to_string())),
                // A PHP string that is not valid UTF-8 cannot be a BSON string;
                // it becomes generic binary, which is what the bytes are.
                None => Ok(Bson::Binary(Binary {
                    subtype: BinarySubtype::Generic,
                    bytes: text.as_bytes().to_vec(),
                })),
            },
            Value::Binary(bytes) => Ok(Bson::Binary(Binary {
                subtype: BinarySubtype::Generic,
                bytes: bytes.clone(),
            })),
            Value::Array(items) => {
                self.next_index();

                let mut array = Vec::with_capacity(items.len());

                for item in items {
                    array.push(self.value_from(item)?);
                }

                Ok(Bson::Array(array))
            }
            Value::Map(pairs) => {
                let index = self.next_index();

                match self.read_envelope(index, pairs)? {
                    Some(envelope) => self.bson_from_envelope(&envelope),
                    None => {
                        let mut document = Document::new();

                        for (key, item) in pairs {
                            let name = map_key(key)?;
                            let converted = self.value_from(item)?;

                            document.insert(name, converted);
                        }

                        Ok(Bson::Document(document))
                    }
                }
            }
            Value::Ext(..) => Err("MessagePack extension types are not supported".to_string()),
        }
    }

    /// Recognises an object envelope — ext-msgpack marks one by making the nil
    /// key its first — and returns it, resolving a repeat to the instance
    /// recorded earlier under the same payload.
    fn read_envelope(
        &mut self,
        index: i64,
        pairs: &[(Value, Value)],
    ) -> Result<Option<ObjectEnvelope>, Error> {
        let Some((first_key, first_value)) = pairs.first() else {
            return Ok(None);
        };

        if !matches!(first_key, Value::Nil) {
            return Ok(None);
        }

        // A reference names an earlier container by number instead of a class.
        if let Value::Integer(marker) = first_value {
            let marker = marker.as_i64().unwrap_or(-1);

            if marker != REFERENCE_MARKER {
                return Err(format!("unsupported object marker {marker}"));
            }

            let target = pairs
                .iter()
                .skip(1)
                .find_map(|(key, value)| match (map_key(key), value) {
                    (Ok(name), Value::Integer(number)) if name == "0" => number.as_i64(),
                    _ => None,
                })
                .ok_or_else(|| "object reference without a target".to_string())?;

            return self
                .objects
                .get(&target)
                .cloned()
                .map(Some)
                .ok_or_else(|| format!("reference to an unknown object {target}"));
        }

        let Value::String(class) = first_value else {
            return Err("object envelope without a class name".to_string());
        };

        let class = class
            .as_str()
            .ok_or_else(|| "object class is not valid UTF-8".to_string())?
            .to_string();

        let mut properties = Vec::with_capacity(pairs.len() - 1);

        for (key, value) in pairs.iter().skip(1) {
            properties.push((map_key(key)?, value.clone()));
        }

        let envelope = ObjectEnvelope { class, properties };

        self.objects.insert(index, envelope.clone());

        Ok(Some(envelope))
    }

    /// Writes the BSON value an object envelope names.
    fn bson_from_envelope(&mut self, envelope: &ObjectEnvelope) -> Result<Bson, Error> {
        match envelope.class.as_str() {
            classes::STD_CLASS => Ok(Bson::Document(
                self.document_from_pairs(&envelope.properties)?,
            )),
            classes::OBJECT_ID => {
                let hexadecimal = property_string(envelope, "oid")?;

                mongodb::bson::oid::ObjectId::parse_str(&hexadecimal)
                    .map(Bson::ObjectId)
                    .map_err(|error| format!("invalid ObjectId: {error}"))
            }
            classes::UTC_DATE_TIME => Ok(Bson::DateTime(DateTime::from_millis(property_int(
                envelope, "epochMs",
            )?))),
            classes::BINARY => {
                let subtype = property_int(envelope, "subType")?;

                if !(0..=u8::MAX as i64).contains(&subtype) {
                    return Err(format!("Binary subType {subtype} out of range"));
                }

                Ok(Bson::Binary(Binary {
                    subtype: BinarySubtype::from(subtype as u8),
                    bytes: property_bytes(envelope, "data")?,
                }))
            }
            // A BSON regex is stored as two C strings, so neither half may
            // hold an interior NUL — the conversion is where that is caught.
            classes::REGEX => {
                let pattern = cstring(property_string(envelope, "pattern")?, "Regex pattern")?;
                let options = cstring(property_string(envelope, "flags")?, "Regex flags")?;

                Ok(Bson::RegularExpression(mongodb::bson::Regex {
                    pattern,
                    options,
                }))
            }
            classes::TIMESTAMP => {
                let seconds = property_range(envelope, "epochSeconds", u32::MAX as i64)?;
                let increment = property_range(envelope, "increment", u32::MAX as i64)?;

                Ok(Bson::Timestamp(mongodb::bson::Timestamp {
                    time: seconds as u32,
                    increment: increment as u32,
                }))
            }
            classes::DECIMAL128 => {
                let value = property_string(envelope, "value")?;

                Decimal128::from_str(&value)
                    .map(Bson::Decimal128)
                    .map_err(|error| format!("invalid Decimal128: {error:?}"))
            }
            classes::JAVASCRIPT => self.javascript_from(envelope),
            classes::MIN_KEY => Ok(Bson::MinKey),
            classes::MAX_KEY => Ok(Bson::MaxKey),
            classes::INT64 => Ok(Bson::Int64(property_int(envelope, "value")?)),
            other => Err(format!("unsupported object class \"{other}\"")),
        }
    }

    /// A plain javascript element, or a code-with-scope one when the object
    /// carries a non-empty scope.
    fn javascript_from(&mut self, envelope: &ObjectEnvelope) -> Result<Bson, Error> {
        let code = property_string(envelope, "code")?;

        let scope = match envelope.property("scope") {
            None | Some(Value::Nil) => None,
            Some(Value::Map(pairs)) if pairs.is_empty() => None,
            Some(Value::Map(pairs)) => {
                // The scope map is itself a container: Go counts it when
                // readObject decodes the property, and missing it here shifts
                // every later object reference by one.
                self.next_index();

                let mut document = Document::new();

                for (key, value) in pairs {
                    let name = map_key(key)?;
                    let converted = self.value_from(value)?;

                    document.insert(name, converted);
                }

                Some(document)
            }
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
fn map_key(value: &Value) -> Result<String, Error> {
    match value {
        Value::String(text) => text
            .as_str()
            .map(str::to_string)
            .ok_or_else(|| "map key is not valid UTF-8".to_string()),
        Value::Integer(number) => Ok(number.to_string()),
        other => Err(format!("unsupported map key {}", describe(other))),
    }
}

fn property_string(envelope: &ObjectEnvelope, name: &str) -> Result<String, Error> {
    match envelope.property(name) {
        Some(Value::String(text)) => text
            .as_str()
            .map(str::to_string)
            .ok_or_else(|| format!("{} property {name} is not valid UTF-8", envelope.class)),
        Some(Value::Integer(number)) => Ok(number.to_string()),
        _ => Err(format!("{} is missing property {name}", envelope.class)),
    }
}

fn property_int(envelope: &ObjectEnvelope, name: &str) -> Result<i64, Error> {
    match envelope.property(name) {
        Some(Value::Integer(number)) => number
            .as_i64()
            .ok_or_else(|| format!("{} property {name} is out of range", envelope.class)),
        // PHP may hand an integer-valued float here.
        Some(Value::F64(number)) => Ok(*number as i64),
        Some(Value::String(text)) => text
            .as_str()
            .and_then(|text| text.parse::<i64>().ok())
            .ok_or_else(|| format!("{} property {name} is not an integer", envelope.class)),
        _ => Err(format!("{} is missing property {name}", envelope.class)),
    }
}

fn property_range(envelope: &ObjectEnvelope, name: &str, maximum: i64) -> Result<i64, Error> {
    let value = property_int(envelope, name)?;

    if value < 0 || value > maximum {
        return Err(format!(
            "{} property {name} is out of range: {value}",
            envelope.class
        ));
    }

    Ok(value)
}

fn property_bytes(envelope: &ObjectEnvelope, name: &str) -> Result<Vec<u8>, Error> {
    match envelope.property(name) {
        Some(Value::Binary(bytes)) => Ok(bytes.clone()),
        Some(Value::String(text)) => Ok(text.as_bytes().to_vec()),
        _ => Err(format!("{} is missing property {name}", envelope.class)),
    }
}

fn cstring(text: String, label: &str) -> Result<mongodb::bson::raw::CString, Error> {
    mongodb::bson::raw::CString::try_from(text)
        .map_err(|error| format!("{label} cannot contain a NUL byte: {error}"))
}

fn describe(value: &Value) -> &'static str {
    match value {
        Value::Nil => "nil",
        Value::Boolean(_) => "a boolean",
        Value::Integer(_) => "an integer",
        Value::F32(_) | Value::F64(_) => "a float",
        Value::String(_) => "a string",
        Value::Binary(_) => "binary data",
        Value::Array(_) => "an array",
        Value::Map(_) => "a map",
        Value::Ext(..) => "an extension type",
    }
}
