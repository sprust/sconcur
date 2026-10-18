//! serde over a `Value`: `Value::decode::<T>()` fills a `#[derive(Deserialize)]`
//! parameter struct straight from the tree the push export read off the PHP
//! array, so a feature declares its parameters once, as a struct, and never
//! walks the array itself.
//!
//! The mapping follows PHP rather than a wire format. A PHP array is a list
//! when its keys are 0..n and a map otherwise, and an empty array is both: so an
//! empty list deserializes as an empty map or an empty struct, and a map's
//! integer keys read as their decimal text when a string key is asked for. A
//! PHP string is bytes, so it deserializes as a `String` only when it is UTF-8;
//! a field that wants the bytes whatever they are is a `Vec<u8>` annotated with
//! the `bytes` helper below, and a string never stands in for a list. A PHP int
//! fills a float field, since PHP does not keep the distinction for a whole
//! number. An untyped `Value` field keeps the subtree as it stands, an object's
//! class included.

use std::borrow::Cow;
use std::fmt;
use std::ops::Deref;

use serde::de::{
    self, DeserializeSeed, Deserializer, EnumAccess, IntoDeserializer, MapAccess, SeqAccess, Visitor,
};

use super::value::{Key, Object, Value};

/// What a decode that does not fit says. A string: it goes straight into the
/// error result a feature reports.
#[derive(Debug)]
pub struct Error(String);

impl fmt::Display for Error {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter.write_str(&self.0)
    }
}

impl std::error::Error for Error {}

impl de::Error for Error {
    fn custom<T: fmt::Display>(message: T) -> Self {
        Error(message.to_string())
    }
}

impl Value {
    /// Decodes the value into `T`, a `Deserialize` type.
    pub fn decode<'de, T: serde::Deserialize<'de>>(&'de self) -> Result<T, Error> {
        T::deserialize(self)
    }

    fn kind(&self) -> &'static str {
        match self {
            Value::Nil => "null",
            Value::Bool(_) => "a bool",
            Value::Int(_) => "an int",
            Value::Float(_) => "a float",
            Value::Str(_) => "a string",
            Value::List(_) => "a list",
            Value::Map(_) => "a map",
            Value::Object(_) => "an object",
        }
    }

    fn mismatch(&self, expected: &str) -> Error {
        Error(format!("expected {expected}, got {}", self.kind()))
    }
}

/// Fills a `Vec<u8>` field from a PHP string in one copy. serde reads a plain
/// `Vec<u8>` as a sequence, one byte per visit, which for a request body or a file
/// chunk is a million calls where one memcpy would do; a field annotated
/// `#[serde(deserialize_with = "crate::dto::value_de::bytes")]` takes this path
/// instead. A missing field still takes `#[serde(default)]`.
pub fn bytes<'de, D: Deserializer<'de>>(deserializer: D) -> Result<Vec<u8>, D::Error> {
    struct BytesVisitor;

    impl<'de> Visitor<'de> for BytesVisitor {
        type Value = Vec<u8>;

        fn expecting(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
            formatter.write_str("a string")
        }

        fn visit_bytes<E>(self, bytes: &[u8]) -> Result<Vec<u8>, E> {
            Ok(bytes.to_vec())
        }

        fn visit_byte_buf<E>(self, bytes: Vec<u8>) -> Result<Vec<u8>, E> {
            Ok(bytes)
        }

        fn visit_str<E>(self, text: &str) -> Result<Vec<u8>, E> {
            Ok(text.as_bytes().to_vec())
        }
    }

    deserializer.deserialize_bytes(BytesVisitor)
}

/// `bytes`, with PHP `null` read as no bytes: for a field a caller may leave unset.
pub fn nullable_bytes<'de, D: Deserializer<'de>>(deserializer: D) -> Result<Vec<u8>, D::Error> {
    struct NullableBytesVisitor;

    impl<'de> Visitor<'de> for NullableBytesVisitor {
        type Value = Vec<u8>;

        fn expecting(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
            formatter.write_str("a string or null")
        }

        fn visit_none<E>(self) -> Result<Vec<u8>, E> {
            Ok(Vec::new())
        }

        fn visit_unit<E>(self) -> Result<Vec<u8>, E> {
            Ok(Vec::new())
        }

        fn visit_some<D2: Deserializer<'de>>(self, deserializer: D2) -> Result<Vec<u8>, D2::Error> {
            bytes(deserializer)
        }
    }

    deserializer.deserialize_option(NullableBytesVisitor)
}

