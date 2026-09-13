//! RESP values on their way to PHP, and PHP arguments on their way to a command.
//!
//! Strings go across as MessagePack `bin`, not `str`: a Redis value is
//! arbitrary bytes, and declaring them UTF-8 breaks on the first compressed
//! blob somebody stored.

use redis::{ErrorKind, RedisError, ServerError, Value};

use super::errors::Kind;
use rmp::encode;

/// Encodes one reply. The command is not consulted: what the server sent is
/// what crosses, and shaping a reply into something friendlier is the PHP
/// facade's job, where it is visible in the method that does it.
///
/// Fallible, because `Value` is non_exhaustive: a variant a later redis-rs adds
/// must fail loudly rather than cross as nil and be read as a missing key.
pub fn encode_value(value: &Value) -> std::result::Result<Vec<u8>, String> {
    let mut buffer = Vec::new();

    write_value(&mut buffer, value)?;

    Ok(buffer)
}

/// Encodes a list of replies — the elements of one batch.
pub fn encode_values(values: &[Value]) -> std::result::Result<Vec<u8>, String> {
    let mut buffer = Vec::new();

    write_array(&mut buffer, values)?;

    Ok(buffer)
}

/// The answer to a pipeline: the replies, plus the failures by the position of
/// the command that caused them.
///
/// The failures travel beside the replies rather than inside them. They used to
/// be a marked map in the reply's own place, and that was forgeable: a hash with
/// a field named after the marker decoded into exactly the same shape, so a
/// value somebody had stored could come back to PHP as an error object.
pub fn encode_pipeline(values: &[Value]) -> std::result::Result<Vec<u8>, String> {
    let mut errors: Vec<(usize, &ServerError)> = Vec::new();

    for (index, value) in values.iter().enumerate() {
        if let Value::ServerError(error) = value {
            errors.push((index, error));
        }
    }

    let mut buffer = Vec::new();

    let _ = encode::write_map_len(&mut buffer, 2);

    let _ = encode::write_str(&mut buffer, "r");
    let _ = encode::write_array_len(&mut buffer, values.len() as u32);

    for value in values {
        // A failed command has no reply; nil holds its place so the list still
        // lines up with the commands that were sent.
        match value {
            Value::ServerError(_) => {
                let _ = encode::write_nil(&mut buffer);
            }
            other => write_value(&mut buffer, other)?,
        }
    }

    let _ = encode::write_str(&mut buffer, "e");
    let _ = encode::write_map_len(&mut buffer, errors.len() as u32);

    for (index, error) in errors {
        let _ = encode::write_uint(&mut buffer, index as u64);

        let _ = encode::write_map_len(&mut buffer, 2);
        let _ = encode::write_str(&mut buffer, "c");
        let _ = encode::write_str(&mut buffer, error.code());
        let _ = encode::write_str(&mut buffer, "m");
        let _ = encode::write_str(&mut buffer, error.details().unwrap_or(""));
    }

    Ok(buffer)
}

fn write_value(buffer: &mut Vec<u8>, value: &Value) -> std::result::Result<(), String> {
    match value {
        Value::Nil => {
            let _ = encode::write_nil(buffer);
        }
        Value::Int(number) => {
            let _ = encode::write_sint(buffer, *number);
        }
        Value::BulkString(bytes) => write_bin(buffer, bytes),
        Value::SimpleString(text) => write_bin(buffer, text.as_bytes()),
        // The status reply of SET, DEL and the rest. PHP sees the string every
        // other client shows, rather than a boolean this side invented.
        Value::Okay => write_bin(buffer, b"OK"),
        Value::Array(items) | Value::Set(items) => write_array(buffer, items)?,
        Value::Map(pairs) => {
            let _ = encode::write_map_len(buffer, pairs.len() as u32);

            for (key, item) in pairs {
                write_value(buffer, key)?;
                write_value(buffer, item)?;
            }
        }
        Value::Double(number) => {
            let _ = encode::write_f64(buffer, *number);
        }
        Value::Boolean(flag) => {
            let _ = encode::write_bool(buffer, *flag);
        }
        Value::VerbatimString { text, .. } => write_bin(buffer, text.as_bytes()),
        // Without the num-bigint feature redis-rs hands the digits over as
        // bytes, which is what a number too large for an i64 has to stay on the
        // PHP side anyway.
        Value::BigNumber(digits) => write_bin(buffer, digits),
        // An attribute is metadata attached to a reply; the reply is what the
        // caller asked for.
        Value::Attribute { data, .. } => write_value(buffer, data)?,
        Value::Push { data, .. } => write_array(buffer, data)?,
        // Reached only where a server error is nested inside another reply. The
        // single-command path lifts a top-level one into the call's failure, and
        // the pipeline path takes them out before encoding.
        Value::ServerError(error) => {
            return Err(format!(
                "{} {}",
                error.code(),
                error.details().unwrap_or("")
            ));
        }
        // Value is non_exhaustive: a variant added by a later redis-rs must not
        // silently become nil, which PHP would read as a missing key.
        other => return Err(format!("unsupported reply type: {other:?}")),
    }

    Ok(())
}

fn write_array(buffer: &mut Vec<u8>, items: &[Value]) -> std::result::Result<(), String> {
    let _ = encode::write_array_len(buffer, items.len() as u32);

    for item in items {
        write_value(buffer, item)?;
    }

    Ok(())
}

