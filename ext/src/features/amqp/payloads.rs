//! Mirrors ext-go-legacy/internal/features/amqp/payloads/payloads.go: the parameters of
//! every AMQP command and the results the feature sends back.
//!
//! Every message is a command envelope (`cm`/`p`) under `MethodEnum::Amqp` —
//! `cm` selects the AMQP method, `p` carries that method's parameters. On the
//! PHP side each command builds its own short-key map in the `AmqpCommandEnum`
//! case that names it, and the `serde` renames here are those same keys.
//!
//! Go declares the channel handle and the deadline once, in an embedded
//! `ChannelCommand` that msgpack inlines. Here they are declared per struct and
//! read through the `ChannelCommand` trait: `serde(flatten)` would buffer every
//! field through an intermediate value on a hot path, for no gain the wire can
//! see.

use rmp::encode;
use serde::Deserialize;

use super::values;

/// Reads a field that may arrive as an explicit nil.
///
/// `ext-msgpack` writes `null` for a PHP option nobody set, while Go's decoder
/// turns that into the zero value without comment. serde refuses it, so the two
/// are reconciled here: a missing key and a null one both mean "not set".
fn nullable<'de, D, T>(deserializer: D) -> std::result::Result<T, D::Error>
where
    D: serde::Deserializer<'de>,
    T: Default + serde::Deserialize<'de>,
{
    Ok(Option::<T>::deserialize(deserializer)?.unwrap_or_default())
}

/// `rmpv::Value` has no `Default`, and the absence of an optional field on the
/// wire is nil — which is exactly what the table and body readers treat as
/// "nothing was sent".
fn nil() -> rmpv::Value {
    rmpv::Value::Nil
}

/// The command envelope decoded from the msgpack message.
/// PHP: SConcur\Features\Amqp\Payloads\AmqpPayload.
#[derive(Deserialize)]
pub struct Envelope {
    #[serde(rename = "cm")]
    pub command: String,
    #[serde(rename = "p", default = "nil")]
    pub params: rmpv::Value,
}

/// What every command that runs on an already open channel carries: the handle
/// it runs on, and the deadline PHP put on it.
pub trait ChannelCommand {
    fn channel_id(&self) -> &str;
    fn timeout_ms(&self) -> i64;
}

macro_rules! channel_command {
    ($name:ident) => {
        impl ChannelCommand for $name {
            fn channel_id(&self) -> &str {
                &self.channel_id
            }

            fn timeout_ms(&self) -> i64 {
                self.timeout_ms
            }
        }
    };
}

/// The credentials, the TLS material and the tuning of one connection.
/// `connect_timeout_ms` bounds the dial; 0 leaves this feature's default.
///
/// The other three deadlines a connection carries — read, write and rpc — do
/// not travel here: they bound a command, not the connection, and PHP puts each
/// on the command it belongs to.
#[derive(Deserialize, Clone, PartialEq, Eq, Hash, Default)]
pub struct ConnectParams {
    #[serde(rename = "ho", default, deserialize_with = "nullable")]
    pub host: String,
    #[serde(rename = "po", default, deserialize_with = "nullable")]
    pub port: i64,
    #[serde(rename = "vh", default, deserialize_with = "nullable")]
    pub vhost: String,
    #[serde(rename = "lg", default, deserialize_with = "nullable")]
    pub login: String,
    #[serde(rename = "pw", default, deserialize_with = "nullable")]
    pub password: String,
    #[serde(rename = "ct", default, deserialize_with = "nullable")]
    pub connect_timeout_ms: i64,
    #[serde(rename = "cx", default, deserialize_with = "nullable")]
    pub channel_max: i64,
    #[serde(rename = "fx", default, deserialize_with = "nullable")]
    pub frame_max_bytes: i64,
    #[serde(rename = "hb", default, deserialize_with = "nullable")]
    pub heartbeat_seconds: i64,
    #[serde(rename = "sc", default, deserialize_with = "nullable")]
    pub secure: bool,
    #[serde(rename = "ca", default, deserialize_with = "nullable")]
    pub ca_cert_path: String,
    #[serde(rename = "ce", default, deserialize_with = "nullable")]
    pub cert_path: String,
    #[serde(rename = "ke", default, deserialize_with = "nullable")]
    pub key_path: String,
    #[serde(rename = "vf", default, deserialize_with = "nullable")]
    pub verify: bool,
    #[serde(rename = "sm", default, deserialize_with = "nullable")]
    pub sasl_method: i64,
    #[serde(rename = "cn", default, deserialize_with = "nullable")]
    pub connection_name: String,
}

