package types

// AmqpCommand selects a sub-operation of the amqp feature, carried in the payload
// envelope's cm field under the single MethodAmqp — mirrors WsClientCommand, with one
// case per AMQP 0-9-1 method the PHP feature exposes.
// PHP: SConcur\Features\Amqp\AmqpCommandEnum.
type AmqpCommand string

const (
	// AmqpConnect opens (or takes from the pool) a connection to the broker.
	AmqpConnect AmqpCommand = "con"
	// AmqpDisconnect releases a connection handle.
	AmqpDisconnect AmqpCommand = "dis"
	// AmqpChannelOpen opens a channel on a connection.
	AmqpChannelOpen AmqpCommand = "cho"
	// AmqpChannelClose closes a channel.
	AmqpChannelClose AmqpCommand = "chc"
	// AmqpQos applies the prefetch settings of a channel.
	AmqpQos AmqpCommand = "qos"
	// AmqpExchangeDeclare declares an exchange (passively when asked).
	AmqpExchangeDeclare AmqpCommand = "exd"
	// AmqpExchangeDelete deletes an exchange.
	AmqpExchangeDelete AmqpCommand = "exx"
	// AmqpExchangeBind binds one exchange to another.
	AmqpExchangeBind AmqpCommand = "exb"
	// AmqpExchangeUnbind removes a binding between two exchanges.
	AmqpExchangeUnbind AmqpCommand = "exu"
	// AmqpQueueDeclare declares a queue (passively when asked).
	AmqpQueueDeclare AmqpCommand = "qud"
	// AmqpQueueDelete deletes a queue.
	AmqpQueueDelete AmqpCommand = "qux"
	// AmqpQueueBind binds a queue to an exchange.
	AmqpQueueBind AmqpCommand = "qub"
	// AmqpQueueUnbind removes a binding between a queue and an exchange.
	AmqpQueueUnbind AmqpCommand = "quu"
	// AmqpQueuePurge removes every message from a queue.
	AmqpQueuePurge AmqpCommand = "qup"
	// AmqpPublish publishes one message.
	AmqpPublish AmqpCommand = "pub"
	// AmqpGet pulls one message, or nothing when the queue is empty.
	AmqpGet AmqpCommand = "get"
	// AmqpConsume opens a delivery stream: the first result carries the consumer tag,
	// every following one a delivery.
	AmqpConsume AmqpCommand = "csm"
	// AmqpCancel cancels a consumer.
	AmqpCancel AmqpCommand = "cnl"
	// AmqpAck acknowledges a delivery.
	AmqpAck AmqpCommand = "ack"
	// AmqpNack refuses a delivery, optionally requeueing it.
	AmqpNack AmqpCommand = "nck"
	// AmqpReject refuses exactly one delivery.
	AmqpReject AmqpCommand = "rej"
	// AmqpConfirmSelect puts the channel into publisher-confirm mode.
	AmqpConfirmSelect AmqpCommand = "cfs"
	// AmqpConfirmWait waits for the outstanding publisher confirms.
	AmqpConfirmWait AmqpCommand = "cfw"
	// AmqpUsedChannels counts the channels a connection handle holds open.
	AmqpUsedChannels AmqpCommand = "usc"
)
