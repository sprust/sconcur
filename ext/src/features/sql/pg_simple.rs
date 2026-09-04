//! Running a Postgres statement through the simple query protocol, so the server
//! answers in its text format.
//!
//! The reason is one line of sqlx: its executor binds every prepared statement
//! with `result_formats: &[PgValueFormat::Binary]` and offers no way to ask for
//! anything else. That is a fine default for an application that names its types
//! at compile time — sqlx decodes the binary form into the `T` it was asked for —
//! but this core has no types to name: the column type arrives in the server's
//! answer and the value leaves for PHP. Reproducing Postgres's text form from the
//! binary one means reimplementing its forty `*_out` functions, which is a lot of
//! code to write in order to get back what the server would have printed anyway.
//!
//! sqlx picks the protocol by whether arguments were passed, so the way to reach
//! the text format is to pass none. The parameters then have to travel some other
//! way, and they travel the way Postgres itself provides:
//!
//! ```sql
//! PREPARE sconcur_7 AS SELECT * FROM t WHERE id = $1;
//! EXECUTE sconcur_7($sc$5$sc$);
//! DEALLOCATE sconcur_7;
//! ```
//!
//! The caller's SQL is never parsed or rewritten — it goes into PREPARE verbatim,
//! so a `$1` inside a string literal of theirs stays a `$1` of theirs. The server
//! infers each parameter's type from where it appears, which is what pgx did and
//! what the Go core's behaviour rests on.
//!
//! Injection safety rests on dollar quoting rather than on escaping. A dollar
//! quoted literal has no escape sequences at all: nothing inside `$tag$…$tag$`
//! can end it except that exact tag, so a value cannot break out whatever it
//! holds — quotes, backslashes, semicolons — as long as the tag is not in the
//! value, which is checked. That property does not depend on
//! `standard_conforming_strings`, which escaping would.

use std::sync::atomic::{AtomicU64, Ordering};

use super::values::Binding;

/// Names have to be unique per connection, and a pool hands the same connection
/// to different callers over time. The counter is process-wide, which is
/// stricter than it needs to be and costs one relaxed increment.
static SEQUENCE: AtomicU64 = AtomicU64::new(0);

/// The statement to send. Without parameters the caller's SQL travels as it is;
/// with them it is wrapped in the PREPARE/EXECUTE/DEALLOCATE above.
///
/// All three run in one round trip, and the simple protocol wraps a multi
/// statement batch in a single implicit transaction — so a failure rolls the
/// PREPARE back with it and no name is left behind on the connection.
pub fn statement(sql: &str, bindings: &[Binding]) -> Result<String, String> {
    let trimmed = sql.trim().trim_end_matches(';');

    if bindings.is_empty() {
        return Ok(trimmed.to_string());
    }

    let name = format!("sconcur_{}", SEQUENCE.fetch_add(1, Ordering::Relaxed));

    let arguments = bindings
        .iter()
        .enumerate()
        .map(|(index, binding)| literal(binding, index))
        .collect::<Result<Vec<_>, String>>()?
        .join(", ");

    Ok(format!(
        "PREPARE {name} AS {trimmed}; EXECUTE {name}({arguments}); DEALLOCATE {name};"
    ))
}

/// One binding as a literal EXECUTE will accept.
///
/// Nothing is cast here. A parameter's type is the one PREPARE inferred for its
/// position, and an untyped literal is coerced into it by the server — which is
/// how a PHP string reaches a `numeric`, `date` or `uuid` column. Casting here
/// would break exactly that: `'2026-06-16'::text` is not assignable to a date.
fn literal(binding: &Binding, index: usize) -> Result<String, String> {
    Ok(match binding {
        Binding::Null => "NULL".to_string(),
        Binding::Bool(flag) => match flag {
            true => "TRUE".to_string(),
            false => "FALSE".to_string(),
        },
        Binding::Int(number) => number.to_string(),
        // {:?} rather than {}: it round-trips, where Display can shorten. The
        // non-finite three have no literal form and are cast, since there is no
        // column type they could be coerced into other than a float.
        Binding::Float(number) => match number.is_finite() {
            true => format!("{number:?}"),
            false => format!("'{number}'::float8"),
        },
        // Refused here rather than on the wire. Postgres holds no NUL in a text
        // value at all, and a statement carrying one comes back as "invalid
        // message format", which names neither the parameter nor the reason.
        // Binding the same bytes to a bytea parameter works, and is what the
        // error points at.
        Binding::Text(text) => match text.contains('\0') {
            true => {
                return Err(format!(
                    "binding {}: a text value cannot hold a NUL byte; bind it to a bytea column instead",
                    index + 1,
                ));
            }
            false => dollar_quote(text),
        },
        // Hex is the one encoding that is safe by construction: the literal holds
        // nothing but hex digits, whatever the bytes were. It also lifts the NUL
        // byte limit the text form has — bytea is the only place a PHP string of
        // arbitrary bytes has always belonged.
        Binding::Bytes(bytes) => {
            let hex = bytes
                .iter()
                .map(|byte| format!("{byte:02x}"))
                .collect::<String>();

            format!("'\\x{hex}'::bytea")
        }
    })
}