/// The `p` content of the commands addressing a connection handle as a whole.
#[derive(Deserialize, Default)]
pub struct ConnectionParams {
    #[serde(rename = "cid", default, deserialize_with = "nullable")]
    pub connection_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
}

/// The `p` content of a ChannelOpen: the connection to open the channel on,
/// plus the prefetch applied right after opening, so channel creation stays one
/// crossing.
#[derive(Deserialize, Default)]
pub struct ChannelOpenParams {
    #[serde(rename = "cid", default, deserialize_with = "nullable")]
    pub connection_id: String,
    #[serde(rename = "sz", default, deserialize_with = "nullable")]
    pub prefetch_size_bytes: i64,
    #[serde(rename = "ct", default, deserialize_with = "nullable")]
    pub prefetch_count: i64,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
}

/// The `p` content of the commands that need nothing but the channel: closing
/// it, and waiting for its publisher confirms.
#[derive(Deserialize, Default)]
pub struct ChannelParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
}

channel_command!(ChannelParams);

#[derive(Deserialize, Default)]
pub struct QosParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "sz", default, deserialize_with = "nullable")]
    pub prefetch_size_bytes: i64,
    #[serde(rename = "ct", default, deserialize_with = "nullable")]
    pub prefetch_count: i64,
    #[serde(rename = "gl", default, deserialize_with = "nullable")]
    pub global: bool,
}

channel_command!(QosParams);

#[derive(Deserialize)]
pub struct ExchangeDeclareParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "na", default, deserialize_with = "nullable")]
    pub name: String,
    #[serde(rename = "ty", default, deserialize_with = "nullable")]
    pub kind: String,
    #[serde(rename = "pa", default, deserialize_with = "nullable")]
    pub passive: bool,
    #[serde(rename = "du", default, deserialize_with = "nullable")]
    pub durable: bool,
    #[serde(rename = "ad", default, deserialize_with = "nullable")]
    pub auto_delete: bool,
    #[serde(rename = "in", default, deserialize_with = "nullable")]
    pub internal: bool,
    #[serde(rename = "nw", default, deserialize_with = "nullable")]
    pub no_wait: bool,
    #[serde(rename = "ar", default = "nil")]
    pub arguments: rmpv::Value,
}

channel_command!(ExchangeDeclareParams);

#[derive(Deserialize, Default)]
pub struct ExchangeDeleteParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "na", default, deserialize_with = "nullable")]
    pub name: String,
    #[serde(rename = "iu", default, deserialize_with = "nullable")]
    pub if_unused: bool,
    #[serde(rename = "nw", default, deserialize_with = "nullable")]
    pub no_wait: bool,
}

channel_command!(ExchangeDeleteParams);

/// Messages flow from the source exchange to the destination one.
#[derive(Deserialize)]
pub struct ExchangeBindParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "ds", default, deserialize_with = "nullable")]
    pub destination: String,
    #[serde(rename = "sr", default, deserialize_with = "nullable")]
    pub source: String,
    #[serde(rename = "rk", default, deserialize_with = "nullable")]
    pub routing_key: String,
    #[serde(rename = "nw", default, deserialize_with = "nullable")]
    pub no_wait: bool,
    #[serde(rename = "ar", default = "nil")]
    pub arguments: rmpv::Value,
}

channel_command!(ExchangeBindParams);