/// The integer conversions, each refusing a value outside the target's range
/// rather than wrapping it.
macro_rules! deserialize_integer {
    ($method:ident, $visit:ident, $target:ty) => {
        fn $method<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
            match self {
                Value::Int(number) => match <$target>::try_from(*number) {
                    Ok(number) => visitor.$visit(number),
                    Err(_) => Err(Error(format!(
                        "{number} does not fit {}",
                        stringify!($target)
                    ))),
                },
                other => Err(other.mismatch("an int")),
            }
        }
    };
}

impl<'de> Deserializer<'de> for &'de Value {
    type Error = Error;

    fn deserialize_any<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        match self {
            Value::Nil => visitor.visit_unit(),
            Value::Bool(flag) => visitor.visit_bool(*flag),
            Value::Int(number) => visitor.visit_i64(*number),
            Value::Float(number) => visitor.visit_f64(*number),
            Value::Str(bytes) => match std::str::from_utf8(bytes) {
                Ok(text) => visitor.visit_borrowed_str(text),
                Err(_) => visitor.visit_borrowed_bytes(bytes),
            },
            Value::List(items) => visitor.visit_seq(ListAccess { items: items.iter() }),
            Value::Map(entries) => visitor.visit_map(MapEntries {
                entries: entries.iter(),
                pending: None,
            }),
            // Through deserialize_any the class travels too, as a marker entry
            // the Value visitor below recognises; a struct or map asked for
            // explicitly gets the properties alone.
            Value::Object(object) => visitor.visit_map(ObjectAccess {
                class: Some(&object.class),
                properties: object.properties.iter(),
                pending: None,
                pending_class: None,
            }),
        }
    }

    fn deserialize_bool<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        match self {
            Value::Bool(flag) => visitor.visit_bool(*flag),
            other => Err(other.mismatch("a bool")),
        }
    }

    deserialize_integer!(deserialize_i8, visit_i8, i8);
    deserialize_integer!(deserialize_i16, visit_i16, i16);
    deserialize_integer!(deserialize_i32, visit_i32, i32);
    deserialize_integer!(deserialize_i64, visit_i64, i64);
    deserialize_integer!(deserialize_u8, visit_u8, u8);
    deserialize_integer!(deserialize_u16, visit_u16, u16);
    deserialize_integer!(deserialize_u32, visit_u32, u32);
    deserialize_integer!(deserialize_u64, visit_u64, u64);

    fn deserialize_f32<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        match self {
            Value::Float(number) => visitor.visit_f32(*number as f32),
            Value::Int(number) => visitor.visit_f32(*number as f32),
            other => Err(other.mismatch("a number")),
        }
    }

    fn deserialize_f64<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        match self {
            Value::Float(number) => visitor.visit_f64(*number),
            Value::Int(number) => visitor.visit_f64(*number as f64),
            other => Err(other.mismatch("a number")),
        }
    }

    fn deserialize_char<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        self.deserialize_str(visitor)
    }

    fn deserialize_str<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        match self {
            Value::Str(bytes) => match std::str::from_utf8(bytes) {
                Ok(text) => visitor.visit_borrowed_str(text),
                Err(_) => Err(Error("expected a UTF-8 string, got bytes that are not".to_string())),
            },
            other => Err(other.mismatch("a string")),
        }
    }

    fn deserialize_string<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        self.deserialize_str(visitor)
    }

    fn deserialize_bytes<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        match self {
            Value::Str(bytes) => visitor.visit_borrowed_bytes(bytes),
            other => Err(other.mismatch("a string")),
        }
    }

    fn deserialize_byte_buf<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        self.deserialize_bytes(visitor)
    }

    fn deserialize_option<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        match self {
            Value::Nil => visitor.visit_none(),
            _ => visitor.visit_some(self),
        }
    }

    fn deserialize_unit<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        match self {
            Value::Nil => visitor.visit_unit(),
            other => Err(other.mismatch("null")),
        }
    }

    fn deserialize_unit_struct<V: Visitor<'de>>(self, _name: &'static str, visitor: V) -> Result<V::Value, Error> {
        self.deserialize_unit(visitor)
    }

    fn deserialize_newtype_struct<V: Visitor<'de>>(self, name: &'static str, visitor: V) -> Result<V::Value, Error> {
        // The one way a struct field borrows its subtree instead of copying it:
        // `Borrowed` asks by this name, and gets the address of the node.
        if name == BORROW_MARKER {
            return visitor.visit_u64(self as *const Value as usize as u64);
        }

        visitor.visit_newtype_struct(self)
    }

    /// A string is refused here on purpose: serde reads a `Vec<u8>` as a
    /// sequence, and letting a string answer would also let a string stand in
    /// for a `Vec<Value>` as a list of byte integers, silently. Bytes are read
    /// through the `bytes` helper.
    fn deserialize_seq<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        match self {
            Value::List(items) => visitor.visit_seq(ListAccess { items: items.iter() }),
            Value::Map(entries) if entries.is_empty() => visitor.visit_seq(ListAccess { items: [].iter() }),
            other => Err(other.mismatch("a list")),
        }
    }

    fn deserialize_tuple<V: Visitor<'de>>(self, _length: usize, visitor: V) -> Result<V::Value, Error> {
        self.deserialize_seq(visitor)
    }

    fn deserialize_tuple_struct<V: Visitor<'de>>(
        self,
        _name: &'static str,
        _length: usize,
        visitor: V,
    ) -> Result<V::Value, Error> {
        self.deserialize_seq(visitor)
    }

    fn deserialize_map<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        match self {
            Value::Map(entries) => visitor.visit_map(MapEntries {
                entries: entries.iter(),
                pending: None,
            }),
            // An empty PHP array: a map with nothing in it.
            Value::List(items) if items.is_empty() => visitor.visit_map(MapEntries {
                entries: [].iter(),
                pending: None,
            }),
            Value::Object(object) => visitor.visit_map(ObjectAccess {
                class: None,
                properties: object.properties.iter(),
                pending: None,
                pending_class: None,
            }),
            other => Err(other.mismatch("a map")),
        }
    }

    fn deserialize_struct<V: Visitor<'de>>(
        self,
        _name: &'static str,
        _fields: &'static [&'static str],
        visitor: V,
    ) -> Result<V::Value, Error> {
        self.deserialize_map(visitor)
    }

    fn deserialize_enum<V: Visitor<'de>>(
        self,
        _name: &'static str,
        _variants: &'static [&'static str],
        visitor: V,
    ) -> Result<V::Value, Error> {
        match self {
            Value::Str(_) => visitor.visit_enum(UnitVariant { value: self }),
            other => Err(other.mismatch("a string naming the variant")),
        }
    }

    fn deserialize_identifier<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        self.deserialize_str(visitor)
    }

    fn deserialize_ignored_any<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        visitor.visit_unit()
    }
}

