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
use tokio::sync::Mutex;
use tokio_util::sync::CancellationToken;

use crate::dto::{Map, Message, Result, Value};
use crate::helpers::calc_execution_ms;
use crate::states::{StateCloseFuture, StateContract, StateFuture};

use super::errors::{message as fail, Kind};

pub struct SubscribeState {
    stream: Mutex<Option<PubSubStream>>,
    batch_size: usize,
    message: Arc<Message>,
    /// What this subscription is registered under, so closing the stream takes
    /// the registry entry with it. Every other path removes it by hand; a stream
    /// that ends by itself is closed by the states registry, and without this the
    /// id, its sink and its mutex would stay in a process-wide map for the life
    /// of the process — one entry per subscription that ever lost its server.
    subscription_id: String,
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
        subscription_id: String,
        cancel: CancellationToken,
    ) -> Self {
        SubscribeState {
            stream: Mutex::new(Some(stream)),
            batch_size,
            message,
            subscription_id,
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

            let mut messages: Vec<Value> = Vec::new();

            // The first message is waited for; the rest are only taken if they
            // are already there.
            let first = tokio::select! {
                biased;

                _ = self.cancel.cancelled() => None,
                message = stream.next() => message,
            };

            let Some(first) = first else {
                // The stream ended, and which of the two ways it ended decides
                // what PHP sees. Closed from this side: the iteration is over,
                // and an empty batch says so. The connection going away is not
                // that — a subscriber whose socket died would otherwise just stop
                // receiving, with no exception, nothing logged, and isClosed()
                // still answering false. Nothing re-establishes it either: a
                // subscription is a raw PubSubStream, not the pooled manager that
                // reconnects behind the commands.
                if self.cancel.is_cancelled() {
                    return Result::success(
                        &self.message,
                        batch_value(Vec::new()),
                        calc_execution_ms(self.start_time),
                    );
                }

                return Result::error(
                    &self.message,
                    fail(Kind::Connection, "the subscription's connection went away"),
                );
            };

            messages.push(message_value(&first));

            while messages.len() < self.batch_size {
                match stream.next().now_or_never() {
                    Some(Some(message)) => messages.push(message_value(&message)),
                    // Nothing ready, or the stream ended — either way this batch
                    // is what there is.
                    _ => break,
                }
            }

            Result::success_with_next(
                &self.message,
                batch_value(messages),
                calc_execution_ms(self.start_time),
            )
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            self.cancel.cancel();

            // The registry entry goes with the stream, however the stream ended.
            // Idempotent, like every other removal of it.
            super::registry_subscriptions().remove(&self.subscription_id);

            // Dropping the stream closes the connection, which is what
            // unsubscribes: the socket was the subscription.
            let taken = self.stream.lock().await.take();

            drop(taken);
        })
    }
}

/// One message as the map PHP builds a Dto\Message from: the kind, the channel,
/// the pattern that matched (empty unless the kind is pmsg) and the payload.
fn message_value(message: &redis::Msg) -> Value {
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

    Map::new()
        .set("k", kind)
        .set("c", channel)
        .set("p", pattern)
        .set("d", message.get_payload_bytes())
        .into()
}

/// The batch: a list of messages.
fn batch_value(messages: Vec<Value>) -> Value {
    Value::List(messages)
}

#[cfg(test)]
mod tests {
    use super::*;

    use redis::Value as RedisValue;

    /// A message the way the server sends one: the kind, then its parts.
    fn message(kind: &str, parts: &[&str]) -> redis::Msg {
        let mut raw: Vec<RedisValue> = vec![RedisValue::BulkString(kind.as_bytes().to_vec())];

        raw.extend(
            parts
                .iter()
                .map(|part| RedisValue::BulkString(part.as_bytes().to_vec())),
        );

        redis::Msg::from_value(&RedisValue::Array(raw)).expect("a pubsub message")
    }

    fn field<'a>(map: &'a Value, key: &str) -> &'a Value {
        map.get(key)
            .unwrap_or_else(|| panic!("the message carries no {key}"))
    }

    fn bytes(value: &Value) -> &[u8] {
        value.as_bytes().expect("a string")
    }

    #[test]
    fn a_plain_message_carries_its_channel_and_payload() {
        let value = message_value(&message("message", &["news", "hello"]));

        assert_eq!(field(&value, "k"), &Value::str("msg"));
        assert_eq!(bytes(field(&value, "c")), b"news");
        assert_eq!(bytes(field(&value, "d")), b"hello");
        assert!(bytes(field(&value, "p")).is_empty());
    }

    #[test]
    fn a_pattern_message_carries_the_pattern_that_matched() {
        let value = message_value(&message(
            "pmessage",
            &["news.*", "news.sport", "hello"],
        ));

        assert_eq!(field(&value, "k"), &Value::str("pmsg"));
        assert_eq!(bytes(field(&value, "p")), b"news.*");
        assert_eq!(bytes(field(&value, "c")), b"news.sport");
        assert_eq!(bytes(field(&value, "d")), b"hello");
    }

    #[test]
    fn an_empty_pattern_is_still_a_pattern_message() {
        // The kind comes from from_pattern, not from the pattern having bytes in
        // it: a psubscribe to an empty pattern is legal, however pointless, and
        // reading its messages as plain ones loses which subscription they match.
        let value = message_value(&message("pmessage", &["", "news", "hello"]));

        assert_eq!(field(&value, "k"), &Value::str("pmsg"));
        assert!(bytes(field(&value, "p")).is_empty());
    }

    #[test]
    fn a_channel_and_a_payload_may_be_any_bytes() {
        // A channel is a Redis key, and a key is bytes — reading either as a name
        // would drop whatever is not valid UTF-8.
        let raw = RedisValue::Array(vec![
            RedisValue::BulkString(b"message".to_vec()),
            RedisValue::BulkString(vec![0x00, 0xff]),
            RedisValue::BulkString(vec![0x00, 0x01, 0xfe]),
        ]);

        let value = message_value(&redis::Msg::from_value(&raw).expect("a pubsub message"));

        assert_eq!(bytes(field(&value, "c")), &[0x00, 0xff]);
        assert_eq!(bytes(field(&value, "d")), &[0x00, 0x01, 0xfe]);
    }

    #[test]
    fn a_batch_keeps_the_messages_in_the_order_they_arrived() {
        let batch = vec![
            message_value(&message("message", &["news", "one"])),
            message_value(&message("message", &["news", "two"])),
        ];

        let messages = batch_value(batch).as_list().expect("a list").to_vec();

        assert_eq!(messages.len(), 2);
        assert_eq!(bytes(field(&messages[0], "d")), b"one");
        assert_eq!(bytes(field(&messages[1], "d")), b"two");
    }

    #[test]
    fn an_empty_batch_is_an_empty_list() {
        // What a stream that ended answers with: PHP sees the iteration finish
        // rather than a message nobody published.
        assert!(batch_value(Vec::new())
            .as_list()
            .expect("a list")
            .is_empty());
    }
}
