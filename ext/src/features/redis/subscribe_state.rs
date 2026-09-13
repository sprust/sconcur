//! The streaming state of a subscription: PHP pulls batches of messages with
//! next(), one batch per crossing.
//!
//! A batch is not a delay. The state hands over whatever has arrived and waits
//! only when nothing has, so a lone message crosses immediately and a fast
//! publisher costs one crossing per batch instead of one per message.

use std::sync::Arc;
use std::time::Instant;

use futures_util::{FutureExt, StreamExt};
use redis::aio::PubSubStream;
use rmp::encode;
use tokio::sync::Mutex;
use tokio_util::sync::CancellationToken;

use crate::dto::{Message, Result};
use crate::helpers::calc_execution_ms;
use crate::states::{StateCloseFuture, StateContract, StateFuture};

use super::errors::{message as fail, Kind};

pub struct SubscribeState {
    stream: Mutex<Option<PubSubStream>>,
    batch_size: usize,
    message: Arc<Message>,
    /// Ends a next() that is waiting on a message. Cancelled when the
    /// subscription closes, so a pull that nobody will answer does not hold the
    /// state's mutex forever.
    cancel: CancellationToken,
    start_time: Instant,
}

impl SubscribeState {
    pub fn new(
        stream: PubSubStream,
        batch_size: usize,
        message: Arc<Message>,
        cancel: CancellationToken,
    ) -> Self {
        SubscribeState {
            stream: Mutex::new(Some(stream)),
            batch_size,
            message,
            cancel,
            start_time: Instant::now(),
        }
    }
}

impl StateContract for SubscribeState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            let mut guard = self.stream.lock().await;

            let Some(stream) = guard.as_mut() else {
                return Result::error(&self.message, fail(Kind::State, "subscription is closed"));
            };

            let mut messages: Vec<Vec<u8>> = Vec::new();

            // The first message is waited for; the rest are only taken if they
            // are already there.
            let first = tokio::select! {
                biased;

                _ = self.cancel.cancelled() => None,
                message = stream.next() => message,
            };

            let Some(first) = first else {
                // The stream ended: the connection went away, or the
                // subscription was closed while this call waited.
                return Result::success(&self.message, encode_messages(&[]), calc_execution_ms(self.start_time));
            };

            messages.push(encode_message(&first));

            while messages.len() < self.batch_size {
                match stream.next().now_or_never() {
                    Some(Some(message)) => messages.push(encode_message(&message)),
                    // Nothing ready, or the stream ended — either way this batch
                    // is what there is.
                    _ => break,
                }
            }

            Result::success_with_next(
                &self.message,
                encode_messages(&messages),
                calc_execution_ms(self.start_time),
            )
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            self.cancel.cancel();

            // Dropping the stream closes the connection, which is what
            // unsubscribes: the socket was the subscription.
            let taken = self.stream.lock().await.take();

            drop(taken);
        })
    }
}

/// One message as the map PHP builds a Dto\Message from: the kind, the channel,
/// the pattern that matched (empty unless the kind is pmsg) and the payload.
fn encode_message(message: &redis::Msg) -> Vec<u8> {
    let mut buffer = Vec::new();

    // Read as bytes, not as a name: a channel is a Redis key, and a key may be
    // any bytes at all.
    let channel: Vec<u8> = message.get_channel::<Vec<u8>>().unwrap_or_default();

    let pattern: Vec<u8> = message
        .get_pattern::<Option<Vec<u8>>>()
        .ok()
        .flatten()
        .unwrap_or_default();

    // from_pattern, not the pattern being empty: a psubscribe to an empty
    // pattern is legal, however pointless.
    let kind: &str = if message.from_pattern() { "pmsg" } else { "msg" };

    let _ = encode::write_map_len(&mut buffer, 4);

    let _ = encode::write_str(&mut buffer, "k");
    let _ = encode::write_str(&mut buffer, kind);

    let _ = encode::write_str(&mut buffer, "c");
    let _ = encode::write_bin(&mut buffer, &channel);

    let _ = encode::write_str(&mut buffer, "p");
    let _ = encode::write_bin(&mut buffer, &pattern);

    let _ = encode::write_str(&mut buffer, "d");
    let _ = encode::write_bin(&mut buffer, message.get_payload_bytes());

    buffer
}

/// The batch: a list of already-encoded messages.
fn encode_messages(messages: &[Vec<u8>]) -> Vec<u8> {
    let mut buffer = Vec::new();

    let _ = encode::write_array_len(&mut buffer, messages.len() as u32);

    for message in messages {
        buffer.extend_from_slice(message);
    }

    buffer
}