struct ListAccess<'de> {
    items: std::slice::Iter<'de, Value>,
}

impl<'de> SeqAccess<'de> for ListAccess<'de> {
    type Error = Error;

    fn next_element_seed<T: DeserializeSeed<'de>>(&mut self, seed: T) -> Result<Option<T::Value>, Error> {
        match self.items.next() {
            Some(item) => seed.deserialize(item).map(Some),
            None => Ok(None),
        }
    }

    fn size_hint(&self) -> Option<usize> {
        Some(self.items.len())
    }
}

/// The key of a map entry on its way into a struct field name or a map key: a
/// string key as it is, an integer key as its decimal text.
struct KeyDeserializer<'de> {
    key: &'de Key,
}

impl<'de> Deserializer<'de> for KeyDeserializer<'de> {
    type Error = Error;

    fn deserialize_any<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        match self.key {
            Key::Int(index) => visitor.visit_i64(*index),
            Key::Str(bytes) => match std::str::from_utf8(bytes) {
                Ok(text) => visitor.visit_borrowed_str(text),
                Err(_) => visitor.visit_borrowed_bytes(bytes),
            },
        }
    }

    fn deserialize_str<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        match self.key {
            Key::Int(index) => visitor.visit_string(index.to_string()),
            Key::Str(bytes) => match std::str::from_utf8(bytes) {
                Ok(text) => visitor.visit_borrowed_str(text),
                Err(_) => Err(Error("a map key is not UTF-8".to_string())),
            },
        }
    }

    fn deserialize_string<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        self.deserialize_str(visitor)
    }

    fn deserialize_identifier<V: Visitor<'de>>(self, visitor: V) -> Result<V::Value, Error> {
        self.deserialize_str(visitor)
    }

    serde::forward_to_deserialize_any! {
        bool i8 i16 i32 i64 u8 u16 u32 u64 f32 f64 char bytes byte_buf option unit
        unit_struct newtype_struct seq tuple tuple_struct map struct enum ignored_any
    }
}

