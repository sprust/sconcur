//! The shell-style name filter behind list() and walk().
//!
//! Deliberately small: `*`, `?` and `[...]`, matched against one entry name and
//! nothing else. It is not glob(3) — there is no `**`, no brace expansion and no
//! escaping, and a pattern never spans a directory separator, because the
//! filter's whole job is to keep a directory of a hundred thousand entries from
//! crossing the boundary, not to be a path language.

/// A pattern parsed once, so a listing of a hundred thousand entries does not
/// re-parse it a hundred thousand times.
///
/// It is not an optimization looking for a problem: the filter runs per entry,
/// and the per-entry cost is the whole reason this feature beats scandir plus a
/// stat each.
pub struct Pattern {
    characters: Vec<char>,
}

impl Pattern {
    pub fn compile(pattern: &str) -> Self {
        Pattern {
            characters: pattern.chars().collect(),
        }
    }

    /// Whether an entry name matches. An empty pattern matches everything, which
    /// is how "no filter" travels on the wire.
    pub fn matches(&self, name: &str) -> bool {
        if self.characters.is_empty() {
            return true;
        }

        matches_chars(&self.characters, name)
    }
}

fn matches_chars(pattern: &[char], name: &str) -> bool {
    let name: Vec<char> = name.chars().collect();

    let mut pattern_index = 0;
    let mut name_index = 0;

    // Where the last `*` was, and how much of the name it had consumed. A
    // mismatch later comes back here and lets it eat one character more, which
    // is what makes the match backtrack without recursion.
    let mut star_index: Option<usize> = None;
    let mut star_name_index = 0;

    while name_index < name.len() {
        let advanced = if pattern_index < pattern.len() {
            match pattern[pattern_index] {
                '?' => {
                    pattern_index += 1;
                    name_index += 1;

                    true
                }
                '*' => {
                    star_index = Some(pattern_index);
                    star_name_index = name_index;
                    pattern_index += 1;

                    true
                }
                '[' => match class_end(&pattern, pattern_index) {
                    Some(end) => {
                        if class_matches(&pattern[pattern_index + 1..end], name[name_index]) {
                            pattern_index = end + 1;
                            name_index += 1;

                            true
                        } else {
                            false
                        }
                    }
                    // An unterminated class is a literal bracket, which is what
                    // a shell does with it too.
                    None => {
                        if pattern[pattern_index] == name[name_index] {
                            pattern_index += 1;
                            name_index += 1;

                            true
                        } else {
                            false
                        }
                    }
                },
                character => {
                    if character == name[name_index] {
                        pattern_index += 1;
                        name_index += 1;

                        true
                    } else {
                        false
                    }
                }
            }
        } else {
            false
        };

        if advanced {
            continue;
        }

        let Some(index) = star_index else {
            return false;
        };

        star_name_index += 1;
        name_index = star_name_index;
        pattern_index = index + 1;
    }

    // Trailing stars match the empty rest of the name.
    while pattern_index < pattern.len() && pattern[pattern_index] == '*' {
        pattern_index += 1;
    }

    pattern_index == pattern.len()
}

/// The index of the `]` closing the class that opens at `start`, if there is
/// one. A `]` in the first position is a literal, as it is in a shell.
fn class_end(pattern: &[char], start: usize) -> Option<usize> {
    let mut index = start + 1;

    if index < pattern.len() && (pattern[index] == '!' || pattern[index] == '^') {
        index += 1;
    }

    if index < pattern.len() && pattern[index] == ']' {
        index += 1;
    }

    while index < pattern.len() {
        if pattern[index] == ']' {
            return Some(index);
        }

        index += 1;
    }

    None
}

/// Whether the character is in the class body (what sits between the brackets).
fn class_matches(body: &[char], character: char) -> bool {
    let (negated, body) = match body.first() {
        Some('!') | Some('^') => (true, &body[1..]),
        _ => (false, body),
    };

    let mut found = false;
    let mut index = 0;

    while index < body.len() {
        // A range, unless the dash is first or last — there it is a literal.
        if index + 2 < body.len() && body[index + 1] == '-' {
            if body[index] <= character && character <= body[index + 2] {
                found = true;
            }

            index += 3;

            continue;
        }

        if body[index] == character {
            found = true;
        }

        index += 1;
    }

    found != negated
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The tests read better against a pattern and a name than against a
    /// compiled value, and every caller in the feature compiles once per
    /// directory — so the convenience lives here rather than in the module.
    fn matches(pattern: &str, name: &str) -> bool {
        Pattern::compile(pattern).matches(name)
    }

    #[test]
    fn an_empty_pattern_is_no_filter() {
        assert!(matches("", "anything.txt"));
    }

    #[test]
    fn a_literal_matches_only_itself() {
        assert!(matches("report.csv", "report.csv"));
        assert!(!matches("report.csv", "report.csvx"));
        assert!(!matches("report.csv", "Report.csv"));
    }

    #[test]
    fn a_star_stands_for_any_run() {
        assert!(matches("*.csv", "report.csv"));
        assert!(matches("*.csv", ".csv"));
        assert!(matches("report.*", "report.csv"));
        assert!(matches("*", ""));
        assert!(!matches("*.csv", "report.csv.gz"));
    }

    #[test]
    fn stars_backtrack() {
        // The first star has to give a character back for the second to match.
        assert!(matches("*a*b", "xxaxxb"));
        assert!(matches("a*b*c", "abc"));
        assert!(!matches("a*b*c", "abd"));
        assert!(matches("*-*.log", "app-2026.log"));
    }

    #[test]
    fn a_question_mark_stands_for_exactly_one() {
        assert!(matches("log?.txt", "log1.txt"));
        assert!(!matches("log?.txt", "log.txt"));
        assert!(!matches("log?.txt", "log12.txt"));
    }

    #[test]
    fn a_class_matches_one_of_its_members() {
        assert!(matches("log[012].txt", "log1.txt"));
        assert!(!matches("log[012].txt", "log3.txt"));
    }

    #[test]
    fn a_class_takes_ranges() {
        assert!(matches("[a-z]*", "report"));
        assert!(!matches("[a-z]*", "Report"));
        assert!(matches("[0-9][0-9].log", "42.log"));
    }

    #[test]
    fn a_class_can_be_negated_either_way() {
        assert!(matches("[!0-9]*", "report"));
        assert!(!matches("[!0-9]*", "1report"));
        assert!(matches("[^0-9]*", "report"));
    }

    #[test]
    fn an_unterminated_class_is_a_literal_bracket() {
        assert!(matches("[abc", "[abc"));
        assert!(!matches("[abc", "a"));
    }

    #[test]
    fn a_dash_at_an_edge_of_a_class_is_a_literal() {
        assert!(matches("[-a]", "-"));
        assert!(matches("[a-]", "-"));
        assert!(matches("[a-]", "a"));
    }

    #[test]
    fn a_pattern_does_not_reach_across_a_separator() {
        // The filter runs on one entry name, so a slash in a name is an ordinary
        // character and a star does not treat it specially either way.
        assert!(matches("*", "a/b"));
        assert!(matches("a*b", "a/b"));
    }

    #[test]
    fn non_ascii_names_match_by_character() {
        assert!(matches("отчёт-*.csv", "отчёт-2026.csv"));
        assert!(matches("?тчёт.csv", "отчёт.csv"));
    }
}
