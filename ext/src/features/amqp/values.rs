//! The field table in both
//! directions.
//!
//! An AMQP field table — queue and exchange arguments, message headers —
//! carries kinds a PHP array has no equivalent for. Two of them matter, and
//! both are tagged rather than flattened: a decimal written as a float and a
//! timestamp written as an integer would change the type of a header for every
//! other client reading the same queue, and would not come back to PHP as the
//! object it was sent as.
//!
//! PHP: SConcur\Features\Amqp\Support\TableCodec.

use lapin::types::{AMQPValue, DecimalValue, FieldArray, FieldTable, LongString, ShortString};

use crate::dto::{Key, Map, Value};

/// Names the kind of a tagged value. It starts with a NUL byte, which no AMQP
/// field name may contain, so an application's own header can never be mistaken
/// for one.
const TAGGED_KIND: &str = "\u{0}amqp";
/// An AMQP decimal: significand scaled down by 10^exponent.
const TAGGED_DECIMAL: &str = "D";
/// An AMQP timestamp, in seconds since the Unix epoch.
const TAGGED_TIMESTAMP: &str = "T";
/// The fields the two kinds carry.
const TAGGED_EXPONENT: &str = "e";
const TAGGED_SIGNIFICAND: &str = "s";
const TAGGED_VALUE: &str = "v";

/// Turns the arguments and headers PHP sent into an AMQP field table.
///
/// The values are what a PHP array can hold: null, bool, int, float, string
/// (binary payloads included: PHP has one string type for both), and nested
/// arrays. An empty table answers `None`, so a command that was given no
/// arguments sends none rather than an empty one.
pub fn table_from_value(value: Option<&Value>) -> Option<FieldTable> {
    // An empty PHP array arrives as an empty list, and `as_map` reads it as an
    // empty map. A non-empty list is not a field table and is dropped rather
    // than guessed at.
    let entries = value?.as_map()?;

    if entries.is_empty() {
        return None;
    }

    Some(table_from_entries(entries))
}

fn table_from_entries(entries: &[(Key, Value)]) -> FieldTable {
    let mut table = FieldTable::default();

    for (key, item) in entries {
        table.insert(ShortString::from(table_key(key)), to_amqp_value(item));
    }

    table
}

/// AMQP field names are strings, so a numeric PHP key — which stays numeric
/// however PHP is asked to write it — is rendered as its decimal form.
fn table_key(key: &Key) -> String {
    key.text()
}

fn to_amqp_value(value: &Value) -> AMQPValue {
    match value {
        Value::Nil => AMQPValue::Void,
        Value::Bool(flag) => AMQPValue::Boolean(*flag),
        Value::Int(number) => AMQPValue::LongLongInt(*number),
        Value::Float(number) => AMQPValue::Double(*number),
        // Binary-safe: a PHP string holding bytes that are not valid UTF-8 is
        // still one string on the wire, and LongString carries bytes.
        Value::Str(bytes) => AMQPValue::LongString(LongString::from(bytes.as_slice())),
        Value::Map(entries) => match tagged_value_of(entries) {
            Some(tagged) => tagged,
            None => AMQPValue::FieldTable(table_from_entries(entries)),
        },
        Value::List(items) => {
            let mut array = FieldArray::default();

            for item in items {
                array.push(to_amqp_value(item));
            }

            AMQPValue::FieldArray(array)
        }
        // A value object PHP's TableCodec did not translate: its public
        // properties travel as a nested table, the way a stdClass does.
        Value::Object(object) => {
            let mut table = FieldTable::default();

            for (name, item) in &object.properties {
                table.insert(ShortString::from(name.to_string()), to_amqp_value(item));
            }

            AMQPValue::FieldTable(table)
        }
    }
}

/// Recognizes the map a Decimal or a Timestamp was encoded into and rebuilds
/// the AMQP field value it stands for.
fn tagged_value_of(entries: &[(Key, Value)]) -> Option<AMQPValue> {
    let kind = lookup(entries, TAGGED_KIND)?;

    match kind.as_str()? {
        TAGGED_DECIMAL => Some(AMQPValue::DecimalValue(DecimalValue {
            scale: int_from_value(lookup(entries, TAGGED_EXPONENT)) as u8,
            value: int_from_value(lookup(entries, TAGGED_SIGNIFICAND)) as u32,
        })),
        TAGGED_TIMESTAMP => Some(AMQPValue::Timestamp(
            int_from_value(lookup(entries, TAGGED_VALUE)) as u64,
        )),
        _ => None,
    }
}

fn lookup<'a>(entries: &'a [(Key, Value)], name: &str) -> Option<&'a Value> {
    entries
        .iter()
        .find(|(key, _)| matches!(key, Key::Str(bytes) if bytes == name.as_bytes()))
        .map(|(_, value)| value)
}

/// Reads back an integer whether PHP sent it as an int or as a float.
fn int_from_value(value: Option<&Value>) -> i64 {
    match value {
        Some(Value::Int(number)) => *number,
        Some(Value::Float(number)) => *number as i64,
        _ => 0,
    }
}

/// Turns an AMQP field table into the associative array PHP reads back. A
/// decimal and a timestamp keep their kind, in the tagged shape `TableCodec`
/// turns back into a `Decimal` and a `Timestamp`.
pub fn table_value(table: &FieldTable) -> Value {
    let entries = table.inner();
    let mut map = Map::with_capacity(entries.len());

    for (name, value) in entries {
        map.push(name.as_str(), field_value(value));
    }

    map.into()
}

fn field_value(value: &AMQPValue) -> Value {
    match value {
        AMQPValue::Boolean(flag) => Value::Bool(*flag),
        AMQPValue::ShortShortInt(number) => Value::Int(*number as i64),
        AMQPValue::ShortShortUInt(number) => Value::Int(*number as i64),
        AMQPValue::ShortInt(number) => Value::Int(*number as i64),
        AMQPValue::ShortUInt(number) => Value::Int(*number as i64),
        AMQPValue::LongInt(number) => Value::Int(*number as i64),
        AMQPValue::LongUInt(number) => Value::Int(*number as i64),
        AMQPValue::LongLongInt(number) => Value::Int(*number),
        AMQPValue::Float(number) => Value::Float(*number as f64),
        AMQPValue::Double(number) => Value::Float(*number),
        AMQPValue::DecimalValue(decimal) => Map::with_capacity(3)
            .set(TAGGED_KIND, TAGGED_DECIMAL)
            .set(TAGGED_EXPONENT, decimal.scale as i64)
            // Widened through u32, which is the type the field actually
            // carries: an AMQP decimal's significand is unsigned, and anything
            // at or above 2^31 must not arrive at PHP as a negative number it
            // refuses to build a Decimal from.
            .set(TAGGED_SIGNIFICAND, decimal.value as i64)
            .into(),
        AMQPValue::Timestamp(seconds) => Map::with_capacity(2)
            .set(TAGGED_KIND, TAGGED_TIMESTAMP)
            .set(TAGGED_VALUE, *seconds as i64)
            .into(),
        AMQPValue::ShortString(text) => Value::str(text.as_str()),
        // A PHP string whatever bytes it holds: PHP reads a header value as a
        // plain string, including one that is not valid UTF-8.
        AMQPValue::LongString(text) => Value::str(text.as_bytes()),
        AMQPValue::FieldArray(items) => {
            Value::List(items.as_slice().iter().map(field_value).collect())
        }
        AMQPValue::FieldTable(table) => table_value(table),
        AMQPValue::ByteArray(bytes) => Value::str(bytes.as_slice()),
        AMQPValue::Void => Value::Nil,
    }
}
