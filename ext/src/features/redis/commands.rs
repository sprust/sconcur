//! Building a command, and the two lists that decide where it may run.

use redis::Cmd;

/// How the caller answered the question the command name cannot always answer.
/// PHP: the `bl` key of a Command payload.
pub const BLOCKING_BY_NAME: i64 = 0;
pub const BLOCKING_YES: i64 = 1;
pub const BLOCKING_NO: i64 = 2;

/// Commands that put the connection itself into a mode, and so cannot run on a
/// connection shared with other tasks. Each is refused by name, with the
/// replacement in the message: a silent one would corrupt every neighbour on
/// the same socket, which is a bug nobody could trace back to here.
///
/// The pairs are (command, what to use instead).
const REFUSED: &[(&str, &str)] = &[
    ("SUBSCRIBE", "use subscribe()"),
    ("UNSUBSCRIBE", "use the subscription's remove() or close()"),
    ("PSUBSCRIBE", "use subscribe(patterns:)"),
    ("PUNSUBSCRIBE", "use the subscription's remove() or close()"),
    ("SSUBSCRIBE", "sharded pub/sub is not supported"),
    ("SUNSUBSCRIBE", "sharded pub/sub is not supported"),
    ("MULTI", "use transaction()"),
    ("EXEC", "use transaction()"),
    ("DISCARD", "use transaction()"),
    ("WATCH", "optimistic locking is not supported yet"),
    ("UNWATCH", "optimistic locking is not supported yet"),
    ("SELECT", "put the database number in the dsn"),
    ("AUTH", "put the login and password in the dsn"),
    ("HELLO", "put ?protocol= in the dsn"),
    ("RESET", "this would reset a connection other tasks are using"),
    ("MONITOR", "this would turn a shared connection into a monitor"),
    ("SWAPDB", "this would move every connection of this process to another database"),
    ("QUIT", "this would close a connection other tasks are using"),
];

/// Subcommands refused on a shared connection, by (command, subcommand).
///
/// CLIENT as a whole is not refused, because most of it is harmless and useful —
/// LIST, INFO, ID, GETNAME, KILL all read or act on somebody else. These three do
/// not:
///
/// - CLIENT REPLY OFF|SKIP tells the server not to answer, and an answer that
///   never comes shifts the multiplexer's queue by one for the rest of the
///   connection's life. There is no I/O error, so nothing reconnects: every later
///   command on that connection reads the previous one's reply and eventually
///   times out. It is the worst thing on this list, because it is silent and
///   permanent;
/// - SETNAME, SETINFO, NO-TOUCH and NO-EVICT set something on a connection that
///   belongs to every coroutine in the process, so whatever they set is a lie for
///   all but one of them;
/// - PAUSE stops the whole server answering anybody, this process included.
const REFUSED_SUBCOMMANDS: &[(&str, &str, &str)] = &[
    (
        "CLIENT",
        "REPLY",
        "this would stop the server answering on a connection other tasks are reading",
    ),
    ("CLIENT", "SETNAME", "the connection is shared, so its name is not yours to set"),
    ("CLIENT", "SETINFO", "the connection is shared, so its attributes are not yours to set"),
    ("CLIENT", "NO-TOUCH", "the connection is shared, so this flag is not yours to set"),
    ("CLIENT", "NO-EVICT", "the connection is shared, so this flag is not yours to set"),
    ("CLIENT", "PAUSE", "this would stop the whole server answering, this process included"),
    ("CLIENT", "UNPAUSE", "pausing is refused, so there is nothing here to undo"),
];

/// Commands that wait on the server by definition. A command here holds the
/// server's attention on its connection for as long as it waits, so it is given
/// one of its own.
///
/// Not here, and deliberately: XREAD and XREADGROUP, which wait only when the
/// arguments say BLOCK. The side that built the arguments knows, and says so
/// with `bl`.
const BLOCKING: &[&str] = &[
    "BLPOP",
    "BRPOP",
    "BLMOVE",
    "BLMPOP",
    "BRPOPLPUSH",
    "BZPOPMIN",
    "BZPOPMAX",
    "BZMPOP",
    "WAIT",
    "WAITAOF",
];

