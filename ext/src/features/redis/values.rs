//! RESP values on their way to PHP, and PHP arguments on their way to a command.
//!
//! A reply crosses as the PHP value it becomes. Every string kind of RESP lands
//! in one PHP string of the bytes the server sent: a Redis value is arbitrary
//! bytes, and reading them as UTF-8 breaks on the first compressed blob somebody
//! stored.

use redis::{ErrorKind, RedisError, ServerError, Value};

use super::errors::Kind;
use crate::dto::{Key, Map, Value as PhpValue};

/// One reply as the value PHP receives. The command is not consulted: what the
/// server sent is what crosses, and shaping a reply into something friendlier
/// is the PHP facade's job, where it is visible in the method that does it.
///
/// Fallible, because `Value` is non_exhaustive: a variant a later redis-rs adds
/// must fail loudly rather than cross as null and be read as a missing key.
pub fn reply_value(value: &Value) -> std::result::Result<PhpValue, String> {
    convert(value)
}

/// A list of replies — the elements of one batch.
pub fn replies_value(values: &[Value]) -> std::result::Result<PhpValue, String> {
    convert_list(values)
}

/// The answer to a pipeline: the replies under `r`, plus the failures under `e`
/// by the position of the command that caused them, each as `{c: code, m: message}`.
///
/// The failures travel beside the replies rather than inside them. They used to
/// be a marked map in the reply's own place, and that was forgeable: a hash with
/// a field named after the marker decoded into exactly the same shape, so a
/// value somebody had stored could come back to PHP as an error object.
pub fn pipeline_value(values: &[Value]) -> std::result::Result<PhpValue, String> {
    let mut replies: Vec<PhpValue> = Vec::with_capacity(values.len());
    let mut errors = Map::new();

    for (index, value) in values.iter().enumerate() {
        match value {
            // A failed command has no reply; null holds its place so the list
            // still lines up with the commands that were sent.
            Value::ServerError(error) => {
                replies.push(PhpValue::Nil);
                errors.push(index, failure_value(error));
            }
            other => replies.push(convert(other)?),
        }
    }

    Ok(Map::new().set("r", replies).set("e", errors).into())
}

fn failure_value(error: &ServerError) -> PhpValue {
    Map::new()
        .set("c", error.code())
        .set("m", error.details().unwrap_or(""))
        .into()
}

fn convert(value: &Value) -> std::result::Result<PhpValue, String> {
    Ok(match value {
        Value::Nil => PhpValue::Nil,
        Value::Int(number) => PhpValue::Int(*number),
        Value::BulkString(bytes) => PhpValue::str(bytes.as_slice()),
        Value::SimpleString(text) => PhpValue::str(text.as_str()),
        // The status reply of SET, DEL and the rest. PHP sees the string every
        // other client shows, rather than a boolean this side invented.
        Value::Okay => PhpValue::str("OK"),
        Value::Array(items) | Value::Set(items) => convert_list(items)?,
        Value::Map(pairs) => {
            let mut map = Map::with_capacity(pairs.len());

            for (key, item) in pairs {
                map.push(map_key(key)?, convert(item)?);
            }

            map.into()
        }
        Value::Double(number) => PhpValue::Float(*number),
        Value::Boolean(flag) => PhpValue::Bool(*flag),
        Value::VerbatimString { text, .. } => PhpValue::str(text.as_str()),
        // Without the num-bigint feature redis-rs hands the digits over as
        // bytes, which is what a number too large for an i64 has to stay on the
        // PHP side anyway.
        Value::BigNumber(digits) => PhpValue::str(digits.as_slice()),
        // An attribute is metadata attached to a reply; the reply is what the
        // caller asked for.
        Value::Attribute { data, .. } => convert(data)?,
        Value::Push { data, .. } => convert_list(data)?,
        // Reached only where a server error is nested inside another reply. The
        // single-command path lifts a top-level one into the call's failure, and
        // the pipeline path takes them out before converting.
        Value::ServerError(error) => {
            return Err(format!(
                "{} {}",
                error.code(),
                error.details().unwrap_or("")
            ));
        }
        // Value is non_exhaustive: a variant added by a later redis-rs must not
        // silently become null, which PHP would read as a missing key.
        other => return Err(format!("unsupported reply type: {other:?}")),
    })
}

fn convert_list(items: &[Value]) -> std::result::Result<PhpValue, String> {
    items
        .iter()
        .map(convert)
        .collect::<std::result::Result<Vec<PhpValue>, String>>()
        .map(PhpValue::List)
}

