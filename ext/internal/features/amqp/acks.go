package amqp_feature

import (
	"sconcur/internal/features/amqp/payloads"
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

	respondDone(task, startTime)
}

// handleRecover asks the broker to deliver the unacknowledged messages again.
func (f *AmqpFeature) handleRecover(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.RecoverParams

	if !decodeParams(task, raw, &params, "recover params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs)
	defer cancel()

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		return channel.Recover(params.Requeue)
	})

	if err != nil {
		fail(task, entry, "recover", err)

		return
	}

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

	entry.forgetConsumer(params.ConsumerTag)

	respondDone(task, startTime)
}
