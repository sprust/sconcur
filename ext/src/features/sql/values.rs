//! Turning the bindings PHP sent
//! into driver arguments, and driver rows into the PHP values a result carries.
//!
//! The shapes here are a wire contract, not a preference. `tests/feature/
//! Features/Mysql/MysqlTypesTest.php` pins them: integers arrive as integers,
//! DECIMAL as the string `'123.4500'`, TINYINT(1) as `1` and not `true`,
//! DATE/DATETIME as an RFC3339 string, BLOB as a string that survives
//! `\x00`..`\xff` byte for byte, NULL as null. Each of those is an explicit
//! decision here, made once and pinned by the test.

use sqlx::{Column, Row, TypeInfo, ValueRef};

use crate::dto::{Map, Value};

/// One binding, normalized out of the PHP value into something a driver
/// accepts: the driver's converter sees one integer type and one float type,
/// and text apart from bytes.
pub enum Binding {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    Text(String),
    Bytes(Vec<u8>),
}

/// A binding that is an array or an object is refused here, by position: a driver
/// would store the text of it in a VARCHAR column without a word, and this is the
/// one place that knows it was never a scalar.
pub fn normalize_bindings(values: &[Value]) -> Result<Vec<Binding>, String> {
    values
        .iter()
        .enumerate()
        .map(|(index, value)| {
            normalize_binding(value).ok_or_else(|| format!("binding {index} is an array or an object, not a value a driver can bind"))
        })
        .collect()
}

fn normalize_binding(value: &Value) -> Option<Binding> {
    match value {
        Value::Nil => Some(Binding::Null),
        Value::Bool(flag) => Some(Binding::Bool(*flag)),
        Value::Int(number) => Some(Binding::Int(*number)),
        Value::Float(number) => Some(Binding::Float(*number)),
        // A PHP string is one type for text and bytes alike, so a BLOB binding
        // arrives as a string holding bytes that are not valid UTF-8. Keep
        // those as bytes instead of lossily converting them.
        Value::Str(bytes) => match std::str::from_utf8(bytes) {
            Ok(text) => Some(Binding::Text(text.to_string())),
            Err(_) => Some(Binding::Bytes(bytes.clone())),
        },
        // An array or an object is not something a driver can bind, and sent as
        // text it would be stored, not refused: the caller hears about it here.
        Value::List(_) | Value::Map(_) | Value::Object(_) => None,
    }
}

/// A column value on its way back to PHP, in the shape the PHP side expects.
pub enum ColumnValue {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    /// Text or bytes. Handed to PHP as one string either way, because PHP has
    /// one string type and the PHP side expects a plain string — including for
    /// a BLOB whose bytes are not valid UTF-8.
    Text(Vec<u8>),
}

/// A DATETIME in the shape the PHP side expects (see the DATETIME arms below):
/// RFC3339 with the fractional second trimmed of its trailing zeroes, which is
/// the shape PHP has always been handed:
/// `.5`, not `.500`. chrono's own AutoSi pads to three, six or nine places
/// instead, and the difference reaches any code comparing the string.
fn render_rfc3339(stamp: chrono::DateTime<chrono::Utc>) -> Vec<u8> {
    let mut rendered = stamp.format("%Y-%m-%dT%H:%M:%S").to_string();

    let nanoseconds = stamp.timestamp_subsec_nanos();

    if nanoseconds != 0 {
        let fraction = format!("{nanoseconds:09}");

        rendered.push('.');
        rendered.push_str(fraction.trim_end_matches('0'));
    }

    rendered.push('Z');

    rendered.into_bytes()
}

/// A DATE in the shape the PHP side expects (see the DATE arms below).
fn render_date(date: chrono::NaiveDate) -> Vec<u8> {
    date.and_hms_opt(0, 0, 0)
        .map(|stamp| {
            stamp
                .and_utc()
                .to_rfc3339_opts(chrono::SecondsFormat::Secs, true)
                .into_bytes()
        })
        .unwrap_or_else(|| date.to_string().into_bytes())
}

