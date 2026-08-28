package amqp_feature

import (
	"sconcur/internal/features/amqp/payloads"
	"sconcur/internal/states"
	"sconcur/internal/tasks"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
	"github.com/vmihailenco/msgpack/v5"
)

// handleAck acknowledges one delivery.
//
// The telemetry is recorded inside the closure, so a settle that never reached the broker
// is never counted — and, being the last thing the closure does, only after the driver
// took it. consumerStats holds no lock of the registries, so recording it while the
// channel's own lock is held cannot deadlock.
func (f *AmqpFeature) handleAck(task *tasks.Task, raw msgpack.RawMessage) {
	onChannel(
		task,
		raw,
		"ack",
		defaultRpcTimeout,
		func(channel *amqp091.Channel, params payloads.AckParams) (any, error) {
			if err := channel.Ack(params.DeliveryTag, params.Multiple); err != nil {
				return nil, err
			}

			consumerStatsInstance.deliverySettled(params.ChannelId, params.DeliveryTag, params.Multiple, true)

			return nil, nil
		},
	)
}

// handleNack refuses one delivery, optionally putting it back into the queue.
func (f *AmqpFeature) handleNack(task *tasks.Task, raw msgpack.RawMessage) {
	onChannel(
		task,
		raw,
		"nack",
		defaultRpcTimeout,
		func(channel *amqp091.Channel, params payloads.NackParams) (any, error) {
			if err := channel.Nack(params.DeliveryTag, params.Multiple, params.Requeue); err != nil {
				return nil, err
			}

			consumerStatsInstance.deliverySettled(params.ChannelId, params.DeliveryTag, params.Multiple, false)

			return nil, nil
		},
	)
}

// handleReject refuses exactly one delivery.
func (f *AmqpFeature) handleReject(task *tasks.Task, raw msgpack.RawMessage) {
	onChannel(
		task,
		raw,
		"reject",
		defaultRpcTimeout,
		func(channel *amqp091.Channel, params payloads.RejectParams) (any, error) {
			if err := channel.Reject(params.DeliveryTag, params.Requeue); err != nil {
				return nil, err
			}

			consumerStatsInstance.deliverySettled(params.ChannelId, params.DeliveryTag, false, false)

			return nil, nil
		},
	)
}

// handleCancel cancels a consumer. The channel stays open: it outlives its consumers.
func (f *AmqpFeature) handleCancel(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	entry, params, ok := resolveChannel[payloads.CancelParams](task, raw, "cancel")

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs, defaultRpcTimeout)
	defer cancel()

	// The stream the consumer was read through goes with it. Outside a coroutine PHP
	// releases the flow that owned it; inside one the flow lives as long as the coroutine
	// does, and a worker cancelling consumer after consumer would otherwise pile up a
	// state, a delivery buffer and a goroutine for every one of them.
	//
	// Claimed before the basic.cancel rather than after it, the way cancelConsumer and
	// cancelDetached do it — and before do() rather than inside it, so a cancel that never
	// gets its turn on the channel still gives the stream up. PHP swallows a failed cancel,
	// so a tag left behind by an error return would keep its stream and its state for good
	// — and keep the channel looking busy, which is the one thing the idle sweeper goes by.
	// The broker ignores a cancel for a tag it does not know, so the reverse mistake costs
	// nothing.
	if taskKey, exists := entry.forgetConsumer(params.ConsumerTag); exists && taskKey != "" {
		states.Get().DeleteState(taskKey)
	}

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		return channel.Cancel(params.ConsumerTag, params.NoWait)
	})

	if err != nil {
		fail(task, entry, "cancel", err)

		return
	}

	respondDone(task, startTime)
}
