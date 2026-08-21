package amqp_feature

import (
	"context"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/features/amqp/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/states"
	"sconcur/internal/tasks"
	"strconv"
	"sync"
	"sync/atomic"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
	"github.com/vmihailenco/msgpack/v5"
)

var _ contracts.StateContract = (*consumeState)(nil)

// consumerCounter backs the generated consumer tags. The tag is generated here rather
// than left to the driver because the driver keeps the one it generates to itself, and
// PHP needs it to cancel the consumer and to route deliveries.
var consumerCounter atomic.Int64

// consumeState streams one consumer to PHP: the first Next returns the consumer tag, and
// every following one returns the next delivery. The stream ends when the consumer is
// cancelled, the channel or connection dies, the flow stops, or the read timeout expires.
type consumeState struct {
	mutex       sync.Mutex
	ctx         context.Context
	message     *dto.Message
	entry       *channelEntry
	consumerTag string
	readTimeout time.Duration
	startTime   time.Time
	metaSent    bool
	deliveries  <-chan amqp091.Delivery
	cleanup     func()
}

func (s *consumeState) Next() *dto.Result {
	s.mutex.Lock()

	if !s.metaSent {
		s.metaSent = true

		s.mutex.Unlock()

		serialized, err := msgpack.Marshal(payloads.ConsumerMeta{ConsumerTag: s.consumerTag})

		if err != nil {
			return dto.NewErrorResult(s.message, errFactory.ByErr("marshal consumer meta", err))
		}

		return dto.NewSuccessResultWithNext(s.message, string(serialized), helpers.CalcExecutionMs(s.startTime))
	}

	s.mutex.Unlock()

	// Prefer a delivery that is already buffered over shutdown. With both a delivery and
	// a cancelled context ready, a plain select could pick the cancellation at random and
	// drop a message that had already arrived.
	select {
	case delivery, ok := <-s.deliveries:
		return s.resultFromDelivery(delivery, ok)
	default:
	}

	var deadline <-chan time.Time

	if s.readTimeout > 0 {
		timer := time.NewTimer(s.readTimeout)
		defer timer.Stop()

		deadline = timer.C
	}

	select {
	case delivery, ok := <-s.deliveries:
		return s.resultFromDelivery(delivery, ok)
	case <-deadline:
		// ext-amqp ends the consume loop with this exact failure when read_timeout
		// passes with no delivery.
		return dto.NewErrorResult(s.message, errFactory.ByText("consumer timeout exceed"))
	case <-s.ctx.Done():
		return dto.NewSuccessResult(s.message, "", helpers.CalcExecutionMs(s.startTime))
	}
}

// resultFromDelivery turns a receive into a Next() result: one delivery with more to come,
// or the end of the stream once the driver closed the deliveries channel.
//
// The driver closes it both when the flow is going away and when the consumer was taken
// from us — the queue was deleted, the channel died, the broker cancelled the consumer.
// The first is the normal end of a consume loop; the second is a failure, and ext-amqp
// raises there, so the stream ends with an error rather than a quiet return.
func (s *consumeState) resultFromDelivery(delivery amqp091.Delivery, ok bool) *dto.Result {
	if !ok {
		if s.ctx.Err() != nil {
			return dto.NewSuccessResult(s.message, "", helpers.CalcExecutionMs(s.startTime))
		}

		return dto.NewErrorResult(s.message, errorPayload(
			scopeCommand,
			0,
			"Consumer "+s.consumerTag+" was cancelled by the broker.",
		))
	}

	serialized, err := msgpack.Marshal(deliveryToPayload(delivery, s.entry.id))

	if err != nil {
		return dto.NewErrorResult(s.message, errFactory.ByErr("marshal delivery", err))
	}

	return dto.NewSuccessResultWithNext(s.message, string(serialized), helpers.CalcExecutionMs(s.startTime))
}

// Close cancels the consumer, leaving the channel open — a channel outlives its
// consumers. It runs on a fresh context: by the time cleanup runs, the task context is
// already cancelled.
func (s *consumeState) Close() {
	s.cleanup()
}

// handleConsume registers a consumer and streams its deliveries. The consumer is not tied
// to the task context — the stream state owns it, and Close() cancels it when PHP stops
// reading or the flow ends.
func (f *AmqpFeature) handleConsume(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()
	message := task.GetMessage()

	var params payloads.ConsumeParams

	if !decodeParams(task, raw, &params, "consume params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	consumerTag := params.ConsumerTag

	if consumerTag == "" {
		consumerTag = nextConsumerTag()
	}

	consumerContext, cancelConsumer := context.WithCancel(context.Background())

	registerContext, cancelRegister := commandContext(task, params.TimeoutMs)
	defer cancelRegister()

	deliveries, err := entry.consume(registerContext, consumerContext, consumerTag, params)

	if err != nil {
		cancelConsumer()

		fail(task, entry, "consume", err)

		return
	}

	var cleanupOnce sync.Once

	cleanup := func() {
		cleanupOnce.Do(func() {
			// Cancelling the consumer context makes the driver send the basic.cancel;
			// forgetConsumer drops it from the channel's registry so the idle sweeper
			// sees the channel as idle again.
			cancelConsumer()

			entry.forgetConsumer(consumerTag)
		})
	}

	entry.registerConsumer(consumerTag, cancelConsumer)

	state := &consumeState{
		ctx:         task.GetContext(),
		message:     message,
		entry:       entry,
		consumerTag: consumerTag,
		readTimeout: time.Duration(max(params.ReadTimeoutMs, 0)) * time.Millisecond,
		startTime:   startTime,
		deliveries:  deliveries,
		cleanup:     cleanup,
	}

	// The first Next returns the consumer tag with HasNext set, so the state stays alive
	// and the deliveries can be pulled through next(). states.Start hooks Close on flow
	// stop.
	result, err := states.Get().Start(task.GetContext(), message.TaskKey, state)

	if err != nil {
		cleanup()

		fail(task, entry, "consume", err)

		return
	}

	task.AddResult(result)
}

// consume registers the consumer on the driver channel, bounded by the command context
// like every other method — the consumer itself then lives on the consumer context.
func (e *channelEntry) consume(
	ctx context.Context,
	consumerContext context.Context,
	consumerTag string,
	params payloads.ConsumeParams,
) (<-chan amqp091.Delivery, error) {
	arguments := mapToTable(params.Arguments)

	var deliveries <-chan amqp091.Delivery

	err := e.do(ctx, func(channel *amqp091.Channel) error {
		var consumeError error

		deliveries, consumeError = channel.ConsumeWithContext(
			consumerContext,
			params.QueueName,
			consumerTag,
			params.AutoAck,
			params.Exclusive,
			params.NoLocal,
			params.NoWait,
			arguments,
		)

		return consumeError
	})

	if err != nil {
		return nil, err
	}

	return deliveries, nil
}

func nextConsumerTag() string {
	return "sconcur-ctag-" + strconv.FormatInt(consumerCounter.Add(1), 10)
}
