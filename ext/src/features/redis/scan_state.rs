//! The streaming state behind a cursor command (SCAN, HSCAN, SSCAN, ZSCAN).
//!
//! The cursor lives here, not on the PHP side: PHP pulls batches with next()
//! and never sees a cursor value. COUNT decides how much the server does per
//! round trip; the batch size decides how much crosses the boundary at a time,
//! and the two are different numbers.

use std::sync::Arc;
use std::time::{Duration, Instant};
use tokio::sync::Mutex;
use tokio_util::sync::CancellationToken;

use redis::aio::ConnectionLike;
use redis::Value;

use crate::dto::{Message, Result};
use crate::helpers::calc_execution_ms;
use crate::states::{StateCloseFuture, StateContract, StateFuture};

use super::commands;
use super::errors::{message as fail, Kind};
use super::pools::Acquired;
use super::values;

/// A failure carries the kind PHP raises it as, like everywhere else in the
/// feature: a deadline is a timeout, a dropped socket is a connection failure,
/// and only the server's own refusal is a command failure. Flattening them into
/// one kind is how a scan that ran out of time came back as a
/// RedisCommandException with no code in it.
type Outcome = std::result::Result<(), (Kind, String)>;

/// The elements read but not yet handed to PHP, plus what it takes to ask for
/// more. Behind one mutex, because close() may arrive from a cancelled flow
/// while next() is in the middle of a round trip.
struct Cursor {
    /// The pooled connection the scan runs on. Held for the life of the cursor
    /// and released when it closes, so the pool's own accounting matches what
    /// is actually in use.
    acquired: Acquired,
    /// The cursor the last reply carried. "0" means the scan is over; None
    /// means it has not started.
    cursor: Option<Vec<u8>>,
    buffered: Vec<Value>,
    finished: bool,
}

pub struct ScanState {
    name: String,
    args: Vec<Vec<u8>>,
    batch_size: usize,
    /// The deadline one next() may take, from the payload. It bounds the whole
    /// call rather than one round trip: a MATCH that finds nothing walks the
    /// keyspace over many round trips, and it is the walk that has to end.
    timeout_ms: i64,
    message: Arc<Message>,
    cursor: Mutex<Option<Cursor>>,
    /// Ends a next() that is in the middle of a walk. Cancelled by close(), so
    /// the flow going away does not have to wait for the round trip in flight —
    /// and, more to the point, so close() is not queued behind a walk that could
    /// last as long as the keyspace does.
    cancel: CancellationToken,
    start_time: Instant,
}

impl ScanState {
    pub fn new(
        name: String,
        args: Vec<Vec<u8>>,
        batch_size: usize,
        timeout_ms: i64,
        message: Arc<Message>,
        acquired: Acquired,
    ) -> Self {
        ScanState {
            name,
            args,
            batch_size,
            timeout_ms,
            message,
            cursor: Mutex::new(Some(Cursor {
                acquired,
                cursor: None,
                buffered: Vec::new(),
                finished: false,
            })),
            cancel: CancellationToken::new(),
            start_time: Instant::now(),
        }
    }

    /// One round trip: ask with the cursor the last reply carried, and add what
    /// came back to the buffer.
    async fn fetch(&self, cursor: &mut Cursor) -> Outcome {
        let previous = cursor.cursor.clone().unwrap_or_else(|| b"0".to_vec());

        let command = commands::build(&self.name, &scan_args(&self.name, &self.args, &previous));

        let mut connection = cursor.acquired.connection();

        let value = connection
            .req_packed_command(&command)
            .await
            .map_err(|error| values::classify_error(&error))?;

        let (next_cursor, elements) = parse_reply(&self.name, value)?;

        cursor.finished = next_cursor == b"0";
        cursor.cursor = Some(next_cursor);
        cursor.buffered.extend(elements);

        Ok(())
    }