/// An empty `name` asks the broker to generate one; `passive` selects the
/// declare-passive form.
#[derive(Deserialize)]
pub struct QueueDeclareParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "na", default, deserialize_with = "nullable")]
    pub name: String,
    #[serde(rename = "pa", default, deserialize_with = "nullable")]
    pub passive: bool,
    #[serde(rename = "du", default, deserialize_with = "nullable")]
    pub durable: bool,
    #[serde(rename = "ex", default, deserialize_with = "nullable")]
    pub exclusive: bool,
    #[serde(rename = "ad", default, deserialize_with = "nullable")]
    pub auto_delete: bool,
    #[serde(rename = "nw", default, deserialize_with = "nullable")]
    pub no_wait: bool,
    #[serde(rename = "ar", default = "nil")]
    pub arguments: rmpv::Value,
}

channel_command!(QueueDeclareParams);

#[derive(Deserialize, Default)]
pub struct QueueDeleteParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "na", default, deserialize_with = "nullable")]
    pub name: String,
    #[serde(rename = "iu", default, deserialize_with = "nullable")]
    pub if_unused: bool,
    #[serde(rename = "ie", default, deserialize_with = "nullable")]
    pub if_empty: bool,
    #[serde(rename = "nw", default, deserialize_with = "nullable")]
    pub no_wait: bool,
}

channel_command!(QueueDeleteParams);

#[derive(Deserialize)]
pub struct QueueBindParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "na", default, deserialize_with = "nullable")]
    pub queue_name: String,
    #[serde(rename = "en", default, deserialize_with = "nullable")]
    pub exchange_name: String,
    #[serde(rename = "rk", default, deserialize_with = "nullable")]
    pub routing_key: String,
    #[serde(rename = "nw", default, deserialize_with = "nullable")]
    pub no_wait: bool,
    #[serde(rename = "ar", default = "nil")]
    pub arguments: rmpv::Value,
}

channel_command!(QueueBindParams);

#[derive(Deserialize, Default)]
pub struct QueuePurgeParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "na", default, deserialize_with = "nullable")]
    pub name: String,
    #[serde(rename = "nw", default, deserialize_with = "nullable")]
    pub no_wait: bool,
}

channel_command!(QueuePurgeParams);

/// The AMQP basic properties of a message, in both directions. A property
/// nobody set does not travel — AMQP distinguishes an absent property from an
/// empty one, and PHP's `PropertiesCodec` writes exactly what the message was
/// built with.
///
/// cluster-id is absent: AMQP 0-9-1 excludes it from publishing, and the driver
/// does not surface it on a delivery either.
#[derive(Deserialize)]
pub struct Properties {
    #[serde(rename = "ct", default, deserialize_with = "nullable")]
    pub content_type: String,
    #[serde(rename = "ce", default, deserialize_with = "nullable")]
    pub content_encoding: String,
    #[serde(rename = "hd", default = "nil")]
    pub headers: rmpv::Value,
    #[serde(rename = "dm", default, deserialize_with = "nullable")]
    pub delivery_mode: i64,
    #[serde(rename = "pr", default, deserialize_with = "nullable")]
    pub priority: i64,
    #[serde(rename = "ci", default, deserialize_with = "nullable")]
    pub correlation_id: String,
    #[serde(rename = "rp", default, deserialize_with = "nullable")]
    pub reply_to: String,
    #[serde(rename = "ep", default, deserialize_with = "nullable")]
    pub expiration: String,
    #[serde(rename = "mi", default, deserialize_with = "nullable")]
    pub message_id: String,
    #[serde(rename = "ts", default, deserialize_with = "nullable")]
    pub timestamp: i64,
    #[serde(rename = "ty", default, deserialize_with = "nullable")]
    pub kind: String,
    #[serde(rename = "ui", default, deserialize_with = "nullable")]
    pub user_id: String,
    #[serde(rename = "ai", default, deserialize_with = "nullable")]
    pub app_id: String,
}

impl Default for Properties {
    fn default() -> Self {
        Properties {
            content_type: String::new(),
            content_encoding: String::new(),
            headers: rmpv::Value::Nil,
            delivery_mode: 0,
            priority: 0,
            correlation_id: String::new(),
            reply_to: String::new(),
            expiration: String::new(),
            message_id: String::new(),
            timestamp: 0,
            kind: String::new(),
            user_id: String::new(),
            app_id: String::new(),
        }
    }
}

