//! Typing Postgres parameters.
//!
//! Postgres refuses a `text` parameter where the column is `numeric`, `date` or
//! `uuid` — it has no implicit cast in that direction. PHP nonetheless sends
//! `'123.4500'` for a numeric column and `'2026-06-16'` for a date one, and the
//! Go build accepts both, because pgx leaves such a parameter's type
//! unspecified and sends it in *text* format for the server to coerce.
//!
//! That escape is closed here: sqlx sends every parameter in binary format
//! (hardcoded in its executor), so an unspecified parameter would be resolved to
//! `numeric` by the server and then parsed with numeric's *binary* reader,
//! which is how `invalid sign in external "numeric" value` happens.
//!
//! So the type is asked for instead of guessed: the statement is described
//! before it runs — the same Parse/Describe round-trip sqlx caches with the
//! prepared statement — and each text binding is converted to the type the
//! server says that position holds.

use sqlx::postgres::PgTypeInfo;
use sqlx::types::BigDecimal;
use std::str::FromStr;

use super::values::Binding;

/// A binding already in the type its parameter slot expects.
pub enum PgBound {
    /// A binding the server would have refused. Carried rather than thrown at
    /// conversion time so the failure surfaces as the statement's error, where
    /// a caller expects it.
    Invalid(String),
    Null,
    Bool(bool),
    /// Postgres parameter widths are exact: an i64 sent where the column is
    /// int4 is rejected as "incorrect binary data format", so the width follows
    /// the described parameter rather than the value.
    SmallInt(i16),
    Int4(i32),
    Int(i64),
    Float4(f32),
    Float(f64),
    Text(String),
    /// Raw bytes bound as `text`. Not the same as `Bytes`: this is what a PHP
    /// string that is not valid UTF-8 becomes, and the server rejecting it (a
    /// NUL byte, bad encoding) is the behaviour the Go build has, because a Go
    /// string carries arbitrary bytes into a text parameter too.
    RawText(Vec<u8>),
    Bytes(Vec<u8>),
    Decimal(BigDecimal),
    Date(chrono::NaiveDate),
    Timestamp(chrono::NaiveDateTime),
    TimestampTz(chrono::DateTime<chrono::Utc>),
}

/// The first binding the server would have refused, if any. Checked before the
/// statement runs so the failure names the binding rather than surfacing as a
/// protocol error further down.
pub fn first_invalid(bounds: &[PgBound]) -> Option<&str> {
    bounds.iter().find_map(|bound| match bound {
        PgBound::Invalid(error) => Some(error.as_str()),
        _ => None,
    })
}

/// Converts the bindings to the types the described parameters call for.
/// Positions the description does not cover keep their natural type.
pub fn coerce(bindings: &[Binding], parameters: &[PgTypeInfo]) -> Vec<PgBound> {
    bindings
        .iter()
        .enumerate()
        .map(|(index, binding)| coerce_one(binding, parameters.get(index)))
        .collect()
}

