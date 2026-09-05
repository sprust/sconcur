//! Connection strings.
//!
//! The PHP side emits MySQL DSNs in go-sql-driver's format, and that is part of
//! the wire contract — the demo server, the benchmarks and the tests all write
//! `user:pass@tcp(host:port)/db?parseTime=true`. Postgres is already a URL and
//! passes straight through; that format is not one and has to be parsed.
//!
//! Rewriting the PHP side to emit URLs was the alternative and was rejected:
//! the point of the spike is a core an unmodified package can load.

use sqlx::mysql::MySqlConnectOptions;
use std::str::FromStr;

/// Parses a go-sql-driver MySQL DSN:
///
///   [user[:password]@][tcp(host[:port])][/database][?param=value&...]
///
/// The parameters are that driver's options (`parseTime`, `loc`, `charset`, …).
/// The ones that change observable behaviour are read and the rest dropped:
/// passing them on would make the options parser reject the DSN, and an unknown
/// option is ignored rather than refused.
pub fn mysql_options(dsn: &str) -> Result<MySqlConnectOptions, String> {
    // Already a URL (the tests use both shapes for Postgres; accept it here too).
    if dsn.starts_with("mysql://") {
        return MySqlConnectOptions::from_str(dsn).map_err(|error| error.to_string());
    }

    let (credentials, rest) = match dsn.rfind('@') {
        Some(index) => (&dsn[..index], &dsn[index + 1..]),
        None => ("", dsn),
    };

    let (user, password) = match credentials.find(':') {
        Some(index) => (&credentials[..index], Some(&credentials[index + 1..])),
        None => (credentials, None),
    };

    // rest is "tcp(host:port)/database?params" — or "host:port/database?params"
    // if the protocol wrapper is absent.
    let (address, tail) = match rest.find(')') {
        Some(index) => {
            let open = rest.find('(').ok_or("malformed mysql dsn: ')' without '('")?;

            (&rest[open + 1..index], &rest[index + 1..])
        }
        None => match rest.find('/') {
            Some(index) => (&rest[..index], &rest[index..]),
            None => (rest, ""),
        },
    };

    let path = tail.strip_prefix('/').unwrap_or(tail);

    let (database, _parameters) = match path.find('?') {
        Some(index) => (&path[..index], &path[index + 1..]),
        None => (path, ""),
    };

    let (host, port) = match address.rfind(':') {
        Some(index) => (
            &address[..index],
            address[index + 1..]
                .parse::<u16>()
                .map_err(|error| format!("malformed mysql dsn port: {error}"))?,
        ),
        None => (address, 3306),
    };

    let mut options = MySqlConnectOptions::new()
        .host(if host.is_empty() { "127.0.0.1" } else { host })
        .port(port);

    if !user.is_empty() {
        options = options.username(user);
    }

    if let Some(password) = password {
        options = options.password(password);
    }

    if !database.is_empty() {
        options = options.database(database);
    }

    Ok(options)
}