/// The array key a RESP3 map key becomes: the key PHP itself makes of that
/// scalar when it is used as an array offset. A string stays a string (the glue
/// turns a numeric one into an integer, as PHP does), a float is truncated, a
/// boolean is 0 or 1 and nil is the empty string. A container cannot be an
/// array key, so one here fails the conversion.
fn map_key(key: &Value) -> std::result::Result<Key, String> {
    Ok(match key {
        Value::Int(number) => Key::Int(*number),
        Value::BulkString(bytes) => Key::Str(bytes.clone()),
        Value::SimpleString(text) => Key::Str(text.as_bytes().to_vec()),
        Value::Okay => Key::Str(b"OK".to_vec()),
        Value::VerbatimString { text, .. } => Key::Str(text.as_bytes().to_vec()),
        Value::BigNumber(digits) => Key::Str(digits.clone()),
        Value::Double(number) => Key::Int(*number as i64),
        Value::Boolean(flag) => Key::Int(i64::from(*flag)),
        Value::Nil => Key::Str(Vec::new()),
        Value::Attribute { data, .. } => map_key(data)?,
        Value::ServerError(error) => {
            return Err(format!(
                "{} {}",
                error.code(),
                error.details().unwrap_or("")
            ));
        }
        other => return Err(format!("unsupported map key type: {other:?}")),
    })
}

/// One command argument, as the bytes it goes on the wire as.
///
/// The PHP side accepts only strings, integers and floats and writes integers
/// and floats out as their decimal form, so a value that is not a string here
/// is a payload that did not come from it. Both MessagePack string kinds are
/// taken, because what PHP packs depends on whether the string is valid UTF-8.
pub fn decode_arg(value: &rmpv::Value) -> Result<Vec<u8>, String> {
    match value {
        rmpv::Value::Binary(bytes) => Ok(bytes.clone()),
        rmpv::Value::String(text) => Ok(text.as_bytes().to_vec()),
        other => Err(format!("a command argument must be a string, got {other}")),
    }
}

/// Every argument of one command, in order.
pub fn decode_args(values: &[rmpv::Value]) -> Result<Vec<Vec<u8>>, String> {
    values.iter().map(decode_arg).collect()
}