#[derive(Deserialize)]
pub struct PublishParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "en", default, deserialize_with = "nullable")]
    pub exchange_name: String,
    #[serde(rename = "rk", default, deserialize_with = "nullable")]
    pub routing_key: String,
    #[serde(rename = "ma", default, deserialize_with = "nullable")]
    pub mandatory: bool,
    #[serde(rename = "im", default, deserialize_with = "nullable")]
    pub immediate: bool,
    /// Untyped on purpose. A message body is arbitrary bytes, and declaring it
    /// a String would corrupt every body that is not valid UTF-8 — the same
    /// mistake the HTTP server's respond payload made before
    /// `testBinaryBodyRoundTripsExactly` caught it.
    #[serde(rename = "bd", default = "nil")]
    pub body: rmpv::Value,
    #[serde(rename = "ps", default, deserialize_with = "nullable")]
    pub properties: Properties,
}

channel_command!(PublishParams);

#[derive(Deserialize, Default)]
pub struct GetParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "na", default, deserialize_with = "nullable")]
    pub queue_name: String,
    #[serde(rename = "aa", default, deserialize_with = "nullable")]
    pub auto_ack: bool,
}

channel_command!(GetParams);

/// The streaming command. It carries two deadlines: the inherited one bounds
/// the `basic.consume` that opens the consumer, and the consumer then lives on,
/// bounded by `read_timeout_ms` (0 waits indefinitely).
#[derive(Deserialize)]
pub struct ConsumeParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "na", default, deserialize_with = "nullable")]
    pub queue_name: String,
    #[serde(rename = "tg", default, deserialize_with = "nullable")]
    pub consumer_tag: String,
    #[serde(rename = "aa", default, deserialize_with = "nullable")]
    pub auto_ack: bool,
    #[serde(rename = "ex", default, deserialize_with = "nullable")]
    pub exclusive: bool,
    #[serde(rename = "nl", default, deserialize_with = "nullable")]
    pub no_local: bool,
    #[serde(rename = "nw", default, deserialize_with = "nullable")]
    pub no_wait: bool,
    #[serde(rename = "ar", default = "nil")]
    pub arguments: rmpv::Value,
    #[serde(rename = "rt", default, deserialize_with = "nullable")]
    pub read_timeout_ms: i64,
}

channel_command!(ConsumeParams);

/// One queue a supervised consumer pulls: how many consumers it gets — each on
/// a channel of its own, which is what gives a hot queue more capacity than a
/// quiet one — and the prefetch each of them carries.
#[derive(Deserialize, Default, Clone)]
pub struct ConsumeServeQueue {
    #[serde(rename = "na", default, deserialize_with = "nullable")]
    pub name: String,
    /// The queue's weight; below 1 it is read as 1.
    #[serde(rename = "cn", default, deserialize_with = "nullable")]
    pub consumers: i64,
    /// This queue's own prefetch; 0 takes the worker-wide one.
    #[serde(rename = "ct", default, deserialize_with = "nullable")]
    pub prefetch_count: i64,
}

/// Every queue one supervised worker pulls, opened on channels this feature
/// owns.
///
/// There is no read timeout here, unlike `ConsumeParams`: a supervised worker
/// has no idle deadline to enforce — a queue that stays quiet is not a failure
/// — and the stream ends when the flow does.
#[derive(Deserialize, Default)]
pub struct ConsumeServeParams {
    #[serde(rename = "cid", default, deserialize_with = "nullable")]
    pub connection_id: String,
    #[serde(rename = "qs", default, deserialize_with = "nullable")]
    pub queues: Vec<ConsumeServeQueue>,
    /// What a queue naming none of its own gets.
    #[serde(rename = "ct", default, deserialize_with = "nullable")]
    pub prefetch_count: i64,
    #[serde(rename = "aa", default, deserialize_with = "nullable")]
    pub auto_ack: bool,
    /// How long a consumer the broker took away waits before it is opened
    /// again; 0 leaves the feature's default.
    #[serde(rename = "rd", default, deserialize_with = "nullable")]
    pub reopen_delay_ms: i64,
    /// Bounds opening one channel and its consumer.
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
}

#[derive(Deserialize, Default)]
pub struct CancelParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "tg", default, deserialize_with = "nullable")]
    pub consumer_tag: String,
    #[serde(rename = "nw", default, deserialize_with = "nullable")]
    pub no_wait: bool,
}

