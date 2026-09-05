//! Connection strings.
//!
//! The PHP side emits MySQL DSNs in go-sql-driver's format, and that is part of
//! the wire contract — the demo server, the benchmarks and the tests all write
//! `user:pass@tcp(host:port)/db?parseTime=true`. Postgres is already a URL and
//! passes straight through; that format is not one and has to be parsed.
//!
//! Rewriting the PHP side to emit URLs was the alternative and was rejected:
//! the point of the spike is a core an unmodified package can load.

use sqlx::mysql::{MySqlConnectOptions, MySqlSslMode};
use std::str::FromStr;

/// The parameters this core knows how to honour, and what they map to, are in
/// `apply_parameter`. A parameter that only ever meant something to the Go
/// client is listed here instead: it is accepted and does nothing, because the
/// value it carried never reached the server in the first place.
///
/// - `parseTime`, `loc`, `timeTruncate`: how that driver turned a DATE/DATETIME
///   into a Go `time.Time`. Values reach PHP as RFC3339 text, rendered by
///   `features/sql/values.rs`.
/// - `interpolateParams`: whether bindings were pasted into the statement. Here
///   the protocol is chosen per statement — see `drivers::mysql_is_textual`.
/// - `columnsWithAlias`, `allowNativePasswords`, `allowOldPasswords`,
///   `allowCleartextPasswords`, `allowAllFiles`, `allowFallbackToPlaintext`,
///   `checkConnLiveness`, `compress`, `connectionAttributes`,
///   `maxAllowedPacket`, `rejectReadOnly`, `serverPubKey`: capability flags,
///   buffer sizes and auth switches of that client, with no counterpart in sqlx.
/// - `timeout`, `readTimeout`, `writeTimeout`: its connect and I/O deadlines.
///   Query and exec are bounded by the `timeoutMs` of the PHP `Connection`
///   instead, the connect included.
const IGNORED_PARAMETERS: &[&str] = &[
    "allowAllFiles",
    "allowCleartextPasswords",
    "allowFallbackToPlaintext",
    "allowNativePasswords",
    "allowOldPasswords",
    "checkConnLiveness",
    "columnsWithAlias",
    "compress",
    "connectionAttributes",
    "interpolateParams",
    "loc",
    "maxAllowedPacket",
    "parseTime",
    "readTimeout",
    "rejectReadOnly",
    "serverPubKey",
    "timeTruncate",
    "timeout",
    "writeTimeout",
];

/// The two capability flags sqlx negotiates unconditionally, with the value it
/// always ends up with. The DSN may say the same thing and is then accepted; it
/// may not say the opposite, because that is the one case where a dropped
/// parameter changes an answer the caller reads — `clientFoundRows` decides
/// whether `affectedRows` counts matched or changed rows.
const FIXED_FLAGS: [(&str, bool); 2] = [("multiStatements", true), ("clientFoundRows", true)];

/// What the driver settles on its own and a session variable must not fight
/// over. sqlx issues its own `SET` for these when a connection is opened, and a
/// DSN value would be applied on top of it — for `time_zone` there is a real
/// option, and the other two would leave the connection decoding text the driver
/// believes is something else.
const RESERVED_VARIABLES: [&str; 3] = ["character_set_results", "character_set_client", "names"];

/// A MySQL DSN, parsed.
#[derive(Debug)]
pub struct MysqlDsn {
    pub options: MySqlConnectOptions,
    /// The parameters that are neither a connect option nor a flag: this format
    /// reads them as session system variables, and `pools` issues them as
    /// `SET name=value` on every new connection.
    pub session_variables: Vec<(String, String)>,
}