/// The name as it goes on the wire and into the lists: Redis is
/// case-insensitive about command names, so the comparison must be too.
pub fn normalize_name(name: &str) -> String {
    name.trim().to_uppercase()
}

/// Whether this command may run on a connection shared with other tasks. The
/// arguments are read too, because for CLIENT it is the subcommand that decides.
pub fn refusal(name: &str, args: &[Vec<u8>]) -> Option<String> {
    if let Some((refused, replacement)) = REFUSED.iter().find(|(refused, _)| *refused == name) {
        return Some(format!(
            "{refused} would change the state of a shared connection: {replacement}"
        ));
    }

    let subcommand = args.first().map(|arg| String::from_utf8_lossy(arg).to_uppercase())?;

    REFUSED_SUBCOMMANDS
        .iter()
        .find(|(command, refused, _)| *command == name && *refused == subcommand)
        .map(|(command, refused, replacement)| {
            format!("{command} {refused} would change the state of a shared connection: {replacement}")
        })
}

/// Whether this command needs a connection of its own for the length of the
/// call.
///
/// The arguments are consulted, not just the name: XREAD and XREADGROUP wait only
/// when they carry BLOCK, and reading that here rather than trusting the caller
/// to pass `bl` is what keeps a stream reader from parking a connection the whole
/// process shares.
pub fn is_blocking(name: &str, args: &[Vec<u8>], blocking: i64) -> bool {
    match blocking {
        BLOCKING_YES => true,
        BLOCKING_NO => false,
        _ => BLOCKING.contains(&name) || blocking_timeout_ms(name, args).is_some(),
    }
}

/// The blocking argument the command carries, in milliseconds, or None when the
/// command is not one whose arguments name a wait. Used to check the task
/// deadline against it: a deadline shorter than the wait ends the command
/// before the server was ever going to answer, and that looks like an
/// unexplained timeout rather than a misconfiguration.
///
/// The list-and-set blocking commands take the timeout in seconds, as a float,
/// in a position that depends on the command; WAIT and the stream commands take
/// milliseconds. Only the shapes that are unambiguous are read — a command
/// whose wait cannot be located returns None and is bounded by the deadline
/// alone.
pub fn blocking_timeout_ms(name: &str, args: &[Vec<u8>]) -> Option<i64> {
    let seconds_at = |index: usize| -> Option<i64> {
        let text = std::str::from_utf8(args.get(index)?).ok()?;
        let seconds: f64 = text.trim().parse().ok()?;

        Some((seconds * 1000.0) as i64)
    };

    match name {
        // The timeout is the last argument.
        "BLPOP" | "BRPOP" | "BZPOPMIN" | "BZPOPMAX" => seconds_at(args.len().checked_sub(1)?),
        // BLMOVE source destination LEFT|RIGHT LEFT|RIGHT timeout
        "BLMOVE" => seconds_at(4),
        // BRPOPLPUSH source destination timeout
        "BRPOPLPUSH" => seconds_at(2),
        // BLMPOP timeout numkeys …, BZMPOP timeout numkeys …
        "BLMPOP" | "BZMPOP" => seconds_at(0),
        // WAIT numreplicas timeout(ms), WAITAOF numlocal numreplicas timeout(ms)
        "WAIT" => milliseconds_at(args, 1),
        "WAITAOF" => milliseconds_at(args, 2),
        // XREAD … BLOCK ms …, XREADGROUP … BLOCK ms …
        "XREAD" | "XREADGROUP" => block_option_ms(args),
        _ => None,
    }
}

fn milliseconds_at(args: &[Vec<u8>], index: usize) -> Option<i64> {
    std::str::from_utf8(args.get(index)?).ok()?.trim().parse().ok()
}

