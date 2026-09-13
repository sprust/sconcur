//! The DSN a Redis payload carries, and what it is allowed to say.
//!
//! redis-rs parses the URL itself, including `?protocol=`, so nothing here
//! rebuilds that. What it adds is the refusal: redis-rs reads the query
//! parameters it knows and ignores the rest, and a parameter silently ignored
//! means the connection does not behave the way the configuration says it does.

/// The query parameters redis-rs acts on. `db`, `user` and `pass` only mean
/// something on a unix-socket URL, where the path is the socket; on a TCP URL
/// the same three come from the URL itself and are refused here rather than
/// accepted into nothing.
const TCP_PARAMS: &[&str] = &["protocol"];
const UNIX_PARAMS: &[&str] = &["protocol", "db", "user", "pass"];

#[derive(Debug)]
pub struct Dsn {
    /// The URL as it goes to redis-rs, unchanged.
    pub url: String,
}

/// Validates the DSN and hands back what `redis::Client::open` is given. The
/// scheme check is here rather than left to redis-rs so the message names the
/// four schemes instead of reporting a parse failure.
pub fn parse(dsn: &str) -> Result<Dsn, String> {
    let trimmed = dsn.trim();

    if trimmed.is_empty() {
        return Err("empty dsn".to_string());
    }

    let is_unix = trimmed.starts_with("unix://") || trimmed.starts_with("redis+unix://");

    if !is_unix
        && !trimmed.starts_with("redis://")
        && !trimmed.starts_with("rediss://")
    {
        return Err(format!(
            "unsupported dsn scheme in {trimmed}: expected redis://, rediss://, unix:// or redis+unix://"
        ));
    }

    let allowed = if is_unix { UNIX_PARAMS } else { TCP_PARAMS };

    for (name, value) in query_pairs(trimmed) {
        if !allowed.contains(&name.as_str()) {
            return Err(format!(
                "unknown dsn parameter {name}: this dsn accepts {}",
                allowed.join(", ")
            ));
        }

        // RESP3 changes the shape of what several commands answer — HGETALL
        // becomes a map, a scored range becomes pairs — and the typed methods on
        // the PHP side are written against RESP2. Accepting it would mean handing
        // those methods a shape they read wrongly, quietly. Refused until the
        // reply shape travels with the reply.
        if name == "protocol" && (value == "3" || value.eq_ignore_ascii_case("resp3")) {
            return Err(
                "protocol=3 (RESP3) is not supported yet: it changes the reply shape of \
                 several commands, and this feature reads them as RESP2"
                    .to_string(),
            );
        }
    }

    Ok(Dsn {
        url: trimmed.to_string(),
    })
}

/// The parameters of the query string, in order.
///
/// The value is percent-decoded, because the driver decodes it: redis-rs reads
/// the query with `url::Url::query_pairs`, which is form-urlencoded. Reading it
/// raw here meant the two sides disagreed about what the dsn said — `protocol=3`
/// was refused while `protocol=%33` sailed past and opened a RESP3 connection
/// this feature cannot read correctly.
fn query_pairs(dsn: &str) -> Vec<(String, String)> {
    let Some((_, query)) = dsn.split_once('?') else {
        return Vec::new();
    };

    // A fragment is not part of the query. redis-rs reads `#insecure` on a
    // rediss:// URL, so it is left to it rather than treated as a parameter.
    let query = query.split('#').next().unwrap_or(query);

    query
        .split('&')
        .filter(|pair| !pair.is_empty())
        .map(|pair| match pair.split_once('=') {
            Some((name, value)) => (name.to_lowercase(), percent_decode(value)),
            None => (pair.to_lowercase(), String::new()),
        })
        .collect()
}