/// Parses a go-sql-driver MySQL DSN:
///
///   [user[:password]@][tcp(host[:port])|unix(/path/to.sock)][/database][?param=value&...]
///
/// Parameters are read rather than dropped. One that maps onto a connect option
/// is applied (`apply_parameter`), one that only ever configured the Go client
/// is accepted and does nothing (`IGNORED_PARAMETERS`), and anything else is
/// what this format says it is — a session system variable, carried out to
/// `pools` and issued as `SET name=value` on every new connection. A caller who
/// wrote `charset=latin1` and got utf8mb4 had no way to find out; now either it
/// is applied or the DSN is refused by name.
pub fn mysql_dsn(dsn: &str) -> Result<MysqlDsn, String> {
    // Already a URL (the tests use both shapes for Postgres; accept it here too).
    if dsn.starts_with("mysql://") {
        let options = MySqlConnectOptions::from_str(dsn).map_err(|error| error.to_string())?;

        return Ok(MysqlDsn {
            options: with_session_defaults(options),
            session_variables: Vec::new(),
        });
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
    // if the protocol wrapper is absent. The wrapper names the transport, so
    // `unix(...)` holds a socket path rather than a host.
    let (protocol, address, tail) = match rest.find(')') {
        Some(index) => {
            let open = rest.find('(').ok_or("malformed mysql dsn: ')' without '('")?;

            (&rest[..open], &rest[open + 1..index], &rest[index + 1..])
        }
        None => match rest.find('/') {
            Some(index) => ("", &rest[..index], &rest[index..]),
            None => ("", rest, ""),
        },
    };

    let path = tail.strip_prefix('/').unwrap_or(tail);

    let (database, parameters) = match path.find('?') {
        Some(index) => (&path[..index], &path[index + 1..]),
        None => (path, ""),
    };

    let mut options = MySqlConnectOptions::new();

    options = match protocol {
        "unix" => options.socket(address),
        "tcp" | "" => {
            let (host, port) = split_address(address)?;

            options.host(host).port(port)
        }
        other => {
            return Err(format!(
                "unsupported mysql dsn protocol {other:?}; use tcp(host:port) or unix(/path/to.sock)"
            ));
        }
    };

    if !user.is_empty() {
        options = options.username(user);
    }

    if let Some(password) = password {
        options = options.password(password);
    }

    if !database.is_empty() {
        options = options.database(database);
    }

    let mut session_variables = Vec::new();

    for parameter in parameters.split('&') {
        if parameter.is_empty() {
            continue;
        }

        let (name, value) = match parameter.find('=') {
            Some(index) => (&parameter[..index], decode(&parameter[index + 1..])?),
            None => (parameter, String::new()),
        };

        match apply_parameter(options, name, &value)? {
            Applied::Option(applied) => options = applied,
            Applied::SessionVariable(applied) => {
                options = applied;

                session_variables.push((name.to_string(), value));
            }
        }
    }

    Ok(MysqlDsn {
        options: with_session_defaults(options),
        session_variables,
    })
}

fn split_address(address: &str) -> Result<(&str, u16), String> {
    let (host, port) = match address.rfind(':') {
        Some(index) => (
            &address[..index],
            address[index + 1..]
                .parse::<u16>()
                .map_err(|error| format!("malformed mysql dsn port: {error}"))?,
        ),
        None => (address, 3306),
    };

    Ok((if host.is_empty() { "127.0.0.1" } else { host }, port))
}

/// What became of one DSN parameter. The options travel through either way, so
/// the caller does not have to thread two values.
enum Applied {
    Option(MySqlConnectOptions),
    SessionVariable(MySqlConnectOptions),
}

/// Applies one DSN parameter, or says why it cannot be.
fn apply_parameter(
    options: MySqlConnectOptions,
    name: &str,
    value: &str,
) -> Result<Applied, String> {
    match name {
        "charset" => Ok(Applied::Option(options.charset(value))),
        "collation" => Ok(Applied::Option(options.collation(value))),
        // A system variable in this format, so it arrives quoted:
        // `time_zone=%27%2B00%3A00%27`. It is taken as an option rather than a
        // `SET` because sqlx sends one of its own on connect (`+00:00`), and two
        // writers of the same variable is one more than needed.
        "time_zone" => Ok(Applied::Option(options.timezone(Some(unquote(value).to_string())))),
        "tls" => Ok(Applied::Option(options.ssl_mode(ssl_mode(value)?))),
        _ if IGNORED_PARAMETERS.contains(&name) => Ok(Applied::Option(options)),
        _ => match FIXED_FLAGS.iter().find(|(flag, _)| *flag == name) {
            Some((flag, fixed)) => match boolean(value) {
                Some(asked) if asked == *fixed => Ok(Applied::Option(options)),
                _ => Err(format!(
                    "mysql dsn {flag}={value}: this driver always negotiates {flag}={fixed} and cannot turn it off"
                )),
            },
            None if RESERVED_VARIABLES.contains(&name.to_ascii_lowercase().as_str()) => Err(format!(
                "mysql dsn {name}: the driver sets this itself; use the charset and collation parameters"
            )),
            None => {
                identifier(name)?;

                Ok(Applied::SessionVariable(options))
            }
        },
    }
}

/// A session variable name goes into a `SET` statement unquoted, so it is held
/// to what a system variable may be spelled with. The value is not: this format
/// carries it already quoted where quoting is needed, exactly as the Go client
/// passes it on.
fn identifier(name: &str) -> Result<(), String> {
    let valid = !name.is_empty()
        && name
            .chars()
            .all(|character| character.is_ascii_alphanumeric() || character == '_' || character == '.');

    match valid {
        true => Ok(()),
        false => Err(format!("unsupported mysql dsn parameter {name:?}")),
    }
}

/// The spellings go-sql-driver's `readBool` accepts.
fn boolean(value: &str) -> Option<bool> {
    match value.to_ascii_lowercase().as_str() {
        "1" | "t" | "true" => Some(true),
        "0" | "f" | "false" => Some(false),
        _ => None,
    }
}

/// go-sql-driver's `tls` values. It reads the boolean ones through the same
/// `readBool` as every other flag, so `1` and `T` are `true` there and are here;
/// the two named ones it lowercases first.
///
/// A named custom TLS configuration is registered in that client's own registry,
/// which has no counterpart here, so it is refused rather than silently
/// downgraded to an unencrypted connection.
fn ssl_mode(value: &str) -> Result<MySqlSslMode, String> {
    if let Some(flag) = boolean(value) {
        return Ok(match flag {
            // Verifies the chain and the host name, which is what that client's
            // `true` does with an empty tls.Config carrying only a ServerName.
            true => MySqlSslMode::VerifyIdentity,
            false => MySqlSslMode::Disabled,
        });
    }

    match value.to_ascii_lowercase().as_str() {
        "preferred" => Ok(MySqlSslMode::Preferred),
        // Encrypt, but check nothing about the certificate.
        "skip-verify" => Ok(MySqlSslMode::Required),
        _ => Err(format!(
            "unsupported mysql dsn tls value {value:?}; use true, false, skip-verify or preferred"
        )),
    }
}

/// The session settings sqlx would otherwise pick on its own.
///
/// `PIPES_AS_CONCAT` is the one that changes what a query means: with it `||` is
/// string concatenation instead of MySQL's OR, so the same statement gives
/// different answers on a sqlx connection and on a PDO one. Nobody asked for
/// that, so it is turned off and MySQL's own reading of `||` is kept.
///
/// What is left of sqlx's own session setup is in docs/mysql.md: it appends
/// `NO_ENGINE_SUBSTITUTION` to the server's `sql_mode`, sets `time_zone` to
/// `+00:00` unless the DSN names one, asks for `IGNORE_SPACE`, `MULTI_STATEMENTS`
/// and `FOUND_ROWS` in the handshake capabilities, which are not options at all,
/// and defaults TLS to `Preferred` where this DSN format defaults to off.
fn with_session_defaults(options: MySqlConnectOptions) -> MySqlConnectOptions {
    options.pipes_as_concat(false)
}

/// Percent-decoding, the one escape this DSN dialect uses in a parameter value.
/// `+` is left alone on purpose: it is a literal in the values that carry one
/// (`time_zone=%27%2B00%3A00%27` decodes to `'+00:00'`).
fn decode(value: &str) -> Result<String, String> {
    if !value.contains('%') {
        return Ok(value.to_string());
    }

    let bytes = value.as_bytes();
    let mut decoded = Vec::with_capacity(bytes.len());
    let mut index = 0;

    while index < bytes.len() {
        if bytes[index] != b'%' {
            decoded.push(bytes[index]);
            index += 1;

            continue;
        }

        let digits = value
            .get(index + 1..index + 3)
            .ok_or_else(|| format!("malformed percent-encoding in mysql dsn: {value:?}"))?;

        let byte = u8::from_str_radix(digits, 16)
            .map_err(|_| format!("malformed percent-encoding in mysql dsn: {value:?}"))?;

        decoded.push(byte);
        index += 3;
    }

    String::from_utf8(decoded).map_err(|error| format!("malformed mysql dsn parameter: {error}"))
}

/// Strips the single quotes a system-variable value carries in this dialect.
fn unquote(value: &str) -> &str {
    value
        .strip_prefix('\'')
        .and_then(|value| value.strip_suffix('\''))
        .unwrap_or(value)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn options(dsn: &str) -> MySqlConnectOptions {
        mysql_dsn(dsn).unwrap().options
    }

    #[test]
    fn the_address_credentials_and_database_are_read() {
        let options = options("sc_user:secret@tcp(db.host:3307)/u_test");

        assert_eq!(options.get_host(), "db.host");
        assert_eq!(options.get_port(), 3307);
        assert_eq!(options.get_username(), "sc_user");
        assert_eq!(options.get_database(), Some("u_test"));
    }

    /// A password holding an `@` is why the split is on the last one.
    #[test]
    fn the_credentials_split_on_the_last_at_sign() {
        let options = options("sc_user:p@ss@tcp(host:3306)/db");

        assert_eq!(options.get_username(), "sc_user");
        assert_eq!(options.get_host(), "host");
    }

    #[test]
    fn a_missing_port_falls_back_to_the_default() {
        assert_eq!(options("root@tcp(host)/db").get_port(), 3306);
    }

    /// `unix(...)` used to be read as a host name, which produced a TCP connect
    /// to a host that does not exist.
    #[test]
    fn a_unix_address_becomes_a_socket_path() {
        let options = options("root:pass@unix(/var/run/mysqld/mysqld.sock)/db");

        assert_eq!(
            options.get_socket().map(|path| path.to_string_lossy().to_string()),
            Some("/var/run/mysqld/mysqld.sock".to_string()),
        );
    }

    #[test]
    fn an_unknown_protocol_is_refused() {
        assert!(mysql_dsn("root@pipe(mysql)/db").is_err());
    }

    /// The parameters that reach the server are applied, not dropped.
    #[test]
    fn charset_and_collation_reach_the_options() {
        let options = options("root@tcp(host:3306)/db?charset=utf8mb4&collation=utf8mb4_unicode_ci");

        assert_eq!(options.get_charset(), "utf8mb4");
        assert_eq!(options.get_collation(), Some("utf8mb4_unicode_ci"));
    }

    #[test]
    fn the_tls_values_map_onto_ssl_modes() {
        // MySqlSslMode carries no PartialEq, so the shape is matched rather than
        // compared.
        let mode = |value: &str| options(&format!("root@tcp(host:3306)/db?tls={value}")).get_ssl_mode();

        assert!(matches!(mode("false"), MySqlSslMode::Disabled));
        assert!(matches!(mode("preferred"), MySqlSslMode::Preferred));
        assert!(matches!(mode("skip-verify"), MySqlSslMode::Required));
        assert!(matches!(mode("true"), MySqlSslMode::VerifyIdentity));

        // The spellings that client's readBool takes, and the case it folds.
        assert!(matches!(mode("1"), MySqlSslMode::VerifyIdentity));
        assert!(matches!(mode("0"), MySqlSslMode::Disabled));
        assert!(matches!(mode("T"), MySqlSslMode::VerifyIdentity));
        assert!(matches!(mode("SKIP-VERIFY"), MySqlSslMode::Required));

        // A named custom configuration has no counterpart here.
        assert!(mysql_dsn("root@tcp(host:3306)/db?tls=custom").is_err());
    }

    /// A value the Go client percent-encoded arrives decoded.
    #[test]
    fn a_percent_encoded_value_is_decoded() {
        assert_eq!(decode("%27%2B00%3A00%27").unwrap(), "'+00:00'");
        assert_eq!(unquote(&decode("%27%2B00%3A00%27").unwrap()), "+00:00");
        assert!(decode("%2").is_err());
        assert!(decode("%zz").is_err());
    }

    /// The Go-client-only options stay accepted — every DSN in this repository
    /// carries `parseTime=true` — and none of them becomes a session variable.
    #[test]
    fn a_go_client_only_parameter_is_accepted_and_does_nothing() {
        let parsed = mysql_dsn("root@tcp(host:3306)/db?parseTime=true&loc=UTC&rejectReadOnly=true").unwrap();

        assert!(parsed.session_variables.is_empty());
    }

    /// This format reads a parameter it has no option for as a session system
    /// variable, which is how `transaction_isolation` and friends are set. They
    /// used to be dropped without a word.
    #[test]
    fn an_unnamed_parameter_becomes_a_session_variable() {
        let parsed =
            mysql_dsn("root@tcp(host:3306)/db?transaction_isolation=%27READ-COMMITTED%27&group_concat_max_len=4096")
                .unwrap();

        assert_eq!(
            parsed.session_variables,
            vec![
                ("transaction_isolation".to_string(), "'READ-COMMITTED'".to_string()),
                ("group_concat_max_len".to_string(), "4096".to_string()),
            ],
        );
    }

    /// A name that could not be a system variable is refused rather than pasted
    /// into the `SET` statement pools.rs builds.
    #[test]
    fn a_parameter_name_that_is_not_an_identifier_is_refused() {
        let error = mysql_dsn("root@tcp(host:3306)/db?a b=1").unwrap_err();

        assert!(error.contains("a b"), "{error}");

        assert!(mysql_dsn("root@tcp(host:3306)/db?x;DROP=1").is_err());
    }

    /// The driver negotiates both flags unconditionally, so the DSN may confirm
    /// them and may not contradict them.
    #[test]
    fn a_flag_the_driver_cannot_turn_off_is_refused_only_when_contradicted() {
        assert!(mysql_dsn("root@tcp(host:3306)/db?multiStatements=true").is_ok());
        assert!(mysql_dsn("root@tcp(host:3306)/db?clientFoundRows=1").is_ok());

        let error = mysql_dsn("root@tcp(host:3306)/db?clientFoundRows=false").unwrap_err();

        assert!(error.contains("clientFoundRows"), "{error}");

        assert!(mysql_dsn("root@tcp(host:3306)/db?multiStatements=false").is_err());
    }

    /// What sqlx writes on connect is not left for a session variable to fight
    /// over.
    #[test]
    fn a_variable_the_driver_owns_is_refused() {
        assert!(mysql_dsn("root@tcp(host:3306)/db?character_set_client=latin1").is_err());
        assert!(mysql_dsn("root@tcp(host:3306)/db?NAMES=latin1").is_err());
    }
}