/// A batch of rows as a list of maps, one map per row keyed by column name.
/// An empty batch is an empty list, never null, so the PHP side always gets a
/// list.
pub fn batch_value(columns: &[String], rows: &[Vec<ColumnValue>]) -> Value {
    let mut batch = Vec::with_capacity(rows.len());

    for row in rows {
        let mut row_map = Map::with_capacity(columns.len());

        for (index, column) in columns.iter().enumerate() {
            let value = match row.get(index) {
                Some(ColumnValue::Null) | None => Value::Nil,
                Some(ColumnValue::Bool(flag)) => Value::Bool(*flag),
                Some(ColumnValue::Int(number)) => Value::Int(*number),
                Some(ColumnValue::Float(number)) => Value::Float(*number),
                Some(ColumnValue::Text(bytes)) => Value::Str(bytes.clone()),
            };

            row_map.push(column.as_str(), value);
        }

        batch.push(Value::from(row_map));
    }

    Value::List(batch)
}

/// The answer to an Exec: affected rows and last insert id, under the same two
/// keys the PHP side reads.
pub fn exec_result_value(affected_rows: i64, last_insert_id: i64) -> Value {
    Map::new().set("ar", affected_rows).set("li", last_insert_id).into()
}

/// Reads one MySQL row into the wire shapes above, dispatching on the column's
/// SQL type. `try_get_unchecked` is deliberate where it appears: it skips
/// sqlx's compile-time-ish type compatibility check, which is exactly what a
/// dynamic result set needs — the type is decided here, at runtime, from the
/// column.
pub fn read_mysql_row(row: &sqlx::mysql::MySqlRow) -> Result<Vec<ColumnValue>, String> {
    let mut values = Vec::with_capacity(row.columns().len());

    for (index, column) in row.columns().iter().enumerate() {
        let raw = row.try_get_raw(index).map_err(|error| error.to_string())?;

        if raw.is_null() {
            values.push(ColumnValue::Null);

            continue;
        }

        let type_name = column.type_info().name().to_uppercase();

        let value = match type_name.as_str() {
            // TINYINT(1) included: it reaches PHP as an integer, and the PHP
            // side asserts 1, not true.
            "TINYINT" | "SMALLINT" | "MEDIUMINT" | "INT" | "INTEGER" | "BIGINT" | "BOOLEAN"
            | "BOOL" | "YEAR" | "TINYINT UNSIGNED" | "SMALLINT UNSIGNED"
            | "MEDIUMINT UNSIGNED" | "INT UNSIGNED" | "BIGINT UNSIGNED" => {
                read_mysql_integer(row, index)?
            }
            "FLOAT" | "DOUBLE" | "REAL" => row
                .try_get::<f64, _>(index)
                .map(ColumnValue::Float)
                .map_err(|error| error.to_string())?,
            // DECIMAL keeps its exact text form — turning it into a float would
            // lose the very precision the column exists for.
            "DECIMAL" | "NUMERIC" => row
                .try_get::<sqlx::types::BigDecimal, _>(index)
                .map(|number| ColumnValue::Text(number.to_string().into_bytes()))
                .map_err(|error| error.to_string())?,
            // Rendered as a full RFC3339 timestamp at midnight UTC, not as a
            // bare date: a DATE reaches PHP as "2026-12-06T00:00:00Z", and
            // application code may well be parsing that shape.
            "DATE" => row
                .try_get::<chrono::NaiveDate, _>(index)
                .map(|date| ColumnValue::Text(render_date(date)))
                .map_err(|error| error.to_string())?,
            "DATETIME" => row
                .try_get::<chrono::NaiveDateTime, _>(index)
                .map(|stamp| ColumnValue::Text(render_rfc3339(stamp.and_utc())))
                .map_err(|error| error.to_string())?,
            // Separately from DATETIME, and not by preference: sqlx pairs
            // NaiveDateTime with DATETIME and DateTime<Utc> with TIMESTAMP, and
            // refuses the crossed pair. Reading both as NaiveDateTime is what the
            // port did, so a TIMESTAMP column failed its whole query — one of the
            // two column types anybody writing a schema reaches for first.
            "TIMESTAMP" => row
                .try_get::<chrono::DateTime<chrono::Utc>, _>(index)
                .map(|stamp| ColumnValue::Text(render_rfc3339(stamp)))
                .map_err(|error| error.to_string())?,
            "TIME" => row
                .try_get::<sqlx::mysql::types::MySqlTime, _>(index)
                .map(|time| ColumnValue::Text(render_mysql_time(&time)))
                .map_err(|error| error.to_string())?,
            // Everything else — CHAR/VARCHAR/TEXT/BLOB/BINARY/JSON/BIT/GEOMETRY/
            // ENUM/SET — travels as raw bytes, so a BLOB survives byte for byte.
            //
            // Unchecked on purpose. sqlx's compatibility list for Vec<u8> covers
            // the string and blob types only, so the checked form refuses JSON,
            // BIT and GEOMETRY outright and fails the whole query — while the
            // bytes it would have handed back are exactly what this arm wants.
            _ => ColumnValue::Text(
                row.try_get_unchecked::<Vec<u8>, _>(index)
                    .map_err(|error| error.to_string())?,
            ),
        };

        values.push(value);
    }

    Ok(values)
}