/// Percent-decoding, and the `+` a form-urlencoded query means a space by.
///
/// A byte that is not valid UTF-8 once decoded is left as it was written: this
/// reads one value, and a value that decodes into nonsense is refused by the
/// comparison that follows rather than by this function.
fn percent_decode(value: &str) -> String {
    let bytes = value.as_bytes();
    let mut decoded: Vec<u8> = Vec::with_capacity(bytes.len());
    let mut index = 0;

    while index < bytes.len() {
        match bytes[index] {
            b'+' => {
                decoded.push(b' ');
                index += 1;
            }
            b'%' if index + 2 < bytes.len() => {
                let high = (bytes[index + 1] as char).to_digit(16);
                let low = (bytes[index + 2] as char).to_digit(16);

                match (high, low) {
                    (Some(high), Some(low)) => {
                        decoded.push((high * 16 + low) as u8);
                        index += 3;
                    }
                    _ => {
                        decoded.push(bytes[index]);
                        index += 1;
                    }
                }
            }
            byte => {
                decoded.push(byte);
                index += 1;
            }
        }
    }

    String::from_utf8(decoded).unwrap_or_else(|_| value.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn accepts_the_four_schemes() {
        for dsn in [
            "redis://127.0.0.1:6379/0",
            "rediss://user:pass@example.com:6380/1",
            "unix:///var/run/redis.sock",
            "redis+unix:///var/run/redis.sock?db=2",
        ] {
            assert!(parse(dsn).is_ok(), "{dsn} should parse");
        }
    }

    #[test]
    fn refuses_another_scheme() {
        let error = parse("http://127.0.0.1:6379").unwrap_err();

        assert!(error.contains("unsupported dsn scheme"), "{error}");
    }

    #[test]
    fn refuses_an_empty_dsn() {
        assert!(parse("   ").is_err());
    }

    #[test]
    fn accepts_the_protocol_parameter_at_resp2() {
        assert!(parse("redis://127.0.0.1:6379/0?protocol=2").is_ok());
    }

    #[test]
    fn refuses_resp3() {
        for dsn in [
            "redis://127.0.0.1:6379/0?protocol=3",
            "redis://127.0.0.1:6379/0?protocol=resp3",
            "redis://127.0.0.1:6379/0?protocol=RESP3",
            // Percent-encoded, which the driver decodes and this used to not.
            "redis://127.0.0.1:6379/0?protocol=%33",
            "redis://127.0.0.1:6379/0?protocol=%72esp3",
            "redis://127.0.0.1:6379/0?protocol=res%70%33",
        ] {
            let error = parse(dsn).unwrap_err();

            assert!(error.contains("RESP3"), "{dsn}: {error}");
        }
    }

    #[test]
    fn refuses_an_unknown_parameter() {
        let error = parse("redis://127.0.0.1:6379/0?pool_size=4").unwrap_err();

        assert!(error.contains("unknown dsn parameter pool_size"), "{error}");
    }

    #[test]
    fn refuses_a_tcp_only_parameter_on_a_tcp_url() {
        // db belongs in the path of a TCP url; as a parameter it would be read
        // by nothing.
        assert!(parse("redis://127.0.0.1:6379/?db=3").is_err());
    }

    #[test]
    fn accepts_the_unix_parameters_on_a_socket_url() {
        assert!(parse("unix:///var/run/redis.sock?db=1&user=u&pass=p&protocol=2").is_ok());
    }

    #[test]
    fn a_value_is_read_the_way_the_driver_reads_it() {
        assert_eq!(percent_decode("%33"), "3");
        assert_eq!(percent_decode("resp3"), "resp3");
        assert_eq!(percent_decode("a+b"), "a b");
        // A stray percent is not an escape and is left alone.
        assert_eq!(percent_decode("100%"), "100%");
        assert_eq!(percent_decode("%zz"), "%zz");
    }

    #[test]
    fn keeps_the_dsn_verbatim() {
        let dsn = parse("redis://127.0.0.1:6379/7?protocol=2").unwrap();

        assert_eq!(dsn.url, "redis://127.0.0.1:6379/7?protocol=2");
    }

    #[test]
    fn leaves_the_insecure_fragment_to_the_driver() {
        assert!(parse("rediss://127.0.0.1:6379/0?protocol=2#insecure").is_ok());
    }
}
