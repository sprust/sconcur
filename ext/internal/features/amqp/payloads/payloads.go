// Package payloads holds the parameters of every amqp command and the results the feature
// sends back. On the PHP side each command builds its own short-key map in the
// AmqpCommandEnum case that names it; the struct tags here are those same keys.
//
// Every message is a command envelope (cm/p) under MethodAmqp — mirrors WsClient: cm
// selects the AMQP method, p carries that method's parameters.
package payloads

import (
	"sconcur/internal/types"

	"github.com/vmihailenco/msgpack/v5"
)

// Envelope is the command envelope decoded from the msgpack message.
// PHP: SConcur\Features\Amqp\Payloads\AmqpPayload.
type Envelope struct {
	Command types.AmqpCommand  `json:"cm" msgpack:"cm"`
	Params  msgpack.RawMessage `json:"p" msgpack:"p"`
}

// ConnectParams is the `p` content of a Connect command: the credentials, the TLS
// material and the tuning of one connection. ConnectTimeoutMs is in milliseconds and
// bounds the dial; 0 leaves the default of this package in place.
//
// The other three deadlines a connection carries — read, write and rpc — do not
// travel here: they bound a command, not the connection, and PHP puts each of them on the
// command it belongs to (rpc on every one-shot method, write on a publish, read on the
// wait for a consumer's next delivery).
// PHP: built by the AmqpCommandEnum case that names this struct.
type ConnectParams struct {
	Host             string `json:"ho" msgpack:"ho"`
	Port             int    `json:"po" msgpack:"po"`
	Vhost            string `json:"vh" msgpack:"vh"`
	Login            string `json:"lg" msgpack:"lg"`
	Password         string `json:"pw" msgpack:"pw"`
	ConnectTimeoutMs int    `json:"ct" msgpack:"ct"`
	ChannelMax       int    `json:"cx" msgpack:"cx"`
	FrameMaxBytes    int    `json:"fx" msgpack:"fx"`
	HeartbeatSeconds int    `json:"hb" msgpack:"hb"`
	Secure           bool   `json:"sc" msgpack:"sc"`
	CaCertPath       string `json:"ca" msgpack:"ca"`
	CertPath         string `json:"ce" msgpack:"ce"`
	KeyPath          string `json:"ke" msgpack:"ke"`
	Verify           bool   `json:"vf" msgpack:"vf"`
	SaslMethod       int    `json:"sm" msgpack:"sm"`
	ConnectionName   string `json:"cn" msgpack:"cn"`
}

// ConnectResult is what a Connect answers with: the handle the later commands address,
// plus the values the handshake settled on.
// PHP: decoded in SConcur\Features\Amqp\Connection::connect.
type ConnectResult struct {
	ConnectionId string `json:"cid" msgpack:"cid"`
	MaxChannels  int    `json:"mc" msgpack:"mc"`
	MaxFrameSize int    `json:"mf" msgpack:"mf"`
	Heartbeat    int    `json:"hb" msgpack:"hb"`
}

// ConnectionParams is the `p` content of the commands addressing a connection handle as a
// whole.
// PHP: built by the AmqpCommandEnum case that names this struct.
type ConnectionParams struct {
	ConnectionId string `json:"cid" msgpack:"cid"`
	TimeoutMs    int    `json:"to" msgpack:"to"`
}

// UsedChannelsResult answers a UsedChannels command.
// PHP: decoded in SConcur\Features\Amqp\Connection::usedChannels.
type UsedChannelsResult struct {
	UsedChannels int `json:"uc" msgpack:"uc"`
}

