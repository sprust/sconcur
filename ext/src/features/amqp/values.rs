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
        // properties travel as a nested table, the way a nested array does.
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
        // Out of range is refused rather than wrapped: the keys are the caller's
        // to write, and a scale of 300 silently becoming 44 is a wrong value on a
        // queue every other client reads.
        TAGGED_DECIMAL => {
            let scale = u8::try_from(int_from_value(lookup(entries, TAGGED_EXPONENT))).ok()?;
            let value = u32::try_from(int_from_value(lookup(entries, TAGGED_SIGNIFICAND))).ok()?;

            Some(AMQPValue::DecimalValue(DecimalValue { scale, value }))
        }
        TAGGED_TIMESTAMP => Some(AMQPValue::Timestamp(
            u64::try_from(int_from_value(lookup(entries, TAGGED_VALUE))).ok()?,
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

#[cfg(test)]
mod tests {
    use super::*;

    fn tagged(kind: &str, fields: Map) -> Value {
        let mut map = Map::new().set(TAGGED_KIND, kind);

        for (key, value) in Value::from(fields).as_map().unwrap() {
            map.push(key.clone(), value.clone());
        }

        map.into()
    }

    /// What TableCodec::encode sends, read back as the AMQP table the broker gets.
    #[test]
    fn a_table_reads_every_kind_a_php_array_holds() {
        let value: Value = Map::new()
            .set("flag", true)
            .set("count", 7i64)
            .set("ratio", 0.5f64)
            .set("name", "n")
            .set("bytes", Value::Str(vec![0, 255]))
            .set("nothing", Value::Nil)
            .set("list", vec![Value::Int(1), Value::Str(b"x".to_vec())])
            .set("nested", Map::new().set("inner", 1i64))
            .set(5usize, "numeric key")
            .set("price", tagged(TAGGED_DECIMAL, Map::new().set(TAGGED_EXPONENT, 2i64).set(TAGGED_SIGNIFICAND, 1999i64)))
            .set("when", tagged(TAGGED_TIMESTAMP, Map::new().set(TAGGED_VALUE, 1_700_000_000i64)))
            .into();

        let table = table_from_value(Some(&value)).expect("a table");
        let inner = table.inner();

        assert!(matches!(inner.get("flag"), Some(AMQPValue::Boolean(true))));
        assert!(matches!(inner.get("count"), Some(AMQPValue::LongLongInt(7))));
        assert!(matches!(inner.get("ratio"), Some(AMQPValue::Double(ratio)) if *ratio == 0.5));
        assert!(matches!(inner.get("name"), Some(AMQPValue::LongString(text)) if text.as_bytes() == b"n"));
        assert!(matches!(inner.get("bytes"), Some(AMQPValue::LongString(text)) if text.as_bytes() == [0, 255]));
        assert!(matches!(inner.get("nothing"), Some(AMQPValue::Void)));
        assert!(matches!(inner.get("list"), Some(AMQPValue::FieldArray(items)) if items.as_slice().len() == 2));
        assert!(matches!(inner.get("nested"), Some(AMQPValue::FieldTable(_))));
        assert!(inner.contains_key("5"), "a numeric PHP key is its decimal text");
        assert!(matches!(inner.get("price"), Some(AMQPValue::DecimalValue(DecimalValue { scale: 2, value: 1999 }))));
        assert!(matches!(inner.get("when"), Some(AMQPValue::Timestamp(1_700_000_000))));
    }

    #[test]
    fn an_empty_or_non_table_value_is_no_table() {
        assert!(table_from_value(None).is_none());
        assert!(table_from_value(Some(&Value::List(vec![]))).is_none());
        assert!(table_from_value(Some(&Value::Map(vec![]))).is_none());
        assert!(table_from_value(Some(&Value::List(vec![Value::Int(1)]))).is_none());
    }

    #[test]
    fn a_tagged_value_out_of_range_is_not_guessed_at() {
        let value: Value = Map::new()
            .set("price", tagged(TAGGED_DECIMAL, Map::new().set(TAGGED_EXPONENT, 300i64).set(TAGGED_SIGNIFICAND, 1i64)))
            .into();

        let table = table_from_value(Some(&value)).expect("a table");

        // Not a decimal: the tagged map stays a nested table, which is what a
        // header no reader can rebuild honestly looks like.
        assert!(matches!(table.inner().get("price"), Some(AMQPValue::FieldTable(_))));
    }

    /// The way back: a table the broker sent, as the array TableCodec::decode reads,
    /// with the tagged shapes for the two kinds a PHP array has no type for.
    #[test]
    fn a_table_round_trips_through_its_php_array() {
        let value: Value = Map::new()
            .set("count", 7i64)
            .set("name", "n")
            .set("list", vec![Value::Int(1)])
            .set("price", tagged(TAGGED_DECIMAL, Map::new().set(TAGGED_EXPONENT, 2i64).set(TAGGED_SIGNIFICAND, 1999i64)))
            .set("when", tagged(TAGGED_TIMESTAMP, Map::new().set(TAGGED_VALUE, 1_700_000_000i64)))
            .into();

        let table = table_from_value(Some(&value)).expect("a table");
        let back = table_value(&table);

        assert_eq!(back.get("count"), Some(&Value::Int(7)));
        assert_eq!(back.get("name").and_then(Value::as_str), Some("n"));
        assert_eq!(back.get("list").and_then(Value::as_list).map(|items| items.len()), Some(1));

        let price = back.get("price").expect("the decimal");

        assert_eq!(price.get(TAGGED_KIND).and_then(Value::as_str), Some(TAGGED_DECIMAL));
        assert_eq!(price.get(TAGGED_EXPONENT), Some(&Value::Int(2)));
        assert_eq!(price.get(TAGGED_SIGNIFICAND), Some(&Value::Int(1999)));

        let when = back.get("when").expect("the timestamp");

        assert_eq!(when.get(TAGGED_KIND).and_then(Value::as_str), Some(TAGGED_TIMESTAMP));
        assert_eq!(when.get(TAGGED_VALUE), Some(&Value::Int(1_700_000_000)));
    }
}