fn write_bin(buffer: &mut Vec<u8>, bytes: &[u8]) {
    let _ = encode::write_bin(buffer, bytes);
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
    use rmpv::decode::read_value;

    fn decode(bytes: &[u8]) -> rmpv::Value {
        read_value(&mut &bytes[..]).expect("the encoder wrote valid MessagePack")
    }

    fn encoded(value: &Value) -> rmpv::Value {
        decode(&encode_value(value).expect("the value is encodable"))
    }

    #[test]
    fn nil_becomes_nil() {
        assert_eq!(encoded(&Value::Nil), rmpv::Value::Nil);
    }

    #[test]
    fn an_integer_stays_an_integer() {
        assert_eq!(encoded(&Value::Int(-42)), rmpv::Value::from(-42));
    }

    #[test]
    fn a_bulk_string_crosses_as_binary() {
        match encoded(&Value::BulkString(vec![0x00, 0xff, b'a'])) {
            rmpv::Value::Binary(bytes) => assert_eq!(bytes, vec![0x00, 0xff, b'a']),
            other => panic!("expected binary, got {other:?}"),
        }
    }

    #[test]
    fn okay_crosses_as_the_status_string() {
        match encoded(&Value::Okay) {
            rmpv::Value::Binary(bytes) => assert_eq!(bytes, b"OK".to_vec()),
            other => panic!("expected binary, got {other:?}"),
        }
    }

    #[test]
    fn nested_arrays_keep_their_shape() {
        let value = Value::Array(vec![
            Value::Int(1),
            Value::Array(vec![Value::BulkString(b"inner".to_vec()), Value::Nil]),
        ]);

        match encoded(&value) {
            rmpv::Value::Array(items) => {
                assert_eq!(items.len(), 2);

                match &items[1] {
                    rmpv::Value::Array(inner) => assert_eq!(inner.len(), 2),
                    other => panic!("expected a nested array, got {other:?}"),
                }
            }
            other => panic!("expected an array, got {other:?}"),
        }
    }

    #[test]
    fn a_map_crosses_as_a_map() {
        let value = Value::Map(vec![(Value::BulkString(b"k".to_vec()), Value::Int(7))]);

        match encoded(&value) {
            rmpv::Value::Map(pairs) => assert_eq!(pairs.len(), 1),
            other => panic!("expected a map, got {other:?}"),
        }
    }

    #[test]
    fn resp3_scalars_keep_their_types() {
        assert_eq!(encoded(&Value::Double(1.5)), rmpv::Value::from(1.5));
        assert_eq!(encoded(&Value::Boolean(true)), rmpv::Value::from(true));
    }

    #[test]
    fn a_nested_server_error_fails_the_encoding() {
        // Rather than crossing as some value PHP would read as data.
        let value = Value::Array(vec![Value::Int(1), server_error()]);

        assert!(encode_value(&value).is_err());
    }

    #[test]
    fn a_pipeline_answer_carries_replies_and_failures_apart() {
        let values = vec![Value::Okay, server_error(), Value::Int(3)];

        let decoded = decode(&encode_pipeline(&values).expect("encodable"));

        let rmpv::Value::Map(fields) = decoded else {
            panic!("expected a map");
        };

        let replies = fields
            .iter()
            .find(|(key, _)| key.as_str() == Some("r"))
            .map(|(_, value)| value)
            .expect("the replies are there");

        let errors = fields
            .iter()
            .find(|(key, _)| key.as_str() == Some("e"))
            .map(|(_, value)| value)
            .expect("the failures are there");

        match replies {
            rmpv::Value::Array(items) => {
                assert_eq!(items.len(), 3);
                // The failed command holds its place, so the list still lines up
                // with the commands that were sent.
                assert_eq!(items[1], rmpv::Value::Nil);
            }
            other => panic!("expected an array, got {other:?}"),
        }

        match errors {
            rmpv::Value::Map(pairs) => {
                assert_eq!(pairs.len(), 1);
                assert_eq!(pairs[0].0.as_u64(), Some(1));
            }
            other => panic!("expected a map, got {other:?}"),
        }
    }

    #[test]
    fn a_pipeline_without_failures_says_so() {
        let decoded = decode(&encode_pipeline(&[Value::Int(1)]).expect("encodable"));

        let rmpv::Value::Map(fields) = decoded else {
            panic!("expected a map");
        };

        let errors = fields
            .iter()
            .find(|(key, _)| key.as_str() == Some("e"))
            .map(|(_, value)| value)
            .expect("the failures field is always there");

        match errors {
            rmpv::Value::Map(pairs) => assert!(pairs.is_empty()),
            other => panic!("expected a map, got {other:?}"),
        }
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

        let decoded = decode(&encode_pipeline(&[value]).expect("encodable"));

        let rmpv::Value::Map(fields) = decoded else {
            panic!("expected a map");
        };

        let errors = fields
            .iter()
            .find(|(key, _)| key.as_str() == Some("e"))
            .map(|(_, value)| value)
            .expect("the failures field is always there");

        match errors {
            rmpv::Value::Map(pairs) => assert!(pairs.is_empty(), "a stored value forged a failure"),
            other => panic!("expected a map, got {other:?}"),
        }
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

    /// A server error to encode with: what the parser makes of an error reply,
    /// which is exactly what a connection hands back.
    fn server_error() -> Value {
        match redis::parse_redis_value(b"-WRONGTYPE bad\r\n") {
            Ok(value @ Value::ServerError(_)) => value,
            other => panic!("expected a server error value, got {other:?}"),
        }
    }
}
