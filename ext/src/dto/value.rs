//! The value a result carries to PHP.
//!
//! A task does not encode its answer into bytes: it builds this tree on the
//! runtime thread, and the wait export that PHP is parked in walks the tree on
//! the PHP thread and asks the glue's value builder (sconcur.c) for the
//! matching zval of every node. The variants are PHP's own scalar and container
//! types plus an object of a named class, so a feature decides the exact PHP
//! shape of its answer here and nothing re-interprets it on the other side.

/// One PHP value.
#[derive(Clone, Debug, PartialEq)]
pub enum Value {
    /// PHP `null`.
    Nil,
    Bool(bool),
    Int(i64),
    Float(f64),
    /// A PHP string. Text and bytes alike: PHP has one type for both, so a body,
    /// a BLOB and a name all travel here.
    Str(Vec<u8>),
    /// A PHP list: the keys are 0..n in this order.
    List(Vec<Value>),
    /// A PHP associative array, in insertion order. A numeric-string key becomes
    /// the integer key PHP itself would make of it.
    Map(Vec<(Key, Value)>),
    /// An instance of a userland class, created without its constructor and
    /// with the named declared properties written in place, the way
    /// `unserialize()` materializes one.
    Object(Object),
}

/// An array key.
#[derive(Clone, Debug, PartialEq)]
pub enum Key {
    Int(i64),
    Str(Vec<u8>),
}

/// An object: the class by its fully qualified name, and its declared
/// properties in declaration order.
#[derive(Clone, Debug, PartialEq)]
pub struct Object {
    pub class: &'static str,
    pub properties: Vec<(&'static str, Value)>,
}

/// A builder for `Value::Map`, so a feature writes its answer as a flat list of
/// `set` calls rather than as nested tuple vectors.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Map(Vec<(Key, Value)>);

impl Map {
    pub fn new() -> Self {
        Map(Vec::new())
    }

    pub fn with_capacity(capacity: usize) -> Self {
        Map(Vec::with_capacity(capacity))
    }

    /// Appends one entry. A repeated key is appended too, and the later entry
    /// wins on the PHP side, as in an array literal.
    pub fn set(mut self, key: impl Into<Key>, value: impl Into<Value>) -> Self {
        self.push(key, value);

        self
    }

    pub fn push(&mut self, key: impl Into<Key>, value: impl Into<Value>) {
        self.0.push((key.into(), value.into()));
    }

}

impl From<Map> for Value {
    fn from(map: Map) -> Self {
        Value::Map(map.0)
    }
}

impl Value {
    /// A string from anything that is bytes.
    pub fn str(text: impl Into<Vec<u8>>) -> Self {
        Value::Str(text.into())
    }

    /// An unsigned integer. PHP integers are signed 64-bit, so a value past
    /// i64::MAX becomes a float, which is what PHP's own decoders make of it.
    pub fn uint(number: u64) -> Self {
        match i64::try_from(number) {
            Ok(number) => Value::Int(number),
            Err(_) => Value::Float(number as f64),
        }
    }

    /// An object of `class` with its properties, in the order given.
    pub fn object(class: &'static str, properties: Vec<(&'static str, Value)>) -> Self {
        Value::Object(Object { class, properties })
    }
}

/// Readers for the tests that pin a feature's answer: production code only
/// builds a value and hands it over, it never looks inside one.
#[cfg(test)]
impl Value {
    /// The bytes of a string value, or none for any other variant. For a test
    /// that reads a payload PHP would see as a string.
    pub fn as_bytes(&self) -> Option<&[u8]> {
        match self {
            Value::Str(bytes) => Some(bytes),
            _ => None,
        }
    }

    /// The text of a string value, lossily. For an error message in a test or
    /// a log line, never for data.
    pub fn text(&self) -> String {
        match self {
            Value::Str(bytes) => String::from_utf8_lossy(bytes).into_owned(),
            other => format!("{other:?}"),
        }
    }

    /// The entries of a map value, or none for any other variant.
    pub fn as_map(&self) -> Option<&[(Key, Value)]> {
        match self {
            Value::Map(entries) => Some(entries),
            _ => None,
        }
    }

    /// The value under a string key of a map, or none.
    pub fn get(&self, key: &str) -> Option<&Value> {
        self.as_map()?
            .iter()
            .find(|(entry_key, _)| matches!(entry_key, Key::Str(bytes) if bytes == key.as_bytes()))
            .map(|(_, value)| value)
    }