/// The BLOCK option of a stream read, if it has one.
///
/// Every occurrence is considered, not just the first, and each has to be
/// followed by a number and to come before STREAMS — because after STREAMS the
/// arguments are keys and ids, where "block" is an ordinary name somebody may
/// well have used. Taking the first token that spelled BLOCK got this wrong in
/// both directions: a consumer group called `block` hid the real option, and a
/// stream key called `block` invented one.
fn block_option_ms(args: &[Vec<u8>]) -> Option<i64> {
    let end = args
        .iter()
        .position(|arg| arg.eq_ignore_ascii_case(b"STREAMS"))
        .unwrap_or(args.len());

    args[..end]
        .iter()
        .enumerate()
        .filter(|(_, arg)| arg.eq_ignore_ascii_case(b"BLOCK"))
        .find_map(|(position, _)| milliseconds_at(args, position + 1))
}

/// Builds the command. Arguments are bytes: the PHP side has already refused
/// everything that is not a string, an integer or a float, so there is nothing
/// left to interpret here.
pub fn build(name: &str, args: &[Vec<u8>]) -> Cmd {
    let mut command = Cmd::new();

    command.arg(name.as_bytes());

    for arg in args {
        command.arg(arg.as_slice());
    }

    command
}

#[cfg(test)]
mod tests {
    use super::*;

    fn args(values: &[&str]) -> Vec<Vec<u8>> {
        values.iter().map(|value| value.as_bytes().to_vec()).collect()
    }

    #[test]
    fn names_are_compared_in_upper_case() {
        assert_eq!(normalize_name(" get "), "GET");
        assert!(refusal(&normalize_name("subscribe"), &[]).is_some());
    }

    #[test]
    fn a_refusal_names_the_replacement() {
        let message = refusal("MULTI", &[]).expect("MULTI is refused");

        assert!(message.contains("transaction()"), "{message}");
    }

    #[test]
    fn an_ordinary_command_is_not_refused() {
        assert!(refusal("GET", &args(&["key"])).is_none());
        assert!(refusal("HSET", &args(&["h", "f", "v"])).is_none());
    }

    #[test]
    fn quit_is_refused() {
        assert!(refusal("QUIT", &[]).is_some());
    }

    #[test]
    fn client_reply_is_refused_while_the_rest_of_client_is_not() {
        // CLIENT REPLY OFF|SKIP leaves the server owing no answer, which shifts the
        // multiplexer's queue for good. The read-only subcommands are harmless and
        // the soak test uses one of them.
        assert!(refusal("CLIENT", &args(&["REPLY", "OFF"])).is_some());
        assert!(refusal("CLIENT", &args(&["reply", "skip"])).is_some());
        assert!(refusal("CLIENT", &args(&["SETNAME", "worker"])).is_some());
        assert!(refusal("CLIENT", &args(&["SETINFO", "lib-name", "x"])).is_some());
        assert!(refusal("CLIENT", &args(&["NO-TOUCH", "ON"])).is_some());
        assert!(refusal("CLIENT", &args(&["no-evict", "on"])).is_some());
        assert!(refusal("CLIENT", &args(&["PAUSE", "150"])).is_some());

        assert!(refusal("CLIENT", &args(&["LIST"])).is_none());
        assert!(refusal("CLIENT", &args(&["INFO"])).is_none());
        assert!(refusal("CLIENT", &args(&["ID"])).is_none());
        assert!(refusal("CLIENT", &[]).is_none());
    }

    #[test]
    fn the_blocking_list_is_consulted_by_name() {
        assert!(is_blocking("BLPOP", &args(&["q", "1"]), BLOCKING_BY_NAME));
        assert!(!is_blocking("GET", &args(&["key"]), BLOCKING_BY_NAME));
    }