channel_command!(CancelParams);

#[derive(Deserialize, Default)]
pub struct AckParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "dt", default, deserialize_with = "nullable")]
    pub delivery_tag: u64,
    #[serde(rename = "mu", default, deserialize_with = "nullable")]
    pub multiple: bool,
}

channel_command!(AckParams);

#[derive(Deserialize, Default)]
pub struct NackParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "dt", default, deserialize_with = "nullable")]
    pub delivery_tag: u64,
    #[serde(rename = "mu", default, deserialize_with = "nullable")]
    pub multiple: bool,
    #[serde(rename = "rq", default, deserialize_with = "nullable")]
    pub requeue: bool,
}

channel_command!(NackParams);

#[derive(Deserialize, Default)]
pub struct RejectParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "dt", default, deserialize_with = "nullable")]
    pub delivery_tag: u64,
    #[serde(rename = "rq", default, deserialize_with = "nullable")]
    pub requeue: bool,
}

channel_command!(RejectParams);

#[derive(Deserialize, Default)]
pub struct ConfirmSelectParams {
    #[serde(rename = "chid", default, deserialize_with = "nullable")]
    pub channel_id: String,
    #[serde(rename = "to", default, deserialize_with = "nullable")]
    pub timeout_ms: i64,
    #[serde(rename = "nw", default, deserialize_with = "nullable")]
    pub no_wait: bool,
}

channel_command!(ConfirmSelectParams);

// --- results -----------------------------------------------------------------
//
// Written with rmp directly rather than through serde, so an omitted property
// is omitted rather than sent as an empty string — the same `omitempty` the Go
// structs carry, and what PropertiesCodec reads back as "nobody set this".

/// What a Connect answers with: the handle the later commands address, plus the
/// values the handshake settled on.
pub fn encode_connect_result(
    connection_id: &str,
    max_channels: u16,
    max_frame_size: u32,
    heartbeat: u16,
) -> Vec<u8> {
    let mut buffer = Vec::new();

    encode::write_map_len(&mut buffer, 4).ok();
    write_str(&mut buffer, "cid");
    write_str(&mut buffer, connection_id);
    write_str(&mut buffer, "mc");
    write_int(&mut buffer, max_channels as i64);
    write_str(&mut buffer, "mf");
    write_int(&mut buffer, max_frame_size as i64);
    write_str(&mut buffer, "hb");
    write_int(&mut buffer, heartbeat as i64);

    buffer
}

pub fn encode_used_channels_result(used: i64) -> Vec<u8> {
    let mut buffer = Vec::new();

    encode::write_map_len(&mut buffer, 1).ok();
    write_str(&mut buffer, "uc");
    write_int(&mut buffer, used);

    buffer
}

/// The handle plus the number this feature gave the channel on its connection,
/// which PHP hands back through `Channel::id()`. It is a counter per connection
/// handle, not the channel number AMQP puts on the wire — the driver owns that
/// one and does not report it.
pub fn encode_channel_open_result(channel_id: &str, channel_number: i64) -> Vec<u8> {
    let mut buffer = Vec::new();

    encode::write_map_len(&mut buffer, 2).ok();
    write_str(&mut buffer, "chid");
    write_str(&mut buffer, channel_id);
    write_str(&mut buffer, "no");
    write_int(&mut buffer, channel_number);

    buffer
}

/// The name (the generated one when the request carried none) and how many
/// messages and consumers the queue has.
pub fn encode_queue_declare_result(name: &str, message_count: u32, consumer_count: u32) -> Vec<u8> {
    let mut buffer = Vec::new();

    encode::write_map_len(&mut buffer, 3).ok();
    write_str(&mut buffer, "na");
    write_str(&mut buffer, name);
    write_str(&mut buffer, "mc");
    write_int(&mut buffer, message_count as i64);
    write_str(&mut buffer, "cc");
    write_int(&mut buffer, consumer_count as i64);

    buffer
}

