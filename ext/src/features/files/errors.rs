//! What kind of failure a task error is, said in a way PHP can read without
//! guessing.
//!
//! Half of a file error's text is the caller's: it carries the path, and a path
//! is whatever the application chose to name a file. So the kind cannot be
//! recovered by matching the text — a file named "permission denied" would pick
//! the exception the application catches.
//!
//! The core writes the kind itself, first, in a shape a path can never occupy:
//! `files[<kind>]: <text>`. PHP reads the bracketed word and treats everything
//! after it as opaque. Same scheme as the redis feature's errors.rs.

use std::io::ErrorKind;

/// The kinds. Each maps to exactly one PHP exception class — see
/// SConcur\Features\Files\Support\FilesFailure.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Kind {
    /// The path does not exist.
    NotFound,
    /// The process may not do this to the path.
    Permission,
    /// The destination is there and the mode forbids replacing it.
    AlreadyExists,
    /// The path is of the wrong kind: a directory for a file, a file for a
    /// directory, a directory that still holds entries.
    FileType,
    /// Any other input-output failure.
    Io,
    /// The file is bigger than the read limit the payload carried. A refusal,
    /// not a failure of the filesystem: the alternative was to spend the
    /// memory.
    TooLarge,
    /// The payload's deadline ran out.
    Timeout,
    /// The flow was stopped under a running task.
    Stopped,
    /// A writer or a stream that is closed, or a handle that names nothing.
    State,
    /// A payload this side could not use: a bad argument, an unknown
    /// sub-operation, a malformed body.
    Argument,
}

impl Kind {
    fn as_tag(self) -> &'static str {
        match self {
            Kind::NotFound => "nf",
            Kind::Permission => "pd",
            Kind::AlreadyExists => "ae",
            Kind::FileType => "ft",
            Kind::Io => "io",
            Kind::TooLarge => "big",
            Kind::Timeout => "timeout",
            Kind::Stopped => "stopped",
            Kind::State => "state",
            Kind::Argument => "arg",
        }
    }
}

/// The kind an io::Error carries. Everything the standard library does not name
/// for us — a full disk, a quota, a broken device — is Io, which is the kind
/// that means "the filesystem refused, and the text says why".
///
/// DirectoryNotEmpty joins the file-type kinds rather than Io: like a directory
/// found where a file was wanted, it says the path is not in the shape the
/// operation needs, and the caller fixes it the same way.
pub fn kind_of(error: &std::io::Error) -> Kind {
    match error.kind() {
        ErrorKind::NotFound => Kind::NotFound,
        ErrorKind::PermissionDenied => Kind::Permission,
        ErrorKind::AlreadyExists => Kind::AlreadyExists,
        ErrorKind::IsADirectory | ErrorKind::NotADirectory | ErrorKind::DirectoryNotEmpty => {
            Kind::FileType
        }
        _ => Kind::Io,
    }
}

/// The task-error text: the feature, the kind, then whatever the failure has to
/// say.
pub fn message(kind: Kind, text: &str) -> String {
    format!("files[{}]: {}", kind.as_tag(), text)
}

/// The text of an io failure, with the operation and the path it happened to.
/// The path is included on purpose: "No such file or directory" alone is the
/// least useful error message there is.
pub fn io_message(operation: &str, path: &str, error: &std::io::Error) -> String {
    message(kind_of(error), &format!("{operation} {path}: {error}"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_message_carries_its_kind_first() {
        assert_eq!(
            message(Kind::NotFound, "read /tmp/nope: No such file or directory"),
            "files[nf]: read /tmp/nope: No such file or directory"
        );
    }

    #[test]
    fn every_kind_has_its_own_tag() {
        let kinds = [
            Kind::NotFound,
            Kind::Permission,
            Kind::AlreadyExists,
            Kind::FileType,
            Kind::Io,
            Kind::TooLarge,
            Kind::Timeout,
            Kind::Stopped,
            Kind::State,
            Kind::Argument,
        ];

        let mut tags: Vec<&str> = kinds.iter().map(|kind| kind.as_tag()).collect();

        tags.sort_unstable();
        tags.dedup();

        assert_eq!(tags.len(), kinds.len());
    }

    #[test]
    fn the_io_kinds_are_told_apart() {
        let cases = [
            (ErrorKind::NotFound, Kind::NotFound),
            (ErrorKind::PermissionDenied, Kind::Permission),
            (ErrorKind::AlreadyExists, Kind::AlreadyExists),
            (ErrorKind::IsADirectory, Kind::FileType),
            (ErrorKind::NotADirectory, Kind::FileType),
            (ErrorKind::DirectoryNotEmpty, Kind::FileType),
            // Not named by the standard library, so it lands on the generic kind
            // rather than being guessed at.
            (ErrorKind::StorageFull, Kind::Io),
        ];

        for (error_kind, expected) in cases {
            assert_eq!(
                kind_of(&std::io::Error::from(error_kind)),
                expected,
                "{error_kind:?}"
            );
        }
    }

    #[test]
    fn an_io_message_names_the_operation_and_the_path() {
        let error = std::io::Error::from(ErrorKind::PermissionDenied);

        assert_eq!(
            io_message("read", "/etc/shadow", &error),
            format!("files[pd]: read /etc/shadow: {error}")
        );
    }
}
