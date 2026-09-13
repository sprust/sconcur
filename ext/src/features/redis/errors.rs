//! What kind of failure a task error is, said in a way PHP can read without
//! guessing.
//!
//! The text of an error is partly the server's: a Lua script can call
//! `redis.error_reply("connect: whatever")`, a module can answer anything at
//! all. So the kind cannot be recovered by matching the text — the PHP side
//! would be classifying a string an attacker or a careless script controls.
//!
//! The core writes the kind itself, first, in a shape the server's own text can
//! never occupy: `redis[<kind>]: <text>`. PHP reads the bracketed word and
//! treats everything after it as opaque.

/// The kinds. Each maps to exactly one PHP exception class — see
/// SConcur\Features\Redis\Support\RedisFailure.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Kind {
    /// The server refused the command. The text starts with the Redis error
    /// code, which PHP splits off into RedisCommandException::errorCode.
    Command,
    /// Dialling, authenticating or a socket that went away mid-command.
    Connection,
    /// The payload's deadline ran out.
    Timeout,
    /// A command that cannot run on a shared connection.
    Refused,
    /// A payload this side could not use: a bad argument, an unknown
    /// sub-operation, a malformed body.
    Argument,
    /// The dsn cannot be used.
    Dsn,
    /// The flow was stopped under a running task.
    Stopped,
    /// A stream that is closed, or a handle that names nothing.
    State,
}

impl Kind {
    fn as_tag(self) -> &'static str {
        match self {
            Kind::Command => "cmd",
            Kind::Connection => "conn",
            Kind::Timeout => "timeout",
            Kind::Refused => "refused",
            Kind::Argument => "arg",
            Kind::Dsn => "dsn",
            Kind::Stopped => "stopped",
            Kind::State => "state",
        }
    }
}

/// The task-error text: the feature, the kind, then whatever the failure has to
/// say.
pub fn message(kind: Kind, text: &str) -> String {
    format!("redis[{}]: {}", kind.as_tag(), text)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_message_carries_its_kind_first() {
        assert_eq!(
            message(Kind::Command, "WRONGTYPE wrong kind of value"),
            "redis[cmd]: WRONGTYPE wrong kind of value"
        );
    }

    #[test]
    fn every_kind_has_its_own_tag() {
        let kinds = [
            Kind::Command,
            Kind::Connection,
            Kind::Timeout,
            Kind::Refused,
            Kind::Argument,
            Kind::Dsn,
            Kind::Stopped,
            Kind::State,
        ];

        let mut tags: Vec<&str> = kinds.iter().map(|kind| kind.as_tag()).collect();

        tags.sort_unstable();
        tags.dedup();

        assert_eq!(tags.len(), kinds.len(), "two kinds share a tag");
    }

    #[test]
    fn a_server_text_cannot_forge_a_kind() {
        // The server's text lands after the tag the core wrote, so a script
        // answering "redis[conn]: ..." is still reported as a command failure.
        let forged = message(Kind::Command, "redis[conn]: nice try");

        assert!(forged.starts_with("redis[cmd]: "), "{forged}");
    }
}