fn coerce_one(binding: &Binding, parameter: Option<&PgTypeInfo>) -> PgBound {
    let name = parameter.map(type_name);

    let text = match binding {
        Binding::Null => return PgBound::Null,
        Binding::Bool(flag) => return PgBound::Bool(*flag),
        Binding::Int(number) => return coerce_int(*number, name.as_deref()),
        Binding::Float(number) => return coerce_float(*number, name.as_deref()),
        Binding::Bytes(bytes) => {
            // A PHP string that is not valid UTF-8. It reaches the server as a
            // text parameter in Go, so its encoding is checked there — including
            // for a bytea slot, whose input is text like any other.
            return match name.as_deref() {
                Some("BYTEA") => PgBound::Invalid(
                    "invalid byte sequence for encoding \"UTF8\"".to_string(),
                ),
                _ => PgBound::RawText(bytes.clone()),
            };
        }
        Binding::Text(text) => text,
    };

    let Some(name) = name else {
        return PgBound::Text(text.clone());
    };

    match name.as_str() {
        "NUMERIC" | "DECIMAL" => match BigDecimal::from_str(text) {
            Ok(number) => PgBound::Decimal(number),
            // Not a number after all: hand the original over and let the server
            // produce the error, rather than inventing one here.
            Err(_) => PgBound::Text(text.clone()),
        },
        "DATE" => match chrono::NaiveDate::parse_from_str(text, "%Y-%m-%d") {
            Ok(date) => PgBound::Date(date),
            Err(_) => PgBound::Text(text.clone()),
        },
        "TIMESTAMP" => match parse_naive_timestamp(text) {
            Some(stamp) => PgBound::Timestamp(stamp),
            None => PgBound::Text(text.clone()),
        },
        "TIMESTAMPTZ" => match chrono::DateTime::parse_from_rfc3339(text) {
            Ok(stamp) => PgBound::TimestampTz(stamp.with_timezone(&chrono::Utc)),
            Err(_) => match parse_naive_timestamp(text) {
                Some(stamp) => PgBound::TimestampTz(stamp.and_utc()),
                None => PgBound::Text(text.clone()),
            },
        },
        "INT2" | "INT4" | "INT8" => match text.parse::<i64>() {
            Ok(number) => coerce_int(number, Some(name.as_str())),
            Err(_) => PgBound::Text(text.clone()),
        },
        "FLOAT4" | "FLOAT8" => match text.parse::<f64>() {
            Ok(number) => coerce_float(number, Some(name.as_str())),
            Err(_) => PgBound::Text(text.clone()),
        },
        "BOOL" => match text.as_str() {
            "1" | "t" | "true" | "TRUE" => PgBound::Bool(true),
            "0" | "f" | "false" | "FALSE" => PgBound::Bool(false),
            _ => PgBound::Text(text.clone()),
        },
        // pgx sends a string parameter in text format, so a bytea slot runs it
        // through the server's byteain. sqlx can only send binary format, where
        // bytea takes the bytes verbatim — which would quietly accept input the
        // server would have rejected.
        //
        // So byteain is applied here instead. It is the same emulation the
        // numeric and date arms above already do, for the same reason: the
        // conversion the server would have performed on a text-format parameter
        // has to happen somewhere, and this side is the only one left. The
        // difference from Go is where the error is raised, not whether it is.
        "BYTEA" => match byteain(text) {
            Ok(bytes) => PgBound::Bytes(bytes),
            Err(error) => PgBound::Invalid(error),
        },
        _ => PgBound::Text(text.clone()),
    }
}

/// Narrows an integer to the described parameter's width, and converts it
/// where the slot is not an integer at all (a numeric column, a bool flag).
fn coerce_int(number: i64, name: Option<&str>) -> PgBound {
    match name {
        Some("INT2") => match i16::try_from(number) {
            Ok(narrowed) => PgBound::SmallInt(narrowed),
            // Out of range: send it as-is and let the server say so.
            Err(_) => PgBound::Int(number),
        },
        Some("INT4") => match i32::try_from(number) {
            Ok(narrowed) => PgBound::Int4(narrowed),
            Err(_) => PgBound::Int(number),
        },
        Some("NUMERIC") | Some("DECIMAL") => PgBound::Decimal(BigDecimal::from(number)),
        Some("FLOAT4") => PgBound::Float4(number as f32),
        Some("FLOAT8") => PgBound::Float(number as f64),
        Some("BOOL") => PgBound::Bool(number != 0),
        _ => PgBound::Int(number),
    }
}

fn coerce_float(number: f64, name: Option<&str>) -> PgBound {
    match name {
        Some("FLOAT4") => PgBound::Float4(number as f32),
        Some("NUMERIC") | Some("DECIMAL") => match BigDecimal::from_str(&number.to_string()) {
            Ok(decimal) => PgBound::Decimal(decimal),
            Err(_) => PgBound::Float(number),
        },
        Some("INT2") => PgBound::SmallInt(number as i16),
        Some("INT4") => PgBound::Int4(number as i32),
        Some("INT8") => PgBound::Int(number as i64),
        _ => PgBound::Float(number),
    }
}