/// A driver failure, as the kind PHP raises it and the text that goes with it.
///
/// The kind is decided here, from the driver's own typed error, and never from
/// the message: a Lua script can answer whatever it likes, and classifying that
/// text would let it choose the exception the application catches.
pub fn classify_error(error: &RedisError) -> (Kind, String) {
    // A code is the first token of an error reply, so having one means the server
    // answered and the connection carried the answer whole. That is the entire
    // test: reading the code itself would put the choice of exception in the hands
    // of whatever answered, and a Lua script answering "NOAUTH nice try" would pick
    // it. A refused password is not this case — it fails in the handshake, with no
    // code at all, and the kinds below have it.
    if let Some(code) = error.code() {
        let detail = error.detail().unwrap_or("").to_string();

        return (Kind::Command, format!("{code} {detail}").trim_end().to_string());
    }

    // Without a code the failure belongs to the socket or to this side, never to
    // the server's answer. Anything unrecognised counts as a connection failure
    // rather than a command one, and that default is load-bearing: a reply this
    // side could not parse leaves the connection's framing in doubt, and
    // pools::Dedicated::keep_if_clean reads Kind::Command as the proof that a
    // connection is safe to lend to the next blocking command.
    let kind = match error.kind() {
        ErrorKind::Io | ErrorKind::AuthenticationFailed => Kind::Connection,
        ErrorKind::InvalidClientConfig => Kind::Dsn,
        _ if error.is_timeout() => Kind::Timeout,
        _ => Kind::Connection,
    };

    (kind, error.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn converted(value: &Value) -> PhpValue {
        reply_value(value).expect("the value is convertible")
    }

    #[test]
    fn nil_becomes_nil() {
        assert_eq!(converted(&Value::Nil), PhpValue::Nil);
    }

    #[test]
    fn an_integer_stays_an_integer() {
        assert_eq!(converted(&Value::Int(-42)), PhpValue::Int(-42));
    }

    #[test]
    fn a_bulk_string_crosses_as_its_bytes() {
        assert_eq!(
            converted(&Value::BulkString(vec![0x00, 0xff, b'a'])).as_bytes(),
            Some(&[0x00, 0xff, b'a'][..])
        );
    }

    #[test]
    fn okay_crosses_as_the_status_string() {
        assert_eq!(converted(&Value::Okay).as_bytes(), Some(&b"OK"[..]));
    }

    #[test]
    fn nested_arrays_keep_their_shape() {
        let value = Value::Array(vec![
            Value::Int(1),
            Value::Array(vec![Value::BulkString(b"inner".to_vec()), Value::Nil]),
        ]);

        let items = converted(&value).as_list().expect("a list").to_vec();

        assert_eq!(items.len(), 2);
        assert_eq!(items[1].as_list().expect("a nested list").len(), 2);
    }

    #[test]
    fn a_map_crosses_as_a_map() {
        let value = Value::Map(vec![(Value::BulkString(b"k".to_vec()), Value::Int(7))]);

        let converted = converted(&value);

        assert_eq!(converted.as_map().expect("a map").len(), 1);
        assert_eq!(converted.get("k"), Some(&PhpValue::Int(7)));
    }

    #[test]
    fn an_integer_map_key_stays_an_integer_key() {
        let value = Value::Map(vec![(Value::Int(3), Value::Okay)]);

        let entries = converted(&value).as_map().expect("a map").to_vec();

        assert_eq!(entries[0].0, Key::Int(3));
    }

    #[test]
    fn resp3_scalars_keep_their_types() {
        assert_eq!(converted(&Value::Double(1.5)), PhpValue::Float(1.5));
        assert_eq!(converted(&Value::Boolean(true)), PhpValue::Bool(true));
    }

    #[test]
    fn a_nested_server_error_fails_the_conversion() {
        // Rather than crossing as some value PHP would read as data.
        let value = Value::Array(vec![Value::Int(1), server_error()]);

        assert!(reply_value(&value).is_err());
    }

    #[test]
    fn a_pipeline_answer_carries_replies_and_failures_apart() {
        let values = vec![Value::Okay, server_error(), Value::Int(3)];

        let answer = pipeline_value(&values).expect("convertible");

        let replies = answer
            .get("r")
            .and_then(PhpValue::as_list)
            .expect("the replies are there");

        let errors = answer
            .get("e")
            .and_then(PhpValue::as_map)
            .expect("the failures are there");

        assert_eq!(replies.len(), 3);
        // The failed command holds its place, so the list still lines up with
        // the commands that were sent.
        assert_eq!(replies[1], PhpValue::Nil);

        assert_eq!(errors.len(), 1);
        assert_eq!(errors[0].0, Key::Int(1));
        assert_eq!(errors[0].1.get("c"), Some(&PhpValue::str("WRONGTYPE")));
        assert_eq!(errors[0].1.get("m"), Some(&PhpValue::str("bad")));
    }

    #[test]
    fn a_pipeline_without_failures_says_so() {
        let answer = pipeline_value(&[Value::Int(1)]).expect("convertible");

        let errors = answer
            .get("e")
            .and_then(PhpValue::as_map)
            .expect("the failures field is always there");

        assert!(errors.is_empty());
    }

    #[test]
    fn a_value_cannot_forge_a_failure() {
        // The old encoding marked a failure with a magic key inside the reply
        // itself, so a hash carrying that field came back to PHP as an error
        // object. Failures travel beside the replies now, and a map that looks
        // like one is still just a map.
        let value = Value::Map(vec![
            (
                Value::BulkString(b"__sconcur_redis_error".to_vec()),
                Value::BulkString(b"1".to_vec()),
            ),
            (Value::BulkString(b"code".to_vec()), Value::BulkString(b"BOOM".to_vec())),
        ]);

        let answer = pipeline_value(&[value]).expect("convertible");

        let errors = answer
            .get("e")
            .and_then(PhpValue::as_map)
            .expect("the failures field is always there");

        assert!(errors.is_empty(), "a stored value forged a failure");
    }

    #[test]
    fn an_argument_is_taken_from_either_string_kind() {
        assert_eq!(
            decode_arg(&rmpv::Value::Binary(vec![1, 2, 3])).unwrap(),
            vec![1, 2, 3]
        );
        assert_eq!(
            decode_arg(&rmpv::Value::from("key")).unwrap(),
            b"key".to_vec()
        );
    }

    #[test]
    fn an_argument_that_is_not_a_string_is_refused() {
        let error = decode_arg(&rmpv::Value::from(7)).unwrap_err();

        assert!(error.contains("must be a string"), "{error}");
    }

    /// The RedisError a connection hands back for an error reply.
    fn server_failure(reply: &[u8]) -> RedisError {
        match redis::parse_redis_value(reply) {
            Ok(value) => value.extract_error().expect_err("an error reply is a failure"),
            Err(error) => panic!("the reply should parse: {error:?}"),
        }
    }

    #[test]
    fn an_answer_from_the_server_is_a_command_failure_whatever_it_spells() {
        assert_eq!(classify_error(&server_failure(b"-WRONGTYPE bad\r\n")).0, Kind::Command);

        // NOAUTH was read as a connection failure, out of the code the server sent.
        // A Lua script answering redis.error_reply('NOAUTH nice try') could pick the
        // exception PHP raises that way — and a connection the server had plainly
        // answered looked too dirty to lend to the next blocking command.
        assert_eq!(classify_error(&server_failure(b"-NOAUTH nice try\r\n")).0, Kind::Command);
    }

    #[test]
    fn a_failure_the_server_did_not_answer_is_never_a_command_failure() {
        let dropped = RedisError::from((ErrorKind::Io, "connection reset"));

        assert_eq!(classify_error(&dropped).0, Kind::Connection);

        // The catch-all carries as much weight as the named kinds: Kind::Command is
        // what pools::Dedicated::keep_if_clean parks a connection on, so a reply this
        // side could not read must not wear it.
        let unreadable = RedisError::from((ErrorKind::UnexpectedReturnType, "unexpected reply"));

        assert!(unreadable.code().is_none(), "the case only holds for an error with no code");
        assert_eq!(classify_error(&unreadable).0, Kind::Connection);
    }

    /// A server error to convert with: what the parser makes of an error reply,
    /// which is exactly what a connection hands back.
    fn server_error() -> Value {
        match redis::parse_redis_value(b"-WRONGTYPE bad\r\n") {
            Ok(value @ Value::ServerError(_)) => value,
            other => panic!("expected a server error value, got {other:?}"),
        }
    }
}
