//! Mirrors ext-go-legacy/internal/features/amqp/values.go: the field table in both
//! directions.
//!
//! An AMQP field table — queue and exchange arguments, message headers —
//! carries kinds MessagePack has no equivalent for. Two of them matter, and
//! both are tagged rather than flattened: a decimal written as a float and a
//! timestamp written as an integer would change the type of a header for every
//! other client reading the same queue, and would not come back to PHP as the
//! object it was sent as.
//!
//! PHP: SConcur\Features\Amqp\Support\TableCodec.

use lapin::types::{AMQPValue, DecimalValue, FieldArray, FieldTable, LongString, ShortString};
use rmp::encode;

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
/// What the values can be is settled by what `ext-msgpack` writes for a PHP
/// array: nil, bool, string (binary payloads included — PHP packs them as str),
/// integers of every width, floats, and nested maps and lists. An empty table
/// answers `None`, so a command that was given no arguments sends none rather
/// than an empty one.
pub fn table_from_msgpack(value: Option<&rmpv::Value>) -> Option<FieldTable> {
    let entries = match value {
        Some(rmpv::Value::Map(entries)) => entries,
        // A PHP empty array packs as an empty MessagePack array, not as an
        // empty map — the same asymmetry the MongoDB serializer handles. A
        // non-empty array is not a field table and is dropped rather than
        // guessed at.
        _ => return None,
    };

    if entries.is_empty() {
        return None;
    }

    let mut table = FieldTable::default();

    for (key, item) in entries {
        table.insert(ShortString::from(table_key(key)), to_amqp_value(item));
    }

    Some(table)
}

/// AMQP field names are strings, so a numeric PHP key — which stays numeric
/// however PHP is asked to write it — is rendered as its decimal form.
fn table_key(key: &rmpv::Value) -> String {
    match key {
        rmpv::Value::String(text) => match text.as_str() {
            Some(text) => text.to_string(),
            None => String::from_utf8_lossy(text.as_bytes()).into_owned(),
        },
        rmpv::Value::Integer(number) => number.to_string(),
        rmpv::Value::F32(number) => format_float(*number as f64),
        rmpv::Value::F64(number) => format_float(*number),
        other => other.to_string(),
    }
}

/// Go renders a float key with `strconv.FormatFloat(_, 'f', -1, 64)`, which
/// writes the shortest form that round-trips and never an exponent.
fn format_float(number: f64) -> String {
    if number == number.trunc() && number.abs() < 1e15 {
        return format!("{}", number as i64);
    }

    format!("{number}")
}

fn to_amqp_value(value: &rmpv::Value) -> AMQPValue {
    match value {
        rmpv::Value::Nil => AMQPValue::Void,
        rmpv::Value::Boolean(flag) => AMQPValue::Boolean(*flag),
        rmpv::Value::Integer(number) => match number.as_i64() {
            Some(number) => AMQPValue::LongLongInt(number),
            // Past i64 there is no AMQP integer field wide enough; the driver
            // refuses an unsigned 64-bit field, so it travels as text rather
            // than wrapping into a negative number.
            None => AMQPValue::LongString(LongString::from(number.to_string())),
        },
        rmpv::Value::F32(number) => AMQPValue::Double(*number as f64),
        rmpv::Value::F64(number) => AMQPValue::Double(*number),
        // Binary-safe: a PHP string holding bytes that are not valid UTF-8 is
        // still one string on the wire, and LongString carries bytes.
        rmpv::Value::String(text) => AMQPValue::LongString(LongString::from(text.as_bytes())),
        rmpv::Value::Binary(bytes) => AMQPValue::LongString(LongString::from(bytes.as_slice())),
        rmpv::Value::Map(entries) => match tagged_value_of(entries) {
            Some(tagged) => tagged,
            None => {
                let mut table = FieldTable::default();

                for (key, item) in entries {
                    table.insert(ShortString::from(table_key(key)), to_amqp_value(item));
                }

                AMQPValue::FieldTable(table)
            }
        },
        rmpv::Value::Array(items) => {
            let mut array = FieldArray::default();

            for item in items {
                array.push(to_amqp_value(item));
            }

            AMQPValue::FieldArray(array)
        }
        rmpv::Value::Ext(_, bytes) => AMQPValue::LongString(LongString::from(bytes.as_slice())),
    }
}

/// Recognizes the map a Decimal or a Timestamp was encoded into and rebuilds
/// the AMQP field value it stands for.
fn tagged_value_of(entries: &[(rmpv::Value, rmpv::Value)]) -> Option<AMQPValue> {
    let kind = lookup(entries, TAGGED_KIND)?;

    match text_of(kind)?.as_str() {
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

fn lookup<'a>(entries: &'a [(rmpv::Value, rmpv::Value)], name: &str) -> Option<&'a rmpv::Value> {
    entries
        .iter()
        .find(|(key, _)| matches!(key, rmpv::Value::String(text) if text.as_str() == Some(name)))
        .map(|(_, value)| value)
}