struct MapEntries<'de> {
    entries: std::slice::Iter<'de, (Key, Value)>,
    pending: Option<&'de Value>,
}

impl<'de> MapAccess<'de> for MapEntries<'de> {
    type Error = Error;

    fn next_key_seed<K: DeserializeSeed<'de>>(&mut self, seed: K) -> Result<Option<K::Value>, Error> {
        match self.entries.next() {
            Some((key, value)) => {
                self.pending = Some(value);

                seed.deserialize(KeyDeserializer { key }).map(Some)
            }
            None => Ok(None),
        }
    }

    fn next_value_seed<T: DeserializeSeed<'de>>(&mut self, seed: T) -> Result<T::Value, Error> {
        match self.pending.take() {
            Some(value) => seed.deserialize(value),
            None => Err(Error("a map value was asked for before its key".to_string())),
        }
    }

    fn size_hint(&self) -> Option<usize> {
        Some(self.entries.len())
    }
}

/// The name `Borrowed` asks for its subtree under, which no derived struct uses.
const BORROW_MARKER: &str = "\0sconcur_borrowed_value";

/// PHP null, for a field that borrows and was not sent.
pub static NIL: Value = Value::Nil;

/// A subtree of the tree a struct is decoded from, borrowed rather than copied.
///
/// A parameter struct keeps its command body untyped until the sub-operation
/// knows what it is (`dt`, `p`), and a `Value` field would copy that body out of
/// the message — for a file write or an upload chunk, the whole body, once more.
/// This field type keeps a reference into the message instead; the struct gains
/// the lifetime of the value it was decoded from, which is the message's.
///
/// It can only come out of `Value::decode`, this module's own deserializer: the
/// address of the node travels through the marker newtype above, which no other
/// deserializer answers, so another one fails here with an error rather than a
/// forged reference. The type is crate-private for the same reason.
#[derive(Clone, Copy, Debug)]
pub struct Borrowed<'de>(pub &'de Value);

impl Deref for Borrowed<'_> {
    type Target = Value;

    fn deref(&self) -> &Value {
        self.0
    }
}

impl PartialEq<Value> for Borrowed<'_> {
    fn eq(&self, other: &Value) -> bool {
        self.0 == other
    }
}

/// The default of a borrowed field PHP did not send.
pub fn borrowed_nil() -> Borrowed<'static> {
    Borrowed(&NIL)
}

/// `'de: 'a`, which is what `#[serde(borrow)]` asks: a reference that lives for
/// the decode lives for the struct.
impl<'de: 'a, 'a> serde::Deserialize<'de> for Borrowed<'a> {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        struct AddressVisitor<'a>(std::marker::PhantomData<&'a Value>);

        impl<'de: 'a, 'a> Visitor<'de> for AddressVisitor<'a> {
            type Value = Borrowed<'a>;

            fn expecting(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
                formatter.write_str("a subtree of the value being decoded")
            }

            fn visit_u64<E>(self, address: u64) -> Result<Borrowed<'a>, E> {
                // Only this module's deserializer answers the marker, and it
                // answers with a node of the tree that lives for 'de, hence for 'a.
                let node: &'de Value = unsafe { &*(address as usize as *const Value) };

                Ok(Borrowed(node))
            }
        }

        deserializer.deserialize_newtype_struct(BORROW_MARKER, AddressVisitor(std::marker::PhantomData))
    }
}

/// The key under which an object read through `deserialize_any` names its class,
/// ahead of its properties. A NUL byte keeps it apart from any property a PHP
/// class can declare.
const CLASS_MARKER: &str = "\0class";

/// An object read as a map of its properties, with the class first when asked.
struct ObjectAccess<'de> {
    class: Option<&'de Cow<'static, str>>,
    properties: std::slice::Iter<'de, (Cow<'static, str>, Value)>,
    pending: Option<&'de Value>,
    /// The class name handed out as the marker entry's value: it exists nowhere
    /// in the tree as a `Value`, so it is carried on its own.
    pending_class: Option<&'de str>,
}

