//! Mirrors ext-go-legacy/internal/features/sql/values.go: turning MessagePack bindings
//! into driver arguments, and driver rows back into MessagePack.
//!
//! The shapes here are a wire contract, not a preference. `tests/feature/
//! Features/Mysql/MysqlTypesTest.php` pins them: integers arrive as integers,
//! DECIMAL as the string `'123.4500'`, TINYINT(1) as `1` and not `true`,
//! DATE/DATETIME as an RFC3339 string, BLOB as a string that survives
//! `\x00`..`\xff` byte for byte, NULL as null. Go reaches those shapes because
//! its driver hands back `[]byte` for most things and `time.Time` for dates
//! (parseTime=true); here every one of them is an explicit decision.

use rmp::encode;
use sqlx::{Column, Row, TypeInfo, ValueRef};

/// One binding, normalized out of MessagePack into something a driver accepts.
/// Go collapses msgpack's int8/int16/uint64/… into int64/float64 for the same
/// reason: the driver's converter should see one integer type, not nine.
pub enum Binding {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    Text(String),
    Bytes(Vec<u8>),
}

pub fn normalize_bindings(values: &[rmpv::Value]) -> Vec<Binding> {
    values.iter().map(normalize_binding).collect()
}

fn normalize_binding(value: &rmpv::Value) -> Binding {
    match value {
        rmpv::Value::Nil => Binding::Null,
        rmpv::Value::Boolean(flag) => Binding::Bool(*flag),
        rmpv::Value::Integer(number) => match number.as_i64() {
            Some(number) => Binding::Int(number),
            // Past i64 the value cannot be an integer argument; hand it over as
            // text rather than silently wrapping it.
            None => Binding::Text(number.to_string()),
        },
        rmpv::Value::F32(number) => Binding::Float(*number as f64),
        rmpv::Value::F64(number) => Binding::Float(*number),
        // PHP strings arrive as msgpack str; a binary payload (a BLOB binding)
        // arrives as str too, holding bytes that are not valid UTF-8. Keep those
        // as bytes instead of lossily converting them.
        rmpv::Value::String(text) => match text.as_str() {
            Some(text) => Binding::Text(text.to_string()),
            None => Binding::Bytes(text.as_bytes().to_vec()),
        },
        rmpv::Value::Binary(bytes) => Binding::Bytes(bytes.clone()),
        other => Binding::Text(other.to_string()),
    }
}

/// A column value on its way back to PHP, in the shape Go would have produced.
pub enum ColumnValue {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    /// Text or bytes. Written as a MessagePack *str* either way, because that is
    /// what Go's `string([]byte)` becomes on the wire and what the PHP side
    /// expects to read back as a plain string — including for a BLOB whose
    /// bytes are not valid UTF-8.
    Text(Vec<u8>),
}

/// Encodes a batch of rows as a MessagePack array of maps. An empty batch
/// encodes as an empty array, never null, so the PHP side always decodes a list.
/// A DATE in the shape the Go build produces (see the DATE arms above).
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

pub fn encode_batch(columns: &[String], rows: &[Vec<ColumnValue>]) -> Vec<u8> {
    let mut buffer = Vec::with_capacity(64 + rows.len() * columns.len() * 16);

    let _ = encode::write_array_len(&mut buffer, rows.len() as u32);

    for row in rows {
        let _ = encode::write_map_len(&mut buffer, columns.len() as u32);

        for (index, column) in columns.iter().enumerate() {
            let _ = encode::write_str(&mut buffer, column);

            match row.get(index) {
                Some(ColumnValue::Null) | None => {
                    let _ = encode::write_nil(&mut buffer);
                }
                Some(ColumnValue::Bool(flag)) => {
                    let _ = encode::write_bool(&mut buffer, *flag);
                }
                Some(ColumnValue::Int(number)) => {
                    let _ = encode::write_sint(&mut buffer, *number);
                }
                Some(ColumnValue::Float(number)) => {
                    let _ = encode::write_f64(&mut buffer, *number);
                }
                Some(ColumnValue::Text(bytes)) => {
                    let _ = encode::write_str_len(&mut buffer, bytes.len() as u32);
                    buffer.extend_from_slice(bytes);
                }
            }
        }
    }

    buffer
}