#[cfg(test)]
mod tests {
    use super::*;

    use redis::Value;
    use rmpv::decode::read_value;

    /// A message the way the server sends one: the kind, then its parts.
    fn message(kind: &str, parts: &[&str]) -> redis::Msg {
        let mut raw: Vec<Value> = vec![Value::BulkString(kind.as_bytes().to_vec())];

        raw.extend(parts.iter().map(|part| Value::BulkString(part.as_bytes().to_vec())));

        redis::Msg::from_value(&Value::Array(raw)).expect("a pubsub message")
    }

    fn decoded(bytes: &[u8]) -> rmpv::Value {
        read_value(&mut &bytes[..]).expect("the encoder wrote valid MessagePack")
    }

    fn field<'a>(map: &'a rmpv::Value, key: &str) -> &'a rmpv::Value {
        match map {
            rmpv::Value::Map(pairs) => pairs
                .iter()
                .find(|(name, _)| name.as_str() == Some(key))
                .map(|(_, value)| value)
                .unwrap_or_else(|| panic!("the message carries no {key}")),
            other => panic!("expected a map, got {other:?}"),
        }
    }

    fn binary(value: &rmpv::Value) -> &[u8] {
        match value {
            rmpv::Value::Binary(bytes) => bytes,
            other => panic!("expected binary, got {other:?}"),
        }
    }

    #[test]
    fn a_plain_message_carries_its_channel_and_payload() {
        let value = decoded(&encode_message(&message("message", &["news", "hello"])));

        assert_eq!(field(&value, "k").as_str(), Some("msg"));
        assert_eq!(binary(field(&value, "c")), b"news");
        assert_eq!(binary(field(&value, "d")), b"hello");
        assert!(binary(field(&value, "p")).is_empty());
    }

    #[test]
    fn a_pattern_message_carries_the_pattern_that_matched() {
        let value = decoded(&encode_message(&message(
            "pmessage",
            &["news.*", "news.sport", "hello"],
        )));

        assert_eq!(field(&value, "k").as_str(), Some("pmsg"));
        assert_eq!(binary(field(&value, "p")), b"news.*");
        assert_eq!(binary(field(&value, "c")), b"news.sport");
        assert_eq!(binary(field(&value, "d")), b"hello");
    }

    #[test]
    fn an_empty_pattern_is_still_a_pattern_message() {
        // The kind comes from from_pattern, not from the pattern having bytes in
        // it: a psubscribe to an empty pattern is legal, however pointless, and
        // reading its messages as plain ones loses which subscription they match.
        let value = decoded(&encode_message(&message("pmessage", &["", "news", "hello"])));

        assert_eq!(field(&value, "k").as_str(), Some("pmsg"));
        assert!(binary(field(&value, "p")).is_empty());
    }

    #[test]
    fn a_channel_and_a_payload_may_be_any_bytes() {
        // A channel is a Redis key, and a key is bytes — reading either as a name
        // would drop whatever is not valid UTF-8.
        let raw = Value::Array(vec![
            Value::BulkString(b"message".to_vec()),
            Value::BulkString(vec![0x00, 0xff]),
            Value::BulkString(vec![0x00, 0x01, 0xfe]),
        ]);

        let value = decoded(&encode_message(
            &redis::Msg::from_value(&raw).expect("a pubsub message"),
        ));

        assert_eq!(binary(field(&value, "c")), &[0x00, 0xff]);
        assert_eq!(binary(field(&value, "d")), &[0x00, 0x01, 0xfe]);
    }

    #[test]
    fn a_batch_keeps_the_messages_in_the_order_they_arrived() {
        let batch = vec![
            encode_message(&message("message", &["news", "one"])),
            encode_message(&message("message", &["news", "two"])),
        ];

        match decoded(&encode_messages(&batch)) {
            rmpv::Value::Array(messages) => {
                assert_eq!(messages.len(), 2);
                assert_eq!(binary(field(&messages[0], "d")), b"one");
                assert_eq!(binary(field(&messages[1], "d")), b"two");
            }
            other => panic!("expected an array, got {other:?}"),
        }
    }

    #[test]
    fn an_empty_batch_is_an_empty_list() {
        // What a stream that ended answers with: PHP sees the iteration finish
        // rather than a message nobody published.
        match decoded(&encode_messages(&[])) {
            rmpv::Value::Array(messages) => assert!(messages.is_empty()),
            other => panic!("expected an array, got {other:?}"),
        }
    }
}