/// Answers the commands that report how many messages they moved (QueueDelete,
/// QueuePurge).
pub fn encode_message_count_result(message_count: u32) -> Vec<u8> {
    let mut buffer = Vec::new();

    encode::write_map_len(&mut buffer, 1).ok();
    write_str(&mut buffer, "mc");
    write_int(&mut buffer, message_count as i64);

    buffer
}

/// The first result of a Consume: the tag the broker assigned, which PHP keys
/// its consumer registry by.
pub fn encode_consumer_meta(consumer_tag: &str) -> Vec<u8> {
    let mut buffer = Vec::new();

    encode::write_map_len(&mut buffer, 1).ok();
    write_str(&mut buffer, "tg");
    write_str(&mut buffer, consumer_tag);

    buffer
}

/// The answer of a command that only reports that it ran.
pub fn encode_done() -> Vec<u8> {
    let mut buffer = Vec::new();

    encode::write_map_len(&mut buffer, 0).ok();

    buffer
}

/// One message handed to PHP, by a Get or through a consumer's stream. It
/// carries the channel it was delivered on, because a delivery tag is only
/// valid there.
pub struct DeliveryOut<'a> {
    pub channel_id: &'a str,
    pub consumer_tag: &'a str,
    pub delivery_tag: u64,
    pub redelivered: bool,
    pub exchange_name: &'a str,
    pub routing_key: &'a str,
    pub body: &'a [u8],
    pub properties: &'a lapin::protocol::basic::AMQPProperties,
}

pub fn encode_delivery(delivery: &DeliveryOut<'_>) -> Vec<u8> {
    let mut buffer = Vec::new();

    encode::write_map_len(&mut buffer, 8).ok();
    write_str(&mut buffer, "chid");
    write_str(&mut buffer, delivery.channel_id);
    write_str(&mut buffer, "tg");
    write_str(&mut buffer, delivery.consumer_tag);
    write_str(&mut buffer, "dt");
    write_uint(&mut buffer, delivery.delivery_tag);
    write_str(&mut buffer, "rd");
    encode::write_bool(&mut buffer, delivery.redelivered).ok();
    write_str(&mut buffer, "en");
    write_str(&mut buffer, delivery.exchange_name);
    write_str(&mut buffer, "rk");
    write_str(&mut buffer, delivery.routing_key);
    write_str(&mut buffer, "bd");
    write_bytes_as_str(&mut buffer, delivery.body);
    write_str(&mut buffer, "ps");
    encode_properties(&mut buffer, delivery.properties);

    buffer
}

/// One message the broker could not route and sent back.
pub struct ReturnOut<'a> {
    pub reply_code: i64,
    pub reply_text: &'a str,
    pub exchange_name: &'a str,
    pub routing_key: &'a str,
    pub body: &'a [u8],
    pub properties: &'a lapin::protocol::basic::AMQPProperties,
}

