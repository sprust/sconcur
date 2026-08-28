package amqp_feature

import (
	"sconcur/internal/features/amqp/payloads"
	"sconcur/internal/tasks"

	amqp091 "github.com/rabbitmq/amqp091-go"
	"github.com/vmihailenco/msgpack/v5"
)

// handleExchangeDeclare declares an exchange, or — passively — only checks that it
// exists.
func (f *AmqpFeature) handleExchangeDeclare(task *tasks.Task, raw msgpack.RawMessage) {
	onChannel(
		task,
		raw,
		"exchange declare",
		defaultRpcTimeout,
		func(channel *amqp091.Channel, params payloads.ExchangeDeclareParams) (any, error) {
			arguments := mapToTable(params.Arguments)

			if params.Passive {
				return nil, channel.ExchangeDeclarePassive(
					params.Name,
					params.Type,
					params.Durable,
					params.AutoDelete,
					params.Internal,
					params.NoWait,
					arguments,
				)
			}

			return nil, channel.ExchangeDeclare(
				params.Name,
				params.Type,
				params.Durable,
				params.AutoDelete,
				params.Internal,
				params.NoWait,
				arguments,
			)
		},
	)
}

// handleExchangeDelete deletes an exchange.
func (f *AmqpFeature) handleExchangeDelete(task *tasks.Task, raw msgpack.RawMessage) {
	onChannel(
		task,
		raw,
		"exchange delete",
		defaultRpcTimeout,
		func(channel *amqp091.Channel, params payloads.ExchangeDeleteParams) (any, error) {
			return nil, channel.ExchangeDelete(params.Name, params.IfUnused, params.NoWait)
		},
	)
}

// handleExchangeBinding binds one exchange to another, or removes that binding.
func (f *AmqpFeature) handleExchangeBinding(task *tasks.Task, raw msgpack.RawMessage, bind bool) {
	onChannel(
		task,
		raw,
		"exchange binding",
		defaultRpcTimeout,
		func(channel *amqp091.Channel, params payloads.ExchangeBindParams) (any, error) {
			arguments := mapToTable(params.Arguments)

			if bind {
				return nil, channel.ExchangeBind(
					params.Destination,
					params.RoutingKey,
					params.Source,
					params.NoWait,
					arguments,
				)
			}

			return nil, channel.ExchangeUnbind(
				params.Destination,
				params.RoutingKey,
				params.Source,
				params.NoWait,
				arguments,
			)
		},
	)
}

// handleQueueDeclare declares a queue (passively when asked) and reports its name and how
// much is waiting in it.
func (f *AmqpFeature) handleQueueDeclare(task *tasks.Task, raw msgpack.RawMessage) {
	onChannel(
		task,
		raw,
		"queue declare",
		defaultRpcTimeout,
		func(channel *amqp091.Channel, params payloads.QueueDeclareParams) (any, error) {
			arguments := mapToTable(params.Arguments)

			declare := channel.QueueDeclare

			if params.Passive {
				declare = channel.QueueDeclarePassive
			}

			queue, err := declare(
				params.Name,
				params.Durable,
				params.AutoDelete,
				params.Exclusive,
				params.NoWait,
				arguments,
			)

			if err != nil {
				return nil, err
			}

			return payloads.QueueDeclareResult{
				Name:          queue.Name,
				MessageCount:  queue.Messages,
				ConsumerCount: queue.Consumers,
			}, nil
		},
	)
}

// handleQueueDelete deletes a queue and reports how many messages went with it.
func (f *AmqpFeature) handleQueueDelete(task *tasks.Task, raw msgpack.RawMessage) {
	onChannel(
		task,
		raw,
		"queue delete",
		defaultRpcTimeout,
		func(channel *amqp091.Channel, params payloads.QueueDeleteParams) (any, error) {
			deleted, err := channel.QueueDelete(params.Name, params.IfUnused, params.IfEmpty, params.NoWait)

			if err != nil {
				return nil, err
			}

			return payloads.MessageCountResult{MessageCount: deleted}, nil
		},
	)
}

// handleQueueBinding binds a queue to an exchange, or removes that binding.
func (f *AmqpFeature) handleQueueBinding(task *tasks.Task, raw msgpack.RawMessage, bind bool) {
	onChannel(
		task,
		raw,
		"queue binding",
		defaultRpcTimeout,
		func(channel *amqp091.Channel, params payloads.QueueBindParams) (any, error) {
			arguments := mapToTable(params.Arguments)

			if bind {
				return nil, channel.QueueBind(
					params.QueueName,
					params.RoutingKey,
					params.ExchangeName,
					params.NoWait,
					arguments,
				)
			}

			// queue.unbind has no no-wait form in AMQP 0-9-1, so the driver takes none.
			return nil, channel.QueueUnbind(
				params.QueueName,
				params.RoutingKey,
				params.ExchangeName,
				arguments,
			)
		},
	)
}

// handleQueuePurge empties a queue and reports how many messages that was.
func (f *AmqpFeature) handleQueuePurge(task *tasks.Task, raw msgpack.RawMessage) {
	onChannel(
		task,
		raw,
		"queue purge",
		defaultRpcTimeout,
		func(channel *amqp091.Channel, params payloads.QueuePurgeParams) (any, error) {
			purged, err := channel.QueuePurge(params.Name, params.NoWait)

			if err != nil {
				return nil, err
			}

			return payloads.MessageCountResult{MessageCount: purged}, nil
		},
	)
}
