package amqp_feature

import (
	"sconcur/internal/features/amqp/payloads"
	"sconcur/internal/tasks"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
	"github.com/vmihailenco/msgpack/v5"
)

// handleExchangeDeclare declares an exchange, or — passively — only checks that it
// exists.
func (f *AmqpFeature) handleExchangeDeclare(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.ExchangeDeclareParams

	if !decodeParams(task, raw, &params, "exchange declare params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs)
	defer cancel()

	arguments := mapToTable(params.Arguments)

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		if params.Passive {
			return channel.ExchangeDeclarePassive(
				params.Name,
				params.Type,
				params.Durable,
				params.AutoDelete,
				params.Internal,
				params.NoWait,
				arguments,
			)
		}

		return channel.ExchangeDeclare(
			params.Name,
			params.Type,
			params.Durable,
			params.AutoDelete,
			params.Internal,
			params.NoWait,
			arguments,
		)
	})

	if err != nil {
		fail(task, entry, "exchange declare", err)

		return
	}

	respondDone(task, startTime)
}

// handleExchangeDelete deletes an exchange.
func (f *AmqpFeature) handleExchangeDelete(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.ExchangeDeleteParams

	if !decodeParams(task, raw, &params, "exchange delete params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs)
	defer cancel()

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		return channel.ExchangeDelete(params.Name, params.IfUnused, params.NoWait)
	})

	if err != nil {
		fail(task, entry, "exchange delete", err)

		return
	}

	respondDone(task, startTime)
}

// handleExchangeBinding binds one exchange to another, or removes that binding.
func (f *AmqpFeature) handleExchangeBinding(task *tasks.Task, raw msgpack.RawMessage, bind bool) {
	startTime := time.Now()

	var params payloads.ExchangeBindParams

	if !decodeParams(task, raw, &params, "exchange binding params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs)
	defer cancel()

	arguments := mapToTable(params.Arguments)

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		if bind {
			return channel.ExchangeBind(
				params.Destination,
				params.RoutingKey,
				params.Source,
				params.NoWait,
				arguments,
			)
		}

		return channel.ExchangeUnbind(
			params.Destination,
			params.RoutingKey,
			params.Source,
			params.NoWait,
			arguments,
		)
	})

	if err != nil {
		fail(task, entry, "exchange binding", err)

		return
	}

	respondDone(task, startTime)
}

// handleQueueDeclare declares a queue (passively when asked) and reports its name and how
// much is waiting in it.
func (f *AmqpFeature) handleQueueDeclare(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.QueueDeclareParams

	if !decodeParams(task, raw, &params, "queue declare params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs)
	defer cancel()

	arguments := mapToTable(params.Arguments)

	var queue amqp091.Queue

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		var declareError error

		if params.Passive {
			queue, declareError = channel.QueueDeclarePassive(
				params.Name,
				params.Durable,
				params.AutoDelete,
				params.Exclusive,
				params.NoWait,
				arguments,
			)

			return declareError
		}

		queue, declareError = channel.QueueDeclare(
			params.Name,
			params.Durable,
			params.AutoDelete,
			params.Exclusive,
			params.NoWait,
			arguments,
		)

		return declareError
	})

	if err != nil {
		fail(task, entry, "queue declare", err)

		return
	}

	respond(task, payloads.QueueDeclareResult{
		Name:          queue.Name,
		MessageCount:  queue.Messages,
		ConsumerCount: queue.Consumers,
	}, startTime)
}

// handleQueueDelete deletes a queue and reports how many messages went with it.
func (f *AmqpFeature) handleQueueDelete(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.QueueDeleteParams

	if !decodeParams(task, raw, &params, "queue delete params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs)
	defer cancel()

	var deleted int

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		var deleteError error

		deleted, deleteError = channel.QueueDelete(params.Name, params.IfUnused, params.IfEmpty, params.NoWait)

		return deleteError
	})

	if err != nil {
		fail(task, entry, "queue delete", err)

		return
	}

	respond(task, payloads.MessageCountResult{MessageCount: deleted}, startTime)
}

// handleQueueBinding binds a queue to an exchange, or removes that binding.
func (f *AmqpFeature) handleQueueBinding(task *tasks.Task, raw msgpack.RawMessage, bind bool) {
	startTime := time.Now()

	var params payloads.QueueBindParams

	if !decodeParams(task, raw, &params, "queue binding params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs)
	defer cancel()

	arguments := mapToTable(params.Arguments)

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		if bind {
			return channel.QueueBind(
				params.QueueName,
				params.RoutingKey,
				params.ExchangeName,
				params.NoWait,
				arguments,
			)
		}

		// queue.unbind has no no-wait form in AMQP 0-9-1, so the driver takes none.
		return channel.QueueUnbind(
			params.QueueName,
			params.RoutingKey,
			params.ExchangeName,
			arguments,
		)
	})

	if err != nil {
		fail(task, entry, "queue binding", err)

		return
	}

	respondDone(task, startTime)
}

// handleQueuePurge empties a queue and reports how many messages that was.
func (f *AmqpFeature) handleQueuePurge(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.QueuePurgeParams

	if !decodeParams(task, raw, &params, "queue purge params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs)
	defer cancel()

	var purged int

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		var purgeError error

		purged, purgeError = channel.QueuePurge(params.Name, params.NoWait)

		return purgeError
	})

	if err != nil {
		fail(task, entry, "queue purge", err)

		return
	}

	respond(task, payloads.MessageCountResult{MessageCount: purged}, startTime)
}
