//! Mirrors ext/internal/errs/factory.go: prefixed error strings, so a task
//! error reaching PHP names the feature it came from.

pub struct Factory {
    prefix: &'static str,
}

impl Factory {
    pub const fn new(prefix: &'static str) -> Self {
        Factory { prefix }
    }

    pub fn by_text(&self, text: &str) -> String {
        format!("{}: {}", self.prefix, text)
    }

    pub fn by_err<E: std::fmt::Display>(&self, text: &str, error: E) -> String {
        format!("{}: {}: {}", self.prefix, text, error)
    }
}
