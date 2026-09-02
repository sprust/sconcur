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
            | "BOOL" | "YEAR" => row
                .try_get::<i64, _>(index)
                .map(ColumnValue::Int)
                .map_err(|error| error.to_string())?,
            "TINYINT UNSIGNED" | "SMALLINT UNSIGNED" | "MEDIUMINT UNSIGNED" | "INT UNSIGNED"
            | "BIGINT UNSIGNED" => row
                .try_get::<u64, _>(index)
                .map(|number| ColumnValue::Int(number as i64))
                .map_err(|error| error.to_string())?,
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
                .try_get_unchecked::<String, _>(index)
                .map(|text| ColumnValue::Text(text.into_bytes()))
                .map_err(|error| error.to_string())?,
            // Everything else — CHAR/VARCHAR/TEXT/BLOB/BINARY/JSON/ENUM/SET —
            // travels as raw bytes, so a BLOB survives byte for byte.
            _ => ColumnValue::Text(
                row.try_get::<Vec<u8>, _>(index)
                    .map_err(|error| error.to_string())?,
            ),
        };

        values.push(value);
    }

    Ok(values)
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
            "NUMERIC" | "DECIMAL" => row
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
            _ => ColumnValue::Text(
                row.try_get_unchecked::<String, _>(index)
                    .map(String::into_bytes)
                    .map_err(|error| error.to_string())?,
            ),
        };

        values.push(value);
    }

    Ok(values)
}