impl<'de> MapAccess<'de> for ObjectAccess<'de> {
    type Error = Error;

    fn next_key_seed<K: DeserializeSeed<'de>>(&mut self, seed: K) -> Result<Option<K::Value>, Error> {
        if let Some(class) = self.class.take() {
            self.pending_class = Some(class.as_ref());

            return seed.deserialize(CLASS_MARKER.into_deserializer()).map(Some);
        }

        match self.properties.next() {
            Some((name, value)) => {
                self.pending = Some(value);

                seed.deserialize(name.as_ref().into_deserializer()).map(Some)
            }
            None => Ok(None),
        }
    }

    fn next_value_seed<T: DeserializeSeed<'de>>(&mut self, seed: T) -> Result<T::Value, Error> {
        if let Some(class) = self.pending_class.take() {
            return seed.deserialize(class.into_deserializer());
        }

        match self.pending.take() {
            Some(value) => seed.deserialize(value),
            None => Err(Error("a property value was asked for before its name".to_string())),
        }
    }
}

/// A unit enum variant named by a string.
struct UnitVariant<'de> {
    value: &'de Value,
}

impl<'de> EnumAccess<'de> for UnitVariant<'de> {
    type Error = Error;
    type Variant = Self;

    fn variant_seed<V: DeserializeSeed<'de>>(self, seed: V) -> Result<(V::Value, Self), Error> {
        let variant = seed.deserialize(self.value)?;

        Ok((variant, self))
    }
}

impl<'de> de::VariantAccess<'de> for UnitVariant<'de> {
    type Error = Error;

    fn unit_variant(self) -> Result<(), Error> {
        Ok(())
    }

    fn newtype_variant_seed<T: DeserializeSeed<'de>>(self, _seed: T) -> Result<T::Value, Error> {
        Err(Error("a newtype variant is not something a PHP value names".to_string()))
    }

    fn tuple_variant<V: Visitor<'de>>(self, _length: usize, _visitor: V) -> Result<V::Value, Error> {
        Err(Error("a tuple variant is not something a PHP value names".to_string()))
    }

    fn struct_variant<V: Visitor<'de>>(self, _fields: &'static [&'static str], _visitor: V) -> Result<V::Value, Error> {
        Err(Error("a struct variant is not something a PHP value names".to_string()))
    }
}

/// `Value` itself as a `Deserialize` target, so a struct keeps a field untyped
/// until a sub-operation knows what it is: the tree is copied as it stands.
impl<'de> serde::Deserialize<'de> for Value {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        deserializer.deserialize_any(ValueVisitor)
    }
}

struct ValueVisitor;

impl<'de> Visitor<'de> for ValueVisitor {
    type Value = Value;

    fn expecting(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter.write_str("any PHP value")
    }

    fn visit_bool<E>(self, flag: bool) -> Result<Value, E> {
        Ok(Value::Bool(flag))
    }

    fn visit_i64<E>(self, number: i64) -> Result<Value, E> {
        Ok(Value::Int(number))
    }

    fn visit_u64<E: de::Error>(self, number: u64) -> Result<Value, E> {
        Ok(Value::uint(number))
    }

    fn visit_f64<E>(self, number: f64) -> Result<Value, E> {
        Ok(Value::Float(number))
    }

    fn visit_str<E>(self, text: &str) -> Result<Value, E> {
        Ok(Value::Str(text.as_bytes().to_vec()))
    }

    fn visit_string<E>(self, text: String) -> Result<Value, E> {
        Ok(Value::Str(text.into_bytes()))
    }

    fn visit_bytes<E>(self, bytes: &[u8]) -> Result<Value, E> {
        Ok(Value::Str(bytes.to_vec()))
    }

    fn visit_byte_buf<E>(self, bytes: Vec<u8>) -> Result<Value, E> {
        Ok(Value::Str(bytes))
    }

    fn visit_none<E>(self) -> Result<Value, E> {
        Ok(Value::Nil)
    }