/// Encodes the answer to an Exec: affected rows and last insert id, under the
/// same two keys Go writes.
pub fn encode_exec_result(affected_rows: i64, last_insert_id: i64) -> Vec<u8> {
    let mut buffer = Vec::with_capacity(16);

    let _ = encode::write_map_len(&mut buffer, 2);
    let _ = encode::write_str(&mut buffer, "ar");
    let _ = encode::write_sint(&mut buffer, affected_rows);
    let _ = encode::write_str(&mut buffer, "li");
    let _ = encode::write_sint(&mut buffer, last_insert_id);

    buffer
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
            // TINYINT(1) included: Go's driver reports it as an integer and the
            // PHP side asserts 1, not true.
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
            // bare date: the Go driver runs with parseTime=true, so a DATE
            // reaches PHP as "2026-12-06T00:00:00Z" and application code may
            // well be parsing that shape.
            "DATE" => row
                .try_get::<chrono::NaiveDate, _>(index)
                .map(|date| ColumnValue::Text(render_date(date)))
                .map_err(|error| error.to_string())?,
            "DATETIME" | "TIMESTAMP" => row
                .try_get::<chrono::NaiveDateTime, _>(index)
                .map(|stamp| {
                    ColumnValue::Text(
                        stamp
                            .and_utc()
                            .to_rfc3339_opts(chrono::SecondsFormat::AutoSi, true)
                            .into_bytes(),
                    )
                })
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
/// shape Go's `[]byte` handed to PHP.
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

/// The Postgres counterpart. pgx reports the same logical shapes to PHP, so the
/// mapping targets are identical; only the type names differ.
pub fn read_pg_row(row: &sqlx::postgres::PgRow) -> Result<Vec<ColumnValue>, String> {
    let mut values = Vec::with_capacity(row.columns().len());

    for (index, column) in row.columns().iter().enumerate() {
        let raw = row.try_get_raw(index).map_err(|error| error.to_string())?;

        if raw.is_null() {
            values.push(ColumnValue::Null);

            continue;
        }

        let type_name = column.type_info().name().to_uppercase();

        let value = match type_name.as_str() {
            "INT2" | "SMALLINT" => row
                .try_get::<i16, _>(index)
                .map(|number| ColumnValue::Int(number as i64))
                .map_err(|error| error.to_string())?,
            "INT4" | "INT" | "INTEGER" => row
                .try_get::<i32, _>(index)
                .map(|number| ColumnValue::Int(number as i64))
                .map_err(|error| error.to_string())?,
            "INT8" | "BIGINT" => row
                .try_get::<i64, _>(index)
                .map(ColumnValue::Int)
                .map_err(|error| error.to_string())?,
            // pgx hands a bool to database/sql, which reaches PHP as a bool —
            // PgsqlTypesTest asserts exactly that.
            "BOOL" | "BOOLEAN" => row
                .try_get::<bool, _>(index)
                .map(ColumnValue::Bool)
                .map_err(|error| error.to_string())?,
            "FLOAT4" | "REAL" => row
                .try_get::<f32, _>(index)
                .map(|number| ColumnValue::Float(number as f64))
                .map_err(|error| error.to_string())?,
            "FLOAT8" | "DOUBLE PRECISION" => row
                .try_get::<f64, _>(index)
                .map(ColumnValue::Float)
                .map_err(|error| error.to_string())?,
            "NUMERIC" | "DECIMAL" => read_pg_numeric(row, index, &raw)?,
            // Rendered as a full RFC3339 timestamp at midnight UTC, not as a
            // bare date: the Go driver runs with parseTime=true, so a DATE
            // reaches PHP as "2026-12-06T00:00:00Z" and application code may
            // well be parsing that shape.
            "DATE" => row
                .try_get::<chrono::NaiveDate, _>(index)
                .map(|date| ColumnValue::Text(render_date(date)))
                .map_err(|error| error.to_string())?,
            "TIMESTAMP" => row
                .try_get::<chrono::NaiveDateTime, _>(index)
                .map(|stamp| {
                    ColumnValue::Text(
                        stamp
                            .and_utc()
                            .to_rfc3339_opts(chrono::SecondsFormat::AutoSi, true)
                            .into_bytes(),
                    )
                })
                .map_err(|error| error.to_string())?,
            "TIMESTAMPTZ" => row
                .try_get::<chrono::DateTime<chrono::Utc>, _>(index)
                .map(|stamp| {
                    ColumnValue::Text(
                        stamp
                            .to_rfc3339_opts(chrono::SecondsFormat::AutoSi, true)
                            .into_bytes(),
                    )
                })
                .map_err(|error| error.to_string())?,
            "BYTEA" => ColumnValue::Text(
                row.try_get::<Vec<u8>, _>(index)
                    .map_err(|error| error.to_string())?,
            ),
            // JSONB's binary form is a one-byte format version followed by the
            // JSON text. Read as a String it kept that byte, so PHP received
            // "\x01{...}" and json_decode answered null — a defect nothing
            // reports, because nothing about it looks like an error.
            "JSONB" => ColumnValue::Text(strip_jsonb_version(raw_bytes(&raw)?)),
            "UUID" => ColumnValue::Text(render_uuid(raw_bytes(&raw)?)?),
            "TIME" => row
                .try_get::<chrono::NaiveTime, _>(index)
                .map(|time| ColumnValue::Text(render_pg_time(time)))
                .map_err(|error| error.to_string())?,
            _ => read_pg_text(
                column.name(),
                &type_name,
                column.type_info().kind(),
                &raw,
            )?,
        };

        values.push(value);
    }

    Ok(values)
}

/// The value's bytes exactly as the server sent them.
fn raw_bytes<'a>(raw: &'a sqlx::postgres::PgValueRef<'a>) -> Result<&'a [u8], String> {
    raw.as_bytes().map_err(|error| error.to_string())
}

/// JSONB arrives as `[version][json text]`; version 1 is the only one Postgres
/// has ever sent. The byte is dropped rather than trusted blindly, so a future
/// version cannot be handed to PHP as if it were part of the document.
fn strip_jsonb_version(bytes: &[u8]) -> Vec<u8> {
    match bytes.split_first() {
        Some((1, rest)) => rest.to_vec(),
        _ => bytes.to_vec(),
    }
}

/// A UUID is sixteen raw bytes on the wire and canonical dashed text everywhere
/// a person reads one. Decoded as a String it was not valid UTF-8 and failed the
/// whole query.
fn render_uuid(bytes: &[u8]) -> Result<Vec<u8>, String> {
    if bytes.len() != 16 {
        return Err(format!("uuid: expected 16 bytes, got {}", bytes.len()));
    }

    let hex: String = bytes.iter().map(|byte| format!("{byte:02x}")).collect();

    Ok(format!(
        "{}-{}-{}-{}-{}",
        &hex[0..8],
        &hex[8..12],
        &hex[12..16],
        &hex[16..20],
        &hex[20..32],
    )
    .into_bytes())
}

/// Postgres TIME is microseconds since midnight in binary; pgx handed PHP the
/// text form, so this renders the shape MySQL's TIME renders.
fn render_pg_time(time: chrono::NaiveTime) -> Vec<u8> {
    use chrono::Timelike;

    let mut rendered = format!(
        "{:02}:{:02}:{:02}",
        time.hour(),
        time.minute(),
        time.second(),
    );

    let microseconds = time.nanosecond() / 1_000;

    if microseconds != 0 {
        rendered.push_str(&format!(".{microseconds:06}"));
    }

    rendered.into_bytes()
}

/// The fallback: everything whose binary form IS its text — TEXT, VARCHAR,
/// NAME, JSON, XML and the enum types.
///
/// It used to accept anything, decoding whatever bytes arrived as a String, and
/// that was wrong for every structured binary type in two different ways.
/// int4[], INTERVAL and INET happen to be valid UTF-8, so their wire structure
/// reached PHP dressed as a string with no error at all; UUID and TIME failed
/// UTF-8 and took the whole query down with a message naming neither the column
/// nor its type.
///
/// Note that a UTF-8 check is not enough to tell the two groups apart — that is
/// exactly what let the first group through. The decision has to be made on the
/// type, so this is an allow-list: a type not on it is refused by name, and the
/// error says what to do about it.
fn read_pg_text(
    column_name: &str,
    type_name: &str,
    kind: &sqlx::postgres::PgTypeKind,
    raw: &sqlx::postgres::PgValueRef<'_>,
) -> Result<ColumnValue, String> {
    if !pg_sends_text(type_name, kind) {
        return Err(format!(
            "column {column_name} ({type_name}) arrives in a binary form this core cannot render; cast it to text in the query, for example {column_name}::text"
        ));
    }

    let bytes = raw_bytes(raw)?;

    std::str::from_utf8(bytes)
        .map_err(|error| format!("column {column_name} ({type_name}): {error}"))?;

    Ok(ColumnValue::Text(bytes.to_vec()))
}

/// Whether a type's binary wire form is simply its text.
///
/// A domain is transparent — it is its underlying type with a constraint — so it
/// is unwrapped once. An enum travels as its label. Everything else with a
/// structured encoding (arrays, ranges, composites, INTERVAL, INET, MONEY, OID,
/// the geometric types) is refused rather than reinterpreted.
fn pg_sends_text(type_name: &str, kind: &sqlx::postgres::PgTypeKind) -> bool {
    use sqlx::postgres::PgTypeKind;

    match kind {
        PgTypeKind::Enum(_) => return true,
        PgTypeKind::Domain(inner) => {
            return pg_sends_text(&inner.name().to_uppercase(), inner.kind());
        }
        _ => {}
    }

    matches!(
        type_name,
        "TEXT"
            | "VARCHAR"
            | "CHAR"
            | "BPCHAR"
            | "NAME"
            | "JSON"
            | "XML"
            | "CITEXT"
            | "UNKNOWN"
            // VOID carries no bytes at all, which makes the empty string the
            // right and only rendering. pg_sleep() returns one, so refusing it
            // broke a test the moment the allow-list went in.
            | "VOID"
    )
}

/// NUMERIC has three values that are not numbers: NaN and the two infinities.
/// BigDecimal cannot hold them and sqlx's decoder refuses them, which failed the
/// whole result set where pgx handed the text form straight through.
///
/// They are read off the wire header rather than from the error text: the binary
/// form is four 16-bit fields, and the third is the sign.
fn read_pg_numeric(
    row: &sqlx::postgres::PgRow,
    index: usize,
    raw: &sqlx::postgres::PgValueRef<'_>,
) -> Result<ColumnValue, String> {
    if let Some(special) = pg_numeric_special(raw_bytes(raw)?) {
        return Ok(ColumnValue::Text(special.as_bytes().to_vec()));
    }

    row.try_get::<sqlx::types::BigDecimal, _>(index)
        .map(|number| ColumnValue::Text(number.to_string().into_bytes()))
        .map_err(|error| error.to_string())
}

fn pg_numeric_special(bytes: &[u8]) -> Option<&'static str> {
    if bytes.len() < 8 {
        return None;
    }

    match u16::from_be_bytes([bytes[4], bytes[5]]) {
        0xC000 => Some("NaN"),
        0xD000 => Some("Infinity"),
        0xF000 => Some("-Infinity"),
        _ => None,
    }
}