fn text_of(value: &rmpv::Value) -> Option<String> {
    match value {
        rmpv::Value::String(text) => text.as_str().map(|text| text.to_string()),
        _ => None,
    }
}

/// Reads back an integer whichever of the numeric types `ext-msgpack` landed it
/// in.
fn int_from_value(value: Option<&rmpv::Value>) -> i64 {
    match value {
        Some(rmpv::Value::Integer(number)) => number
            .as_i64()
            .or_else(|| number.as_u64().map(|number| number as i64))
            .unwrap_or(0),
        Some(rmpv::Value::F32(number)) => *number as i64,
        Some(rmpv::Value::F64(number)) => *number as i64,
        _ => 0,
    }
}

/// Writes an AMQP field table as the MessagePack map PHP reads back. A decimal
/// and a timestamp keep their kind, in the tagged shape `TableCodec` turns back
/// into a `Decimal` and a `Timestamp`.
pub fn encode_table(buffer: &mut Vec<u8>, table: &FieldTable) {
    let entries = table.inner();

    encode::write_map_len(buffer, entries.len() as u32).ok();

    for (name, value) in entries {
        write_str(buffer, name.as_str());
        encode_value(buffer, value);
    }
}

/// The table of a message that carries none. PHP reads a missing `hd` key and
/// an empty map the same way, and an empty map is what Go's `omitempty` on a
/// nil table produces once the key is written at all.
pub fn encode_empty_table(buffer: &mut Vec<u8>) {
    encode::write_map_len(buffer, 0).ok();
}

fn encode_value(buffer: &mut Vec<u8>, value: &AMQPValue) {
    match value {
        AMQPValue::Boolean(flag) => {
            encode::write_bool(buffer, *flag).ok();
        }
        AMQPValue::ShortShortInt(number) => write_int(buffer, *number as i64),
        AMQPValue::ShortShortUInt(number) => write_int(buffer, *number as i64),
        AMQPValue::ShortInt(number) => write_int(buffer, *number as i64),
        AMQPValue::ShortUInt(number) => write_int(buffer, *number as i64),
        AMQPValue::LongInt(number) => write_int(buffer, *number as i64),
        AMQPValue::LongUInt(number) => write_int(buffer, *number as i64),
        AMQPValue::LongLongInt(number) => write_int(buffer, *number),
        AMQPValue::Float(number) => {
            encode::write_f64(buffer, *number as f64).ok();
        }
        AMQPValue::Double(number) => {
            encode::write_f64(buffer, *number).ok();
        }
        AMQPValue::DecimalValue(decimal) => {
            encode::write_map_len(buffer, 3).ok();
            write_str(buffer, TAGGED_KIND);
            write_str(buffer, TAGGED_DECIMAL);
            write_str(buffer, TAGGED_EXPONENT);
            write_int(buffer, decimal.scale as i64);
            write_str(buffer, TAGGED_SIGNIFICAND);
            // Widened through u32, which is the type the field actually
            // carries: an AMQP decimal's significand is unsigned, and anything
            // at or above 2^31 must not arrive at PHP as a negative number it
            // refuses to build a Decimal from.
            write_int(buffer, decimal.value as i64);
        }
        AMQPValue::Timestamp(seconds) => {
            encode::write_map_len(buffer, 2).ok();
            write_str(buffer, TAGGED_KIND);
            write_str(buffer, TAGGED_TIMESTAMP);
            write_str(buffer, TAGGED_VALUE);
            write_int(buffer, *seconds as i64);
        }
        AMQPValue::ShortString(text) => write_str(buffer, text.as_str()),
        AMQPValue::LongString(text) => write_bytes_as_str(buffer, text.as_bytes()),
        AMQPValue::FieldArray(items) => {
            let items = items.as_slice();

            encode::write_array_len(buffer, items.len() as u32).ok();

            for item in items {
                encode_value(buffer, item);
            }
        }
        AMQPValue::FieldTable(table) => encode_table(buffer, table),
        AMQPValue::ByteArray(bytes) => write_bytes_as_str(buffer, bytes.as_slice()),
        AMQPValue::Void => {
            encode::write_nil(buffer).ok();
        }
    }
}

fn write_int(buffer: &mut Vec<u8>, number: i64) {
    encode::write_sint(buffer, number).ok();
}

fn write_str(buffer: &mut Vec<u8>, text: &str) {
    encode::write_str(buffer, text).ok();
}

/// A MessagePack *str*, not *bin*: PHP reads a header value as a plain string,
/// and that is what Go's `string([]byte)` becomes on the wire — including for
/// bytes that are not valid UTF-8.
fn write_bytes_as_str(buffer: &mut Vec<u8>, bytes: &[u8]) {
    encode::write_str_len(buffer, bytes.len() as u32).ok();
    buffer.extend_from_slice(bytes);
}