    #[test]
    fn a_stream_read_is_blocking_when_its_arguments_say_so() {
        // The name alone cannot answer this, and the caller is not trusted to: a
        // stream reader that reached the shared pool would park it.
        assert!(is_blocking(
            "XREAD",
            &args(&["BLOCK", "500", "STREAMS", "s", "$"]),
            BLOCKING_BY_NAME
        ));
        // BLOCK 0 waits for ever, so the option being there is what decides.
        assert!(is_blocking(
            "XREAD",
            &args(&["BLOCK", "0", "STREAMS", "s", "$"]),
            BLOCKING_BY_NAME
        ));
        assert!(!is_blocking("XREAD", &args(&["STREAMS", "s", "$"]), BLOCKING_BY_NAME));
        assert!(is_blocking(
            "XREADGROUP",
            &args(&["GROUP", "g", "c", "BLOCK", "10", "STREAMS", "s", ">"]),
            BLOCKING_BY_NAME
        ));
    }

    #[test]
    fn the_caller_can_override_the_blocking_list() {
        assert!(is_blocking("XREAD", &args(&["STREAMS", "s", "$"]), BLOCKING_YES));
        assert!(!is_blocking("BLPOP", &args(&["q", "1"]), BLOCKING_NO));
    }

    #[test]
    fn the_blocking_timeout_is_read_from_the_arguments() {
        assert_eq!(blocking_timeout_ms("BLPOP", &args(&["queue", "5"])), Some(5000));
        assert_eq!(
            blocking_timeout_ms("BLMOVE", &args(&["src", "dst", "LEFT", "RIGHT", "2.5"])),
            Some(2500)
        );
        assert_eq!(blocking_timeout_ms("BLMPOP", &args(&["1", "2", "a", "b", "LEFT"])), Some(1000));
        assert_eq!(blocking_timeout_ms("WAIT", &args(&["1", "300"])), Some(300));
    }

    #[test]
    fn the_block_option_is_found_wherever_it_sits() {
        assert_eq!(
            blocking_timeout_ms("XREAD", &args(&["COUNT", "10", "BLOCK", "700", "STREAMS", "s", "$"])),
            Some(700)
        );
        assert_eq!(
            blocking_timeout_ms("XREAD", &args(&["STREAMS", "s", "$"])),
            None
        );
    }

    #[test]
    fn a_name_spelled_block_neither_hides_nor_invents_the_option() {
        // A consumer group called `block` used to hide the real option, which put
        // a waiting command on the shared pool.
        assert_eq!(
            blocking_timeout_ms(
                "XREADGROUP",
                &args(&["GROUP", "block", "c1", "BLOCK", "3000", "STREAMS", "s", ">"])
            ),
            Some(3000)
        );

        // A stream key called `block` used to invent one, which got an ordinary
        // read refused for wanting to wait for ever.
        assert_eq!(blocking_timeout_ms("XREAD", &args(&["STREAMS", "block", "0"])), None);
        assert_eq!(
            blocking_timeout_ms("XREAD", &args(&["COUNT", "10", "STREAMS", "block", "0"])),
            None
        );

        assert!(!is_blocking("XREAD", &args(&["STREAMS", "block", "0"]), BLOCKING_BY_NAME));
        assert!(is_blocking(
            "XREADGROUP",
            &args(&["GROUP", "block", "c1", "BLOCK", "3000", "STREAMS", "s", ">"]),
            BLOCKING_BY_NAME
        ));
    }

    #[test]
    fn a_command_without_a_wait_has_no_blocking_timeout() {
        assert_eq!(blocking_timeout_ms("GET", &args(&["key"])), None);
        assert_eq!(blocking_timeout_ms("BLPOP", &[]), None);
    }

    #[test]
    fn a_built_command_carries_the_name_and_every_argument() {
        let command = build("SET", &args(&["key", "value"]));
        let packed = String::from_utf8_lossy(&command.get_packed_command()).to_string();

        assert!(packed.contains("SET"), "{packed}");
        assert!(packed.contains("key"), "{packed}");
        assert!(packed.contains("value"), "{packed}");
    }
}