    fn visit_some<D: Deserializer<'de>>(self, deserializer: D) -> Result<Value, D::Error> {
        deserializer.deserialize_any(ValueVisitor)
    }

    fn visit_unit<E>(self) -> Result<Value, E> {
        Ok(Value::Nil)
    }

    fn visit_seq<A: SeqAccess<'de>>(self, mut sequence: A) -> Result<Value, A::Error> {
        let mut items = Vec::with_capacity(sequence.size_hint().unwrap_or(0));

        while let Some(item) = sequence.next_element()? {
            items.push(item);
        }

        Ok(Value::List(items))
    }

    fn visit_map<A: MapAccess<'de>>(self, mut map: A) -> Result<Value, A::Error> {
        let mut entries: Vec<(Key, Value)> = Vec::with_capacity(map.size_hint().unwrap_or(0));

        while let Some(key) = map.next_key::<MapKey>()? {
            let value = map.next_value()?;

            entries.push((key.0, value));
        }

        // An object announced itself: the marker entry names the class and the
        // rest are its properties.
        if let Some((Key::Str(marker), Value::Str(class))) = entries.first()
            && marker == CLASS_MARKER.as_bytes()
        {
            let class = String::from_utf8_lossy(class).into_owned();
            let properties = entries
                .iter()
                .skip(1)
                .map(|(name, value)| (Cow::Owned(name.text()), value.clone()))
                .collect();

            return Ok(Value::Object(Object {
                class: Cow::Owned(class),
                properties,
            }));
        }

        Ok(Value::Map(entries))
    }
}

/// A map key as it comes out of any deserializer: an integer or text.
struct MapKey(Key);

impl<'de> serde::Deserialize<'de> for MapKey {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        struct KeyVisitor;

        impl Visitor<'_> for KeyVisitor {
            type Value = MapKey;

            fn expecting(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
                formatter.write_str("an array key")
            }

            fn visit_i64<E>(self, index: i64) -> Result<MapKey, E> {
                Ok(MapKey(Key::Int(index)))
            }

            fn visit_u64<E>(self, index: u64) -> Result<MapKey, E> {
                Ok(MapKey(match i64::try_from(index) {
                    Ok(index) => Key::Int(index),
                    Err(_) => Key::Str(index.to_string().into_bytes()),
                }))
            }

            fn visit_str<E>(self, text: &str) -> Result<MapKey, E> {
                Ok(MapKey(Key::Str(text.as_bytes().to_vec())))
            }

