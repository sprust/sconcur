package amqp_feature

import (
	"sconcur/internal/features/amqp/payloads"
	"sconcur/internal/states"
	"sconcur/internal/tasks"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
	"github.com/vmihailenco/msgpack/v5"
)

// handleAck acknowledges one delivery, or every delivery up to and including its tag.
func (f *AmqpFeature) handleAck(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.AckParams

	if !decodeParams(task, raw, &params, "ack params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs)
	defer cancel()

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		return channel.Ack(params.DeliveryTag, params.Multiple)
	})

	if err != nil {
		fail(task, entry, "ack", err)

		return
	}

	consumerStatsInstance.deliverySettled(params.ChannelId, params.DeliveryTag, params.Multiple, true)

	respondDone(task, startTime)
}

// handleNack refuses one or more deliveries, optionally putting them back into the queue.
func (f *AmqpFeature) handleNack(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.NackParams

	if !decodeParams(task, raw, &params, "nack params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs)
	defer cancel()

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		return channel.Nack(params.DeliveryTag, params.Multiple, params.Requeue)
	})

	if err != nil {
		fail(task, entry, "nack", err)

		return
	}

	consumerStatsInstance.deliverySettled(params.ChannelId, params.DeliveryTag, params.Multiple, false)

	respondDone(task, startTime)
}

// handleReject refuses exactly one delivery.
func (f *AmqpFeature) handleReject(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.RejectParams

	if !decodeParams(task, raw, &params, "reject params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs)
	defer cancel()

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		return channel.Reject(params.DeliveryTag, params.Requeue)
	})

	if err != nil {
		fail(task, entry, "reject", err)

		return
	}

	consumerStatsInstance.deliverySettled(params.ChannelId, params.DeliveryTag, false, false)

	respondDone(task, startTime)
}

// handleCancel cancels a consumer. The channel stays open: it outlives its consumers.
func (f *AmqpFeature) handleCancel(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.CancelParams

	if !decodeParams(task, raw, &params, "cancel params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs)
	defer cancel()

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		return channel.Cancel(params.ConsumerTag, params.NoWait)
	})

	if err != nil {
		fail(task, entry, "cancel", err)

		return
	}

	// The stream the consumer was read through goes with it. Outside a coroutine PHP
	// releases the flow that owned it; inside one the flow lives as long as the coroutine
	// does, and a worker cancelling consumer after consumer would otherwise pile up a
	// state, a delivery buffer and a goroutine for every one of them.
	if taskKey, exists := entry.forgetConsumer(params.ConsumerTag); exists && taskKey != "" {
		states.Get().DeleteState(taskKey)
	}

	respondDone(task, startTime)
}