fn parse_naive_timestamp(text: &str) -> Option<chrono::NaiveDateTime> {
    chrono::NaiveDateTime::parse_from_str(text, "%Y-%m-%d %H:%M:%S")
        .or_else(|_| chrono::NaiveDateTime::parse_from_str(text, "%Y-%m-%d %H:%M:%S%.f"))
        .or_else(|_| chrono::NaiveDateTime::parse_from_str(text, "%Y-%m-%dT%H:%M:%S"))
        .or_else(|_| chrono::NaiveDateTime::parse_from_str(text, "%Y-%m-%dT%H:%M:%S%.f"))
        .ok()
}

fn type_name(parameter: &PgTypeInfo) -> String {
    use sqlx::TypeInfo;

    parameter.name().to_uppercase()
}

/// Applies Postgres' `byteain` to a text binding: the hex form (`\x` followed
/// by hex digits), the escape form (`\\` for a backslash, `\nnn` for an octal
/// byte), and anything else taken literally.
///
/// A NUL is rejected outright, as it is for any text input — that is what makes
/// binary data sent as a string an error rather than a silent success.
fn byteain(text: &str) -> Result<Vec<u8>, String> {
    if text.as_bytes().contains(&0) {
        return Err("invalid byte sequence for encoding \"UTF8\": 0x00".to_string());
    }

    let raw = text.as_bytes();

    if let Some(hexadecimal) = text.strip_prefix("\\x") {
        let digits = hexadecimal.as_bytes();

        if digits.len() % 2 != 0 {
            return Err("invalid input syntax for type bytea".to_string());
        }

        let mut decoded = Vec::with_capacity(digits.len() / 2);

        for pair in digits.chunks(2) {
            let high = (pair[0] as char)
                .to_digit(16)
                .ok_or_else(|| "invalid input syntax for type bytea".to_string())?;
            let low = (pair[1] as char)
                .to_digit(16)
                .ok_or_else(|| "invalid input syntax for type bytea".to_string())?;

            decoded.push((high * 16 + low) as u8);
        }

        return Ok(decoded);
    }

    let mut decoded = Vec::with_capacity(raw.len());
    let mut index = 0;

    while index < raw.len() {
        if raw[index] != b'\\' {
            decoded.push(raw[index]);
            index += 1;

            continue;
        }

        // A lone trailing backslash is not a valid escape.
        if index + 1 >= raw.len() {
            return Err("invalid input syntax for type bytea".to_string());
        }

        if raw[index + 1] == b'\\' {
            decoded.push(b'\\');
            index += 2;

            continue;
        }

        if index + 3 < raw.len() {
            let octal = &text[index + 1..index + 4];

            if let Ok(byte) = u8::from_str_radix(octal, 8) {
                decoded.push(byte);
                index += 4;

                continue;
            }
        }

        return Err("invalid input syntax for type bytea".to_string());
    }

    Ok(decoded)
}

/// Bytes bound as `text`. sqlx cannot build a `&str` from bytes that are not
/// valid UTF-8, so the encoding is written by hand and the server validates it —
/// which is the point (see PgBound::RawText).
pub struct PgRawText<'a>(pub &'a [u8]);

impl sqlx::Type<sqlx::Postgres> for PgRawText<'_> {
    fn type_info() -> PgTypeInfo {
        <String as sqlx::Type<sqlx::Postgres>>::type_info()
    }
}

impl<'q> sqlx::Encode<'q, sqlx::Postgres> for PgRawText<'_> {
    fn encode_by_ref(
        &self,
        buffer: &mut sqlx::postgres::PgArgumentBuffer,
    ) -> Result<sqlx::encode::IsNull, sqlx::error::BoxDynError> {
        buffer.extend_from_slice(self.0);

        Ok(sqlx::encode::IsNull::No)
    }
}