/// MySQL's type name does not reliably say whether a column is signed:
/// `TINYINT(1) UNSIGNED` reports as `BOOLEAN`, and `YEAR` is unsigned while
/// reading like a plain year. sqlx checks signedness when it decodes, so the
/// honest way to ask is to try both — a signed decode of an unsigned column is
/// refused without consuming anything, so the fallback costs one failed
/// compatibility check and nothing else.
///
/// A value past `i64::MAX` becomes its decimal string rather than a wrapped
/// negative. docs/mysql.md already tells callers to read such a column as a
/// string; `18446744073709551615` arriving as `-1` is the one outcome nobody
/// can act on, because nothing about it says anything went wrong.
fn read_mysql_integer(row: &sqlx::mysql::MySqlRow, index: usize) -> Result<ColumnValue, String> {
    if let Ok(number) = row.try_get::<i64, _>(index) {
        return Ok(ColumnValue::Int(number));
    }

    let number = row
        .try_get::<u64, _>(index)
        .map_err(|error| error.to_string())?;

    if number > i64::MAX as u64 {
        return Ok(ColumnValue::Text(number.to_string().into_bytes()));
    }

    Ok(ColumnValue::Int(number as i64))
}

/// MySQL's TIME is a signed span, not a clock reading: it ranges over
/// ±838:59:59, which `chrono::NaiveTime` cannot hold, so sqlx carries it in
/// `MySqlTime`. Rendered here the way the text protocol wrote it — zero-padded
/// hours, and a fractional part only when there is one — because that is the
/// shape PHP receives.
///
/// Decoding this as a `String` is what the port did before, and `try_get_unchecked`
/// meant nothing caught it: the binary protocol keeps TIME's length byte in the
/// value, so a column holding 14:30:00 reached PHP as nine bytes of control
/// characters, with no error anywhere.
fn render_mysql_time(time: &sqlx::mysql::types::MySqlTime) -> Vec<u8> {
    // Through sign(), not MySqlTime::is_negative(): that method is
    // `self.sign.is_positive()` in sqlx 0.8.6 — a copy-paste slip that answers
    // true for every positive time. The enum's own is_negative() is correct.
    let sign = if time.sign().is_negative() { "-" } else { "" };

    let mut rendered = format!(
        "{sign}{:02}:{:02}:{:02}",
        time.hours(),
        time.minutes(),
        time.seconds(),
    );

    let microseconds = time.microseconds();

    if microseconds != 0 {
        rendered.push_str(&format!(".{microseconds:06}"));
    }

    rendered.into_bytes()
}