// ChannelOpenParams is the `p` content of a ChannelOpen command: the connection to open
// the channel on, plus the prefetch settings applied right after opening (ext-amqp sends
// them as a separate basic.qos; carrying them here keeps channel creation one crossing).
// PHP: built by the AmqpCommandEnum case that names this struct.
type ChannelOpenParams struct {
	ConnectionId            string `json:"cid" msgpack:"cid"`
	PrefetchSizeBytes       int    `json:"sz" msgpack:"sz"`
	PrefetchCount           int    `json:"ct" msgpack:"ct"`
	GlobalPrefetchSizeBytes int    `json:"gsz" msgpack:"gsz"`
	GlobalPrefetchCount     int    `json:"gct" msgpack:"gct"`
	TimeoutMs               int    `json:"to" msgpack:"to"`
}

// ChannelOpenResult answers a ChannelOpen: the handle plus the number this feature gave the
// channel on its connection, which PHP hands back through Channel::id(). It is a counter
// per connection handle, not the channel number AMQP puts on the wire — the driver owns
// that one and does not report it.
// PHP: decoded in SConcur\Features\Amqp\Channel::__construct.
type ChannelOpenResult struct {
	ChannelId     string `json:"chid" msgpack:"chid"`
	ChannelNumber int    `json:"no" msgpack:"no"`
}

// ChannelParams is the `p` content of the commands that need nothing but the channel:
// closing it, and waiting for its publisher confirms.
// PHP: built by the AmqpCommandEnum case that names this struct.
type ChannelParams struct {
	ChannelId string `json:"chid" msgpack:"chid"`
	TimeoutMs int    `json:"to" msgpack:"to"`
}

// QosParams is the `p` content of a Qos command.
// PHP: built by the AmqpCommandEnum case that names this struct.
type QosParams struct {
	ChannelId         string `json:"chid" msgpack:"chid"`
	PrefetchSizeBytes int    `json:"sz" msgpack:"sz"`
	PrefetchCount     int    `json:"ct" msgpack:"ct"`
	Global            bool   `json:"gl" msgpack:"gl"`
	TimeoutMs         int    `json:"to" msgpack:"to"`
}

// ExchangeDeclareParams is the `p` content of an ExchangeDeclare command; Passive selects
// the declare-passive form.
// PHP: built by the AmqpCommandEnum case that names this struct.
type ExchangeDeclareParams struct {
	ChannelId  string `json:"chid" msgpack:"chid"`
	Name       string `json:"na" msgpack:"na"`
	Type       string `json:"ty" msgpack:"ty"`
	Passive    bool   `json:"pa" msgpack:"pa"`
	Durable    bool   `json:"du" msgpack:"du"`
	AutoDelete bool   `json:"ad" msgpack:"ad"`
	Internal   bool   `json:"in" msgpack:"in"`
	NoWait     bool   `json:"nw" msgpack:"nw"`
	Arguments  Table  `json:"ar" msgpack:"ar"`
	TimeoutMs  int    `json:"to" msgpack:"to"`
}

// ExchangeDeleteParams is the `p` content of an ExchangeDelete command.
// PHP: built by the AmqpCommandEnum case that names this struct.
type ExchangeDeleteParams struct {
	ChannelId string `json:"chid" msgpack:"chid"`
	Name      string `json:"na" msgpack:"na"`
	IfUnused  bool   `json:"iu" msgpack:"iu"`
	NoWait    bool   `json:"nw" msgpack:"nw"`
	TimeoutMs int    `json:"to" msgpack:"to"`
}

// ExchangeBindParams is the `p` content of an ExchangeBind or ExchangeUnbind command:
// messages flow from the source exchange to the destination one.
// PHP: built by the AmqpCommandEnum case that names this struct.
type ExchangeBindParams struct {
	ChannelId   string `json:"chid" msgpack:"chid"`
	Destination string `json:"ds" msgpack:"ds"`
	Source      string `json:"sr" msgpack:"sr"`
	RoutingKey  string `json:"rk" msgpack:"rk"`
	NoWait      bool   `json:"nw" msgpack:"nw"`
	Arguments   Table  `json:"ar" msgpack:"ar"`
	TimeoutMs   int    `json:"to" msgpack:"to"`
}