            fn visit_bytes<E>(self, bytes: &[u8]) -> Result<MapKey, E> {
                Ok(MapKey(Key::Str(bytes.to_vec())))
            }
        }

        deserializer.deserialize_any(KeyVisitor)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::dto::Map;
    use serde::Deserialize;
    use std::collections::HashMap;

    #[derive(Deserialize, Debug, PartialEq, Default)]
    struct Params {
        #[serde(rename = "q", default)]
        sql: String,
        #[serde(rename = "b", default)]
        bindings: Vec<Value>,
        #[serde(rename = "to", default)]
        timeout_ms: i64,
        #[serde(rename = "hd", default)]
        headers: HashMap<String, Vec<String>>,
        #[serde(rename = "bd", default, deserialize_with = "crate::dto::value_de::bytes")]
        body: Vec<u8>,
        #[serde(rename = "ro", default)]
        read_only: bool,
        #[serde(rename = "f", default)]
        factor: f64,
        #[serde(rename = "opt", default)]
        option: Option<String>,
    }

    #[test]
    fn a_struct_fills_from_a_map_with_defaults_for_what_is_missing() {
        let value: Value = Map::new()
            .set("q", "select 1")
            .set("b", vec![Value::Int(1), Value::Str(b"x".to_vec()), Value::Nil])
            .set("to", 500i64)
            .set("bd", Value::Str(vec![0, 255, 7]))
            .set("f", 3i64)
            .into();

        let params: Params = value.decode().expect("decodes");

        assert_eq!(params.sql, "select 1");
        assert_eq!(params.bindings.len(), 3);
        assert_eq!(params.timeout_ms, 500);
        assert_eq!(params.body, vec![0, 255, 7]);
        assert_eq!(params.factor, 3.0);
        assert!(params.headers.is_empty());
        assert!(!params.read_only);
        assert_eq!(params.option, None);
    }

    #[test]
    fn an_empty_php_array_is_an_empty_map_and_an_empty_struct() {
        let value: Value = Map::new().set("hd", Value::List(vec![])).into();
        let params: Params = value.decode().expect("decodes");

        assert!(params.headers.is_empty());

        let empty: Params = Value::List(vec![]).decode().expect("an empty list is an empty struct");

        assert_eq!(empty, Params::default());
    }

    #[test]
    fn integer_keys_read_as_text_where_a_string_key_is_asked_for() {
        let value: Value = Map::new()
            .set("hd", Value::Map(vec![(Key::Int(123), Value::List(vec![Value::Str(b"v".to_vec())]))]))
            .into();

        let params: Params = value.decode().expect("decodes");

        assert_eq!(params.headers.get("123"), Some(&vec!["v".to_string()]));
    }

    #[test]
    fn a_value_out_of_range_or_of_the_wrong_kind_is_refused() {
        let too_wide: Value = Map::new().set("to", Value::Str(b"5".to_vec())).into();

        assert!(too_wide.decode::<Params>().is_err());

        let not_a_struct = Value::Int(5);

        assert!(not_a_struct.decode::<Params>().is_err());

        let byte: Result<u8, _> = Value::Int(300).decode();

        assert!(byte.is_err());
    }

    #[test]
    fn a_value_field_copies_the_subtree_as_it_stands() {
        let subtree: Value = Map::new().set("a", 1i64).set(7usize, Value::Nil).into();
        let value: Value = Map::new().set("b", vec![subtree.clone()]).into();

        let params: Params = value.decode().expect("decodes");

        assert_eq!(params.bindings, vec![subtree]);
    }

    #[test]
    fn a_bytes_field_takes_the_string_in_one_piece_and_null_as_nothing() {
        #[derive(Deserialize)]
        struct Body {
            #[serde(rename = "bd", default, deserialize_with = "crate::dto::value_de::bytes")]
            body: Vec<u8>,
            #[serde(rename = "nb", default, deserialize_with = "crate::dto::value_de::nullable_bytes")]
            nullable: Vec<u8>,
        }

        let value: Value = Map::new().set("bd", Value::Str(vec![0, 200, 7])).set("nb", Value::Nil).into();
        let body: Body = value.decode().expect("decodes");

        assert_eq!(body.body, vec![0, 200, 7]);
        assert!(body.nullable.is_empty());

        let missing: Body = Value::List(vec![]).decode().expect("defaults");

        assert!(missing.body.is_empty());

        let wrong: Value = Map::new().set("bd", 5i64).into();

        assert!(wrong.decode::<Body>().is_err());
    }

    #[test]
    fn a_borrowed_field_points_into_the_tree_instead_of_copying_it() {
        #[derive(Deserialize)]
        struct Envelope<'a> {
            #[serde(rename = "cm", default)]
            command: String,
            #[serde(rename = "dt", borrow, default = "crate::dto::value_de::borrowed_nil")]
            data: Borrowed<'a>,
        }

        let body: Value = Map::new().set("c", Value::Str(vec![1, 2, 3])).into();
        let value: Value = Map::new().set("cm", "wr").set("dt", body.clone()).into();

        let envelope: Envelope<'_> = value.decode().expect("decodes");

        assert_eq!(envelope.command, "wr");
        assert!(std::ptr::eq(envelope.data.0, value.get("dt").unwrap()), "the body is not copied");
        assert_eq!(envelope.data.get("c"), body.get("c"));

        let empty = Value::List(vec![]);
        let without: Envelope<'_> = empty.decode().expect("decodes");

        assert!(without.data.is_nil());
    }

    #[test]
    fn a_string_never_stands_in_for_a_list() {
        let value: Value = Map::new().set("b", "hi").into();

        assert!(value.decode::<Params>().is_err(), "a string must not decode as a list of its bytes");
    }

    #[test]
    fn a_value_field_keeps_an_object_with_its_class() {
        let object = Value::object("SConcur\\Bson\\ObjectId", vec![("oid", "abc".into())]);
        let value: Value = Map::new().set("b", vec![object.clone()]).into();

        let params: Params = value.decode().expect("decodes");

        assert_eq!(params.bindings, vec![object]);
    }

    #[test]
    fn an_object_reads_as_a_map_of_its_properties() {
        #[derive(Deserialize)]
        struct Id {
            oid: String,
        }

        let value = Value::object("SConcur\\Bson\\ObjectId", vec![("oid", "abc".into())]);
        let id: Id = value.decode().expect("decodes");

        assert_eq!(id.oid, "abc");
    }
}