    /// One batch: keep asking the server until there is something to hand over
    /// or the walk is done. An empty answer with a non-zero cursor is normal —
    /// a MATCH that hits nothing in this slice of the keyspace — and returning
    /// it to PHP would end the iteration early.
    async fn pull_batch(&self) -> std::result::Result<Result, (Kind, String)> {
        let mut guard = self.cursor.lock().await;

        let Some(cursor) = guard.as_mut() else {
            return Err((Kind::State, "scan is closed".to_string()));
        };

        while cursor.buffered.is_empty() && !cursor.finished {
            self.fetch(cursor).await?;
        }

        let taken = cursor.buffered.len().min(self.batch_size);
        let batch: Vec<Value> = cursor.buffered.drain(..taken).collect();

        let payload = values::encode_values(&batch).map_err(|error| (Kind::Command, error))?;
        let has_next = !cursor.buffered.is_empty() || !cursor.finished;

        Ok(if has_next {
            Result::success_with_next(&self.message, payload, calc_execution_ms(self.start_time))
        } else {
            Result::success(&self.message, payload, calc_execution_ms(self.start_time))
        })
    }
}

/// The arguments of one round trip: <NAME> [key] <cursor> [MATCH …] [COUNT …].
/// The key, when the command takes one, is already the first of the stored
/// arguments — the cursor goes after it, which is where every cursor command
/// puts it.
fn scan_args(name: &str, stored: &[Vec<u8>], cursor: &[u8]) -> Vec<Vec<u8>> {
    let mut args: Vec<Vec<u8>> = Vec::with_capacity(stored.len() + 1);

    let takes_key = name != "SCAN";

    if takes_key && !stored.is_empty() {
        args.push(stored[0].clone());
        args.push(cursor.to_vec());
        args.extend(stored[1..].iter().cloned());
    } else {
        args.push(cursor.to_vec());
        args.extend(stored.iter().cloned());
    }

    args
}

/// The two halves of a cursor reply: the cursor to ask with next time, and the
/// elements this round trip found.
///
/// A reply this side cannot make sense of is still the server's answer, so it is
/// a command failure — the command asked for something the server does not
/// answer the way a cursor command answers.
fn parse_reply(name: &str, value: Value) -> std::result::Result<(Vec<u8>, Vec<Value>), (Kind, String)> {
    let unexpected = |what: &str| (Kind::Command, format!("{name} answered with {what}"));

    let Value::Array(mut parts) = value else {
        return Err(unexpected("an unexpected reply"));
    };

    if parts.len() != 2 {
        return Err(unexpected("an unexpected reply"));
    }

    let elements = match parts.pop() {
        Some(Value::Array(elements)) => elements,
        _ => return Err(unexpected("no element list")),
    };

    let next_cursor = match parts.pop() {
        Some(Value::BulkString(bytes)) => bytes,
        Some(Value::Int(number)) => number.to_string().into_bytes(),
        Some(Value::SimpleString(text)) => text.into_bytes(),
        _ => return Err(unexpected("no cursor")),
    };

    Ok((next_cursor, elements))
}

/// Bounds a batch by the payload's deadline. Zero means the caller asked for
/// none, and then only the cancellation token ends it.
async fn with_deadline<F, T>(timeout_ms: i64, work: F) -> std::result::Result<T, (Kind, String)>
where
    F: std::future::Future<Output = std::result::Result<T, (Kind, String)>>,
{
    if timeout_ms <= 0 {
        return work.await;
    }

    match tokio::time::timeout(Duration::from_millis(timeout_ms as u64), work).await {
        Ok(outcome) => outcome,
        Err(_) => Err((
            Kind::Timeout,
            format!("the scan did not answer within {timeout_ms} ms"),
        )),
    }
}