/// Answers ConfirmWait: every confirmation and every returned message that
/// arrived before the deadline. The two travel together on purpose — a
/// mandatory message the broker could not route is returned *and* acknowledged,
/// so reading the confirmations without the returns would report a success for
/// a message that reached no queue.
pub fn encode_wait_result(confirmations: &[(u64, bool)], returns: &[ReturnOut<'_>]) -> Vec<u8> {
    let mut buffer = Vec::new();

    encode::write_map_len(&mut buffer, 2).ok();

    write_str(&mut buffer, "cf");
    encode::write_array_len(&mut buffer, confirmations.len() as u32).ok();

    for (delivery_tag, acked) in confirmations {
        encode::write_map_len(&mut buffer, 2).ok();
        write_str(&mut buffer, "dt");
        write_uint(&mut buffer, *delivery_tag);
        write_str(&mut buffer, "ak");
        encode::write_bool(&mut buffer, *acked).ok();
    }

    write_str(&mut buffer, "rt");
    encode::write_array_len(&mut buffer, returns.len() as u32).ok();

    for returned in returns {
        encode::write_map_len(&mut buffer, 6).ok();
        write_str(&mut buffer, "rc");
        write_int(&mut buffer, returned.reply_code);
        write_str(&mut buffer, "rx");
        write_str(&mut buffer, returned.reply_text);
        write_str(&mut buffer, "en");
        write_str(&mut buffer, returned.exchange_name);
        write_str(&mut buffer, "rk");
        write_str(&mut buffer, returned.routing_key);
        write_str(&mut buffer, "bd");
        write_bytes_as_str(&mut buffer, returned.body);
        write_str(&mut buffer, "ps");
        encode_properties(&mut buffer, returned.properties);
    }

    buffer
}

/// Writes the properties a message actually carries and nothing else — the
/// `omitempty` of the Go struct, which `PropertiesCodec::decode` reads as "this
/// one was never set".
pub fn encode_properties(buffer: &mut Vec<u8>, properties: &lapin::protocol::basic::AMQPProperties) {
    let mut fields: Vec<(&str, PropertyValue<'_>)> = Vec::with_capacity(13);

    push_text(&mut fields, "ct", properties.content_type());
    push_text(&mut fields, "ce", properties.content_encoding());

    if let Some(headers) = properties.headers() {
        if !headers.inner().is_empty() {
            fields.push(("hd", PropertyValue::Table(headers)));
        }
    }

    push_number(&mut fields, "dm", properties.delivery_mode().map(i64::from));
    push_number(&mut fields, "pr", properties.priority().map(i64::from));
    push_text(&mut fields, "ci", properties.correlation_id());
    push_text(&mut fields, "rp", properties.reply_to());
    push_text(&mut fields, "ep", properties.expiration());
    push_text(&mut fields, "mi", properties.message_id());
    push_number(
        &mut fields,
        "ts",
        properties.timestamp().map(|seconds| seconds as i64),
    );
    push_text(&mut fields, "ty", properties.kind());
    push_text(&mut fields, "ui", properties.user_id());
    push_text(&mut fields, "ai", properties.app_id());

    encode::write_map_len(buffer, fields.len() as u32).ok();

    for (key, value) in fields {
        write_str(buffer, key);

        match value {
            PropertyValue::Text(text) => write_str(buffer, text),
            PropertyValue::Number(number) => write_int(buffer, number),
            PropertyValue::Table(table) => values::encode_table(buffer, table),
        }
    }
}

enum PropertyValue<'a> {
    Text(&'a str),
    Number(i64),
    Table(&'a lapin::types::FieldTable),
}

fn push_text<'a>(
    fields: &mut Vec<(&'static str, PropertyValue<'a>)>,
    key: &'static str,
    value: &'a Option<lapin::types::ShortString>,
) {
    if let Some(text) = value {
        if !text.as_str().is_empty() {
            fields.push((key, PropertyValue::Text(text.as_str())));
        }
    }
}

fn push_number(
    fields: &mut Vec<(&'static str, PropertyValue<'_>)>,
    key: &'static str,
    value: Option<i64>,
) {
    // Zero is "not set" here, matching Go's omitempty on an integer: a delivery
    // mode of 0 is not a mode, and a priority of 0 is the default the broker
    // assumes anyway.
    if let Some(number) = value {
        if number != 0 {
            fields.push((key, PropertyValue::Number(number)));
        }
    }
}

/// The bytes of a value PHP sent as a string. `ext-msgpack` writes a PHP
/// string as msgpack *str* whatever bytes it holds, so a binary body arrives
/// here as a str whose contents are not valid UTF-8.
pub fn bytes_of(value: &rmpv::Value) -> &[u8] {
    match value {
        rmpv::Value::String(text) => text.as_bytes(),
        rmpv::Value::Binary(bytes) => bytes.as_slice(),
        _ => &[],
    }
}

fn write_int(buffer: &mut Vec<u8>, number: i64) {
    encode::write_sint(buffer, number).ok();
}

fn write_uint(buffer: &mut Vec<u8>, number: u64) {
    encode::write_uint(buffer, number).ok();
}

fn write_str(buffer: &mut Vec<u8>, text: &str) {
    encode::write_str(buffer, text).ok();
}

/// A MessagePack *str*, not *bin*: a message body is a PHP string whatever
/// bytes it holds, which is what `string([]byte)` becomes on the Go wire.
fn write_bytes_as_str(buffer: &mut Vec<u8>, bytes: &[u8]) {
    encode::write_str_len(buffer, bytes.len() as u32).ok();
    buffer.extend_from_slice(bytes);
}