// QueueDeclareParams is the `p` content of a QueueDeclare command; an empty Name asks the
// broker to generate one, and Passive selects the declare-passive form.
// PHP: built by the AmqpCommandEnum case that names this struct.
type QueueDeclareParams struct {
	ChannelId  string `json:"chid" msgpack:"chid"`
	Name       string `json:"na" msgpack:"na"`
	Passive    bool   `json:"pa" msgpack:"pa"`
	Durable    bool   `json:"du" msgpack:"du"`
	Exclusive  bool   `json:"ex" msgpack:"ex"`
	AutoDelete bool   `json:"ad" msgpack:"ad"`
	NoWait     bool   `json:"nw" msgpack:"nw"`
	Arguments  Table  `json:"ar" msgpack:"ar"`
	TimeoutMs  int    `json:"to" msgpack:"to"`
}

// QueueDeclareResult answers a QueueDeclare: the name (the generated one when the request
// carried none) and how many messages and consumers the queue has.
// PHP: decoded in SConcur\Features\Amqp\Queue::declare.
type QueueDeclareResult struct {
	Name          string `json:"na" msgpack:"na"`
	MessageCount  int    `json:"mc" msgpack:"mc"`
	ConsumerCount int    `json:"cc" msgpack:"cc"`
}

// QueueDeleteParams is the `p` content of a QueueDelete command.
// PHP: built by the AmqpCommandEnum case that names this struct.
type QueueDeleteParams struct {
	ChannelId string `json:"chid" msgpack:"chid"`
	Name      string `json:"na" msgpack:"na"`
	IfUnused  bool   `json:"iu" msgpack:"iu"`
	IfEmpty   bool   `json:"ie" msgpack:"ie"`
	NoWait    bool   `json:"nw" msgpack:"nw"`
	TimeoutMs int    `json:"to" msgpack:"to"`
}

// MessageCountResult answers the commands that report how many messages they moved
// (QueueDelete, QueuePurge).
// PHP: decoded in SConcur\Features\Amqp\Queue::delete and ::purge.
type MessageCountResult struct {
	MessageCount int `json:"mc" msgpack:"mc"`
}

// QueueBindParams is the `p` content of a QueueBind or QueueUnbind command.
// PHP: built by the AmqpCommandEnum case that names this struct.
type QueueBindParams struct {
	ChannelId    string `json:"chid" msgpack:"chid"`
	QueueName    string `json:"na" msgpack:"na"`
	ExchangeName string `json:"en" msgpack:"en"`
	RoutingKey   string `json:"rk" msgpack:"rk"`
	NoWait       bool   `json:"nw" msgpack:"nw"`
	Arguments    Table  `json:"ar" msgpack:"ar"`
	TimeoutMs    int    `json:"to" msgpack:"to"`
}

// QueuePurgeParams is the `p` content of a QueuePurge command.
// PHP: built by the AmqpCommandEnum case that names this struct.
type QueuePurgeParams struct {
	ChannelId string `json:"chid" msgpack:"chid"`
	Name      string `json:"na" msgpack:"na"`
	NoWait    bool   `json:"nw" msgpack:"nw"`
	TimeoutMs int    `json:"to" msgpack:"to"`
}