impl StateContract for ScanState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            // Both mandatory requirements of a handler apply to a batch as much as
            // to a one-shot command: the deadline bounds it and the token ends it.
            // The token is checked first, so a cancelled stream answers at once
            // instead of after one more round trip.
            let outcome = tokio::select! {
                biased;

                _ = self.cancel.cancelled() => Err((
                    Kind::Stopped,
                    "closed by task stop".to_string(),
                )),
                outcome = with_deadline(self.timeout_ms, self.pull_batch()) => outcome,
            };

            match outcome {
                Ok(result) => result,
                Err((kind, error)) => Result::error(&self.message, fail(kind, &error)),
            }
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            // Cancel before reaching for the mutex: a next() in the middle of a
            // long walk holds it, and close() waiting for that walk to end is
            // exactly what a flow being stopped must not do.
            self.cancel.cancel();

            // Dropping the cursor releases the pooled connection. Taking it out
            // of the mutex means a second close finds nothing to do rather than
            // releasing twice.
            let taken = self.cursor.lock().await.take();

            drop(taken);
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn args(values: &[&str]) -> Vec<Vec<u8>> {
        values.iter().map(|value| value.as_bytes().to_vec()).collect()
    }

    fn text(values: &[Vec<u8>]) -> Vec<String> {
        values
            .iter()
            .map(|value| String::from_utf8_lossy(value).to_string())
            .collect()
    }

    #[test]
    fn a_keyless_scan_asks_with_the_cursor_first() {
        let built = scan_args("SCAN", &args(&["MATCH", "a*", "COUNT", "100"]), b"12");

        assert_eq!(text(&built), ["12", "MATCH", "a*", "COUNT", "100"]);
    }

    #[test]
    fn a_keyed_scan_keeps_the_key_before_the_cursor() {
        // HSCAN/SSCAN/ZSCAN take the key first and the cursor second. The other
        // order asks about a key named after the cursor, which is a WRONGTYPE at
        // best and somebody else's data at worst.
        let built = scan_args("HSCAN", &args(&["hash", "COUNT", "10"]), b"7");

        assert_eq!(text(&built), ["hash", "7", "COUNT", "10"]);
    }

    #[test]
    fn a_keyed_scan_without_its_key_still_sends_the_cursor() {
        let built = scan_args("HSCAN", &[], b"0");

        assert_eq!(text(&built), ["0"]);
    }

    #[test]
    fn a_reply_is_split_into_the_next_cursor_and_the_elements() {
        let value = Value::Array(vec![
            Value::BulkString(b"17".to_vec()),
            Value::Array(vec![Value::BulkString(b"key:1".to_vec())]),
        ]);

        let (cursor, elements) = parse_reply("SCAN", value).expect("a cursor reply");

        assert_eq!(cursor, b"17".to_vec());
        assert_eq!(elements.len(), 1);
    }

    #[test]
    fn a_numeric_cursor_is_read_as_its_digits() {
        // RESP3 answers the cursor as a number where RESP2 answers a bulk string.
        let value = Value::Array(vec![Value::Int(42), Value::Array(Vec::new())]);

        let (cursor, elements) = parse_reply("SCAN", value).expect("a cursor reply");

        assert_eq!(cursor, b"42".to_vec());
        assert!(elements.is_empty());
    }

    #[test]
    fn a_reply_that_is_not_a_pair_is_a_command_failure() {
        let (kind, error) = parse_reply("SCAN", Value::Okay).expect_err("refused");

        assert_eq!(kind, Kind::Command);
        assert!(error.contains("SCAN answered with"), "{error}");
    }

    #[test]
    fn a_reply_without_an_element_list_names_what_is_missing() {
        let value = Value::Array(vec![Value::BulkString(b"0".to_vec()), Value::Okay]);

        let (_, error) = parse_reply("HSCAN", value).expect_err("refused");

        assert!(error.contains("no element list"), "{error}");
    }

    #[test]
    fn a_reply_without_a_cursor_names_what_is_missing() {
        let value = Value::Array(vec![Value::Okay, Value::Array(Vec::new())]);

        let (_, error) = parse_reply("SSCAN", value).expect_err("refused");

        assert!(error.contains("no cursor"), "{error}");
    }

    #[tokio::test]
    async fn a_deadline_ends_a_batch_as_a_timeout() {
        // The kind is half the point: a scan that ran out of time reaching PHP as
        // a command failure is a RedisCommandException with no error code in it.
        let outcome: std::result::Result<(), (Kind, String)> = with_deadline(10, async {
            tokio::time::sleep(Duration::from_millis(500)).await;

            Ok(())
        })
        .await;

        let (kind, error) = outcome.expect_err("the deadline fired");

        assert_eq!(kind, Kind::Timeout);
        assert!(error.contains("10 ms"), "{error}");
    }

    #[tokio::test]
    async fn no_deadline_lets_a_batch_run() {
        let outcome: std::result::Result<u8, (Kind, String)> = with_deadline(0, async { Ok(7) }).await;

        assert_eq!(outcome.expect("no deadline"), 7);
    }
}