/// Reads one Postgres row.
///
/// The values arrive in the *text* format — see [`super::pg_simple`] for why the
/// statement is sent the way it is — so a column is already the string Postgres
/// would have printed. That is the shape the PHP side has always been handed,
/// because that is the form the PHP side has always been handed.
///
/// So most types need no work at all: an array is `{1,NULL,3}`, an interval is
/// `1 day`, a composite is `(1,a)`, a `NUMERIC` keeps the scale its column
/// declared. What is left is the handful of columns whose PHP shape is not a
/// string, and the three date shapes that differ from what the server prints.
pub fn read_pg_row(row: &sqlx::postgres::PgRow) -> Result<Vec<ColumnValue>, String> {
    let mut values = Vec::with_capacity(row.columns().len());

    for (index, column) in row.columns().iter().enumerate() {
        let raw = row.try_get_raw(index).map_err(|error| error.to_string())?;

        if raw.is_null() {
            values.push(ColumnValue::Null);

            continue;
        }

        let bytes = raw.as_bytes().map_err(|error| error.to_string())?;
        let type_name = column.type_info().name().to_uppercase();

        let value = match type_name.as_str() {
            "INT2" | "SMALLINT" | "INT4" | "INT" | "INTEGER" | "INT8" | "BIGINT" | "OID" => {
                parse_pg_int(bytes, &type_name, column.name())?
            }
            "BOOL" | "BOOLEAN" => ColumnValue::Bool(bytes == b"t"),
            // Widened from f32 rather than parsed as f64: pgx handed database/sql
            // a float32 and PHP got the double it widens to, so 0.1::float4 has
            // always arrived as 0.10000000149011612 and not as 0.1.
            "FLOAT4" | "REAL" => parse_pg_float(bytes, &type_name, column.name(), true)?,
            "FLOAT8" | "DOUBLE PRECISION" => {
                parse_pg_float(bytes, &type_name, column.name(), false)?
            }
            // A date reaches PHP as an RFC3339 timestamp and a bare DATE as
            // midnight UTC. The server prints neither, so these three are the
            // only reformatting left. Both infinities pass through as the words
            // they are.
            "DATE" | "TIMESTAMP" | "TIMESTAMPTZ" => render_pg_stamp(bytes, &type_name)?,
            // BYTEA prints as hex, and PHP has always received the bytes.
            "BYTEA" => ColumnValue::Text(decode_pg_hex(bytes, column.name())?),
            // xid and cid reach here unnamed: sqlx resolves a type name only for
            // the types it knows, and the simple protocol does no Describe to
            // fill in the rest. They are integers to PHP like their sibling OID,
            // so they are recognised by the OID the row description carries.
            _ => match column.type_info().oid().map(|oid| oid.0) {
                Some(28 | 29) => parse_pg_int(bytes, &type_name, column.name())?,
                _ => ColumnValue::Text(bytes.to_vec()),
            },
        };

        values.push(value);
    }

    Ok(values)
}

/// An integer column, which Postgres prints and PHP expects as an int.
fn parse_pg_int(bytes: &[u8], type_name: &str, column_name: &str) -> Result<ColumnValue, String> {
    let text = pg_text(bytes, type_name, column_name)?;

    text.parse::<i64>()
        .map(ColumnValue::Int)
        .map_err(|error| format!("column {column_name} ({type_name}): {error}"))
}