// Properties are the AMQP basic properties of a message, in both directions: published
// with PublishParams, delivered with Delivery. Empty fields are left unset on the wire.
//
// cluster-id is absent: AMQP 0-9-1 excludes it from publishing, and the driver does not
// surface it on a delivery either, so MessageProperties::$clusterId is always null
// (docs/amqp.md lists it among the deviations).
// PHP: SConcur\Features\Amqp\Support\PropertiesCodec.
type Properties struct {
	ContentType     string `json:"ct,omitempty" msgpack:"ct,omitempty"`
	ContentEncoding string `json:"ce,omitempty" msgpack:"ce,omitempty"`
	Headers         Table  `json:"hd,omitempty" msgpack:"hd,omitempty"`
	DeliveryMode    int    `json:"dm,omitempty" msgpack:"dm,omitempty"`
	Priority        int    `json:"pr,omitempty" msgpack:"pr,omitempty"`
	CorrelationId   string `json:"ci,omitempty" msgpack:"ci,omitempty"`
	ReplyTo         string `json:"rp,omitempty" msgpack:"rp,omitempty"`
	Expiration      string `json:"ep,omitempty" msgpack:"ep,omitempty"`
	MessageId       string `json:"mi,omitempty" msgpack:"mi,omitempty"`
	Timestamp       int64  `json:"ts,omitempty" msgpack:"ts,omitempty"`
	Type            string `json:"ty,omitempty" msgpack:"ty,omitempty"`
	UserId          string `json:"ui,omitempty" msgpack:"ui,omitempty"`
	AppId           string `json:"ai,omitempty" msgpack:"ai,omitempty"`
}

// PublishParams is the `p` content of a Publish command.
// PHP: built by the AmqpCommandEnum case that names this struct.
type PublishParams struct {
	ChannelId    string     `json:"chid" msgpack:"chid"`
	ExchangeName string     `json:"en" msgpack:"en"`
	RoutingKey   string     `json:"rk" msgpack:"rk"`
	Mandatory    bool       `json:"ma" msgpack:"ma"`
	Immediate    bool       `json:"im" msgpack:"im"`
	Body         string     `json:"bd" msgpack:"bd"`
	Properties   Properties `json:"ps" msgpack:"ps"`
	TimeoutMs    int        `json:"to" msgpack:"to"`
}

// GetParams is the `p` content of a Get command.
// PHP: built by the AmqpCommandEnum case that names this struct.
type GetParams struct {
	ChannelId string `json:"chid" msgpack:"chid"`
	QueueName string `json:"na" msgpack:"na"`
	AutoAck   bool   `json:"aa" msgpack:"aa"`
	TimeoutMs int    `json:"to" msgpack:"to"`
}

// Delivery is one message handed to PHP, by a Get or through a consumer's stream. It
// carries the channel it was delivered on, because a delivery tag is only valid there.
// PHP: built into a Delivery by SConcur\Features\Amqp\Support\DeliveryCodec::delivery.
type Delivery struct {
	ChannelId    string     `json:"chid" msgpack:"chid"`
	ConsumerTag  string     `json:"tg" msgpack:"tg"`
	DeliveryTag  uint64     `json:"dt" msgpack:"dt"`
	Redelivered  bool       `json:"rd" msgpack:"rd"`
	ExchangeName string     `json:"en" msgpack:"en"`
	RoutingKey   string     `json:"rk" msgpack:"rk"`
	Body         string     `json:"bd" msgpack:"bd"`
	Properties   Properties `json:"ps" msgpack:"ps"`
}

// ConsumeParams is the `p` content of a Consume command — the streaming one.
// PHP: built by the AmqpCommandEnum case that names this struct.
type ConsumeParams struct {
	ChannelId   string `json:"chid" msgpack:"chid"`
	QueueName   string `json:"na" msgpack:"na"`
	ConsumerTag string `json:"tg" msgpack:"tg"`
	AutoAck     bool   `json:"aa" msgpack:"aa"`
	Exclusive   bool   `json:"ex" msgpack:"ex"`
	NoLocal     bool   `json:"nl" msgpack:"nl"`
	NoWait      bool   `json:"nw" msgpack:"nw"`
	Arguments   Table  `json:"ar" msgpack:"ar"`
	// ReadTimeoutMs bounds the wait for the next delivery; 0 waits indefinitely.
	ReadTimeoutMs int `json:"rt" msgpack:"rt"`
	// TimeoutMs bounds the basic.consume that opens the consumer — the consumer itself
	// then lives on, bounded by ReadTimeoutMs.
	TimeoutMs int `json:"to" msgpack:"to"`
}