    /// The items of a list value, or none for any other variant.
    pub fn as_list(&self) -> Option<&[Value]> {
        match self {
            Value::List(items) => Some(items),
            _ => None,
        }
    }
}

impl From<bool> for Value {
    fn from(flag: bool) -> Self {
        Value::Bool(flag)
    }
}

impl From<i64> for Value {
    fn from(number: i64) -> Self {
        Value::Int(number)
    }
}

impl From<i32> for Value {
    fn from(number: i32) -> Self {
        Value::Int(i64::from(number))
    }
}

impl From<u32> for Value {
    fn from(number: u32) -> Self {
        Value::Int(i64::from(number))
    }
}

impl From<u16> for Value {
    fn from(number: u16) -> Self {
        Value::Int(i64::from(number))
    }
}

impl From<u8> for Value {
    fn from(number: u8) -> Self {
        Value::Int(i64::from(number))
    }
}

impl From<u64> for Value {
    fn from(number: u64) -> Self {
        Value::uint(number)
    }
}

impl From<usize> for Value {
    fn from(number: usize) -> Self {
        Value::uint(number as u64)
    }
}

impl From<f64> for Value {
    fn from(number: f64) -> Self {
        Value::Float(number)
    }
}

impl From<f32> for Value {
    fn from(number: f32) -> Self {
        Value::Float(f64::from(number))
    }
}

impl From<&str> for Value {
    fn from(text: &str) -> Self {
        Value::Str(text.as_bytes().to_vec())
    }
}

impl From<String> for Value {
    fn from(text: String) -> Self {
        Value::Str(text.into_bytes())
    }
}

impl From<&String> for Value {
    fn from(text: &String) -> Self {
        Value::Str(text.as_bytes().to_vec())
    }
}

impl From<Vec<u8>> for Value {
    fn from(bytes: Vec<u8>) -> Self {
        Value::Str(bytes)
    }
}

impl From<&[u8]> for Value {
    fn from(bytes: &[u8]) -> Self {
        Value::Str(bytes.to_vec())
    }
}

impl From<Vec<Value>> for Value {
    fn from(items: Vec<Value>) -> Self {
        Value::List(items)
    }
}

impl<T: Into<Value>> From<Option<T>> for Value {
    fn from(value: Option<T>) -> Self {
        match value {
            Some(value) => value.into(),
            None => Value::Nil,
        }
    }
}

impl From<&str> for Key {
    fn from(text: &str) -> Self {
        Key::Str(text.as_bytes().to_vec())
    }
}

impl From<String> for Key {
    fn from(text: String) -> Self {
        Key::Str(text.into_bytes())
    }
}

impl From<&String> for Key {
    fn from(text: &String) -> Self {
        Key::Str(text.as_bytes().to_vec())
    }
}

impl From<Vec<u8>> for Key {
    fn from(bytes: Vec<u8>) -> Self {
        Key::Str(bytes)
    }
}

impl From<i64> for Key {
    fn from(number: i64) -> Self {
        Key::Int(number)
    }
}

impl From<i32> for Key {
    fn from(number: i32) -> Self {
        Key::Int(i64::from(number))
    }
}

impl From<usize> for Key {
    fn from(number: usize) -> Self {
        Key::Int(number as i64)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_map_keeps_insertion_order_and_mixed_keys() {
        let value: Value = Map::new().set("a", 1i64).set(5usize, "x").set("b", Value::Nil).into();

        let entries = value.as_map().expect("a map");

        assert_eq!(entries.len(), 3);
        assert_eq!(entries[0].0, Key::Str(b"a".to_vec()));
        assert_eq!(entries[1].0, Key::Int(5));
        assert_eq!(value.get("a"), Some(&Value::Int(1)));
        assert_eq!(value.get("b"), Some(&Value::Nil));
        assert_eq!(value.get("zzz"), None);
    }

    #[test]
    fn an_unsigned_past_the_php_integer_becomes_a_float() {
        assert_eq!(Value::uint(7), Value::Int(7));
        assert_eq!(Value::uint(u64::MAX), Value::Float(u64::MAX as f64));
    }

    #[test]
    fn an_option_folds_into_nil() {
        let none: Option<&str> = None;

        assert_eq!(Value::from(none), Value::Nil);
        assert_eq!(Value::from(Some("x")), Value::Str(b"x".to_vec()));
    }
}