fn parse_pg_float(
    bytes: &[u8],
    type_name: &str,
    column_name: &str,
    single: bool,
) -> Result<ColumnValue, String> {
    let text = pg_text(bytes, type_name, column_name)?;

    // Postgres spells these as words; Rust parses them under other names, so
    // they are named here rather than left to a parser that would refuse them.
    let number = match text {
        "NaN" => f64::NAN,
        "Infinity" => f64::INFINITY,
        "-Infinity" => f64::NEG_INFINITY,
        _ => match single {
            true => f64::from(
                text.parse::<f32>()
                    .map_err(|error| format!("column {column_name} ({type_name}): {error}"))?,
            ),
            false => text
                .parse::<f64>()
                .map_err(|error| format!("column {column_name} ({type_name}): {error}"))?,
        },
    };

    Ok(ColumnValue::Float(number))
}

fn pg_text<'a>(
    bytes: &'a [u8],
    type_name: &str,
    column_name: &str,
) -> Result<&'a str, String> {
    std::str::from_utf8(bytes)
        .map_err(|error| format!("column {column_name} ({type_name}): {error}"))
}

/// `DATE`, `TIMESTAMP` and `TIMESTAMPTZ` as RFC3339, which is the one shape the
/// server's own text form does not already provide. A `DATE` becomes midnight
/// UTC, a `TIMESTAMPTZ` is normalised to UTC, and the fractional second keeps
/// only the digits it has — `.5`, not `.500`.
fn render_pg_stamp(bytes: &[u8], type_name: &str) -> Result<ColumnValue, String> {
    let text = std::str::from_utf8(bytes).map_err(|error| error.to_string())?;

    // The infinities have no calendar form, and the server names them.
    if text == "infinity" || text == "-infinity" {
        return Ok(ColumnValue::Text(bytes.to_vec()));
    }

    // There is no year zero in the calendar Postgres prints, so 1 BC is the year
    // ISO numbers 0. chrono counts the ISO way and cannot read the suffix, so the
    // era is folded into the year before it is handed over.
    let (text, era) = match text.strip_suffix(" BC") {
        Some(without) => (without.to_string(), true),
        None => (text.to_string(), false),
    };

    let text = match era {
        false => text,
        true => shift_pg_era(&text)?,
    };

    let text = text.as_str();

    let stamp = match type_name {
        "DATE" => chrono::NaiveDate::parse_from_str(text, "%Y-%m-%d")
            .map_err(|error| format!("date {text:?}: {error}"))?
            .and_hms_opt(0, 0, 0)
            .unwrap()
            .and_utc(),
        "TIMESTAMPTZ" => chrono::DateTime::parse_from_str(&pad_pg_offset(text), "%Y-%m-%d %H:%M:%S%.f%z")
            .map_err(|error| format!("timestamptz {text:?}: {error}"))?
            .with_timezone(&chrono::Utc),
        _ => chrono::NaiveDateTime::parse_from_str(text, "%Y-%m-%d %H:%M:%S%.f")
            .map_err(|error| format!("timestamp {text:?}: {error}"))?
            .and_utc(),
    };

    Ok(ColumnValue::Text(render_rfc3339(stamp)))
}

/// Postgres prints an offset as `+02`, `-05:30` or `+00`; chrono's `%z` wants
/// four digits, so the hour-only form is filled out.
fn pad_pg_offset(text: &str) -> String {
    let Some(position) = text.rfind(['+', '-']).filter(|position| *position > 10) else {
        return text.to_string();
    };

    let (stamp, offset) = text.split_at(position);

    match offset.len() {
        3 => format!("{stamp}{offset}00"),
        _ => format!("{stamp}{}", offset.replace(':', "")),
    }
}

/// BYTEA's text form is `\x` followed by hex, which is turned back into the
/// bytes PHP has always received. (The `escape` form predates 9.0 and is not
/// emitted by a server this core can talk to.)
fn decode_pg_hex(bytes: &[u8], column_name: &str) -> Result<Vec<u8>, String> {
    let Some(hex) = bytes.strip_prefix(b"\\x") else {
        return Ok(bytes.to_vec());
    };

    if hex.len() % 2 != 0 {
        return Err(format!("column {column_name}: bytea has an odd hex length"));
    }

    hex.chunks(2)
        .map(|pair| {
            let text = std::str::from_utf8(pair).map_err(|error| error.to_string())?;

            u8::from_str_radix(text, 16).map_err(|error| error.to_string())
        })
        .collect::<Result<Vec<u8>, String>>()
        .map_err(|error| format!("column {column_name}: bytea: {error}"))
}