// ConsumerMeta is the first result of a Consume: the tag the broker assigned, which PHP
// keys the consumer registry by.
// PHP: decoded in SConcur\Features\Amqp\Channel::consume.
type ConsumerMeta struct {
	ConsumerTag string `json:"tg" msgpack:"tg"`
}

// CancelParams is the `p` content of a Cancel command.
// PHP: built by the AmqpCommandEnum case that names this struct.
type CancelParams struct {
	ChannelId   string `json:"chid" msgpack:"chid"`
	ConsumerTag string `json:"tg" msgpack:"tg"`
	NoWait      bool   `json:"nw" msgpack:"nw"`
	TimeoutMs   int    `json:"to" msgpack:"to"`
}

// AckParams is the `p` content of an Ack command.
// PHP: built by the AmqpCommandEnum case that names this struct.
type AckParams struct {
	ChannelId   string `json:"chid" msgpack:"chid"`
	DeliveryTag uint64 `json:"dt" msgpack:"dt"`
	Multiple    bool   `json:"mu" msgpack:"mu"`
	TimeoutMs   int    `json:"to" msgpack:"to"`
}

// NackParams is the `p` content of a Nack command.
// PHP: built by the AmqpCommandEnum case that names this struct.
type NackParams struct {
	ChannelId   string `json:"chid" msgpack:"chid"`
	DeliveryTag uint64 `json:"dt" msgpack:"dt"`
	Multiple    bool   `json:"mu" msgpack:"mu"`
	Requeue     bool   `json:"rq" msgpack:"rq"`
	TimeoutMs   int    `json:"to" msgpack:"to"`
}

// RejectParams is the `p` content of a Reject command.
// PHP: built by the AmqpCommandEnum case that names this struct.
type RejectParams struct {
	ChannelId   string `json:"chid" msgpack:"chid"`
	DeliveryTag uint64 `json:"dt" msgpack:"dt"`
	Requeue     bool   `json:"rq" msgpack:"rq"`
	TimeoutMs   int    `json:"to" msgpack:"to"`
}

// ConfirmSelectParams is the `p` content of a ConfirmSelect command.
// PHP: built by the AmqpCommandEnum case that names this struct.
type ConfirmSelectParams struct {
	ChannelId string `json:"chid" msgpack:"chid"`
	NoWait    bool   `json:"nw" msgpack:"nw"`
	TimeoutMs int    `json:"to" msgpack:"to"`
}

// Confirmation is one publisher confirm: the tag of the message and whether the broker
// took it. There is no "multiple" flag — the driver resolves the broker's "up to and
// including" confirms into individual ones before this side ever sees them.
// PHP: read in SConcur\Features\Amqp\Support\DeliveryCodec::failOnNacks.
type Confirmation struct {
	DeliveryTag uint64 `json:"dt" msgpack:"dt"`
	Acked       bool   `json:"ak" msgpack:"ak"`
}

// ReturnedMessage is one message the broker could not route and sent back.
// PHP: read in SConcur\Features\Amqp\Support\DeliveryCodec::failOnReturns.
type ReturnedMessage struct {
	ReplyCode    int        `json:"rc" msgpack:"rc"`
	ReplyText    string     `json:"rx" msgpack:"rx"`
	ExchangeName string     `json:"en" msgpack:"en"`
	RoutingKey   string     `json:"rk" msgpack:"rk"`
	Body         string     `json:"bd" msgpack:"bd"`
	Properties   Properties `json:"ps" msgpack:"ps"`
}

// WaitResult answers ConfirmWait: every confirmation and every returned message that
// arrived before the deadline. The two travel together on purpose — a mandatory message
// the broker could not route is returned and acknowledged, so reading the confirmations
// without the returns would report a success for a message that reached no queue.
// PHP: decoded in SConcur\Features\Amqp\Channel::waitForConfirms.
type WaitResult struct {
	Confirmations []Confirmation    `json:"cf" msgpack:"cf"`
	Returns       []ReturnedMessage `json:"rt" msgpack:"rt"`
}