/// Wraps a value in a dollar quoted literal, with a tag the value does not
/// contain. The tag grows until it is absent rather than being chosen at random:
/// the check is what makes the quoting safe, so it is the check that has to be
/// exhaustive, and a loop over a value is cheaper than convincing a reader that
/// a random tag cannot collide.
fn dollar_quote(text: &str) -> String {
    let mut tag = String::from("sc");

    while text.contains(&format!("${tag}$")) {
        tag.push('c');
    }

    format!("${tag}${text}${tag}$")
}

#[cfg(test)]
mod tests {
    use super::*;

    fn rendered(binding: &Binding) -> String {
        literal(binding, 0).unwrap()
    }

    #[test]
    fn a_statement_without_bindings_travels_unwrapped() {
        assert_eq!(statement("SELECT 1", &[]).unwrap(), "SELECT 1");
        assert_eq!(statement("  SELECT 1;  ", &[]).unwrap(), "SELECT 1");
    }

    /// The caller's SQL is not parsed, so a `$1` of theirs inside a string stays
    /// theirs — the reason this wraps the statement rather than substituting
    /// placeholders into it.
    fn wrapped(sql: &str, bindings: &[Binding]) -> String {
        let rendered = statement(sql, bindings).unwrap();
        let start = rendered.find(" AS ").unwrap() + 4;
        let end = rendered.find("; EXECUTE ").unwrap();

        rendered[start..end].to_string()
    }

    #[test]
    fn the_callers_sql_is_passed_through_untouched() {
        let sql = "SELECT 'costs $1 dollars' AS note, $1::int AS value";

        assert_eq!(wrapped(sql, &[Binding::Int(4)]), sql);
    }

    /// Nothing a value can hold ends a dollar quoted literal, and a value that
    /// holds the tag gets a longer one.
    #[test]
    fn a_value_cannot_break_out_of_its_literal() {
        assert_eq!(rendered(&Binding::Text("plain".into())), "$sc$plain$sc$");
        assert_eq!(
            rendered(&Binding::Text("'; DROP TABLE t; --".into())),
            "$sc$'; DROP TABLE t; --$sc$"
        );
        assert_eq!(rendered(&Binding::Text("a\\b".into())), "$sc$a\\b$sc$");
        assert_eq!(rendered(&Binding::Text("$sc$".into())), "$scc$$sc$$scc$");
        assert_eq!(
            rendered(&Binding::Text("$sc$ $scc$".into())),
            "$sccc$$sc$ $scc$$sccc$"
        );

        // A NUL is refused with a message, not sent for the server to reject
        // with "invalid message format".
        assert!(literal(&Binding::Text("a\0b".into()), 0).is_err());
    }

    #[test]
    fn the_scalar_literals_are_what_postgres_reads_back() {
        assert_eq!(rendered(&Binding::Null), "NULL");
        assert_eq!(rendered(&Binding::Bool(true)), "TRUE");
        assert_eq!(rendered(&Binding::Bool(false)), "FALSE");
        assert_eq!(rendered(&Binding::Int(-7)), "-7");
        assert_eq!(rendered(&Binding::Float(1.5)), "1.5");
        assert_eq!(rendered(&Binding::Float(f64::NAN)), "'NaN'::float8");

        // bytea takes the NUL a text value cannot, and hex is safe whatever the
        // bytes are.
        assert_eq!(
            rendered(&Binding::Bytes(vec![0x00, 0x01, 0xff])),
            "'\\x0001ff'::bytea"
        );
    }
}