/// Rewrites a BC date's year the way ISO 8601 counts it: 1 BC is year 0, 2 BC is
/// year -1. Applied to the text, before parsing, because chrono has no era.
fn shift_pg_era(text: &str) -> Result<String, String> {
    let (year, rest) = text
        .split_once('-')
        .ok_or_else(|| format!("date {text:?}: no year"))?;

    let year: i32 = year
        .parse()
        .map_err(|error| format!("date {text:?}: {error}"))?;

    Ok(format!("{:04}-{rest}", 1 - year))
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The shape PHP's RowsResult walks: a list of rows, each a map keyed by the
    /// column name in column order, with the kinds MysqlTypesTest pins.
    #[test]
    fn a_batch_is_a_list_of_column_keyed_rows() {
        let columns = vec!["id".to_string(), "name".to_string(), "price".to_string(), "blob".to_string(), "gone".to_string()];
        let rows = vec![vec![
            ColumnValue::Int(7),
            ColumnValue::Text(b"seven".to_vec()),
            ColumnValue::Text(b"123.4500".to_vec()),
            ColumnValue::Text(vec![0, 255, 7]),
            ColumnValue::Null,
        ]];

        let batch = batch_value(&columns, &rows);
        let rows = batch.as_list().expect("a list");

        assert_eq!(rows.len(), 1);

        let row = &rows[0];
        let keys: Vec<String> = row.as_map().unwrap().iter().map(|(key, _)| key.text()).collect();

        assert_eq!(keys, columns);
        assert_eq!(row.get("id"), Some(&Value::Int(7)));
        assert_eq!(row.get("name").and_then(Value::as_str), Some("seven"));
        assert_eq!(row.get("price").and_then(Value::as_str), Some("123.4500"));
        assert_eq!(row.get("blob").and_then(Value::as_bytes), Some(&[0u8, 255, 7][..]));
        assert_eq!(row.get("gone"), Some(&Value::Nil));
    }

    #[test]
    fn an_empty_batch_is_an_empty_list() {
        assert_eq!(batch_value(&["id".to_string()], &[]), Value::List(vec![]));
    }

    #[test]
    fn an_exec_result_carries_the_two_counters() {
        let value = exec_result_value(3, 42);

        assert_eq!(value.get("ar"), Some(&Value::Int(3)));
        assert_eq!(value.get("li"), Some(&Value::Int(42)));
        assert_eq!(value.as_map().unwrap().len(), 2);
    }

    #[test]
    fn bindings_keep_their_kinds_and_refuse_a_composite() {
        let bindings = normalize_bindings(&[
            Value::Nil,
            Value::Bool(true),
            Value::Int(5),
            Value::Float(2.5),
            Value::Str(b"text".to_vec()),
            Value::Str(vec![0xff, 0x00]),
        ])
        .expect("scalars bind");

        assert!(matches!(bindings[0], Binding::Null));
        assert!(matches!(bindings[1], Binding::Bool(true)));
        assert!(matches!(bindings[2], Binding::Int(5)));
        assert!(matches!(bindings[3], Binding::Float(number) if number == 2.5));
        assert!(matches!(&bindings[4], Binding::Text(text) if text == "text"));
        assert!(matches!(&bindings[5], Binding::Bytes(bytes) if bytes == &[0xff, 0x00]));

        let refused = match normalize_bindings(&[Value::Int(1), Value::List(vec![Value::Int(2)])]) {
            Err(error) => error,
            Ok(_) => panic!("a composite binding must be refused"),
        };

        assert!(refused.starts_with("binding 1 is an array or an object"), "{refused}");
    }
}
