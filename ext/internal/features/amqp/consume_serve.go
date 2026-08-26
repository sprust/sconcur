package amqp_feature

import (
	"context"
	"sconcur/internal/dto"
	"sconcur/internal/features/amqp/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/logger"
	"sconcur/internal/tasks"
	"sync"
	"sync/atomic"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
	"github.com/vmihailenco/msgpack/v5"
)

const (
	// defaultReopenDelay is how long a consumer the broker took away waits before it is
	// opened again. The same second the PHP consumer waited when it did the reopening
	// itself.
	defaultReopenDelay = time.Second
)

// consumeServeStates maps the flow key of a supervised consumer to its stream, so
// StopConsuming can end the consumers of one worker without cancelling the flow — the
// deliveries already handed to PHP have to stay settleable while their handlers finish.
var consumeServeStates sync.Map

// consumeServeState is the delivery stream of one supervised consumer: every queue it was
// given, every channel behind them, and the single task all deliveries are published
// under.
//
// It is the AMQP counterpart of the servers' accept stream, and it is self-pumping for the
// same reason: a worker doing tens of thousands of messages a second must not pay a next()
// crossing, a task and a goroutine per message. PHP drives it with Scheduler::serve(),
// exactly as it drives the three servers.
//
// The channels belong here rather than to a PHP object. That is what lets a worker stop
// without asking the runtime anything about the coroutine it is stopping: a drain cancels
// the consumers and leaves the channels open so the acknowledgements in flight still land,
// and the flow ending closes them.
type consumeServeState struct {
	ctx     context.Context
	message *dto.Message
	task    *tasks.Task
	handle  *connectionHandle
	params  payloads.ConsumeServeParams

	startTime time.Time

	// ended guards the last result of the stream: whatever ends it — a dead connection, a
	// consumer that could not be opened at all — says so once, and the deliveries that
	// were already on their way are dropped rather than published after it.
	ended atomic.Bool

	mutex sync.Mutex
	// stopping is set by StopConsuming: from then on a consumer whose deliveries stop is
	// one this side cancelled, not one the broker took away, so it is not opened again.
	stopping bool
	// live is the consumer of each slot that has one, by slot index — what StopConsuming
	// cancels.
	live map[int]liveConsumer
	// entries is every channel this stream owns, by id. Kept apart from live because the
	// two have different lifetimes: a cancelled consumer leaves its channel open so the
	// acknowledgements of the handlers still running land on it, and only the flow ending
	// closes it.
	entries map[string]*channelEntry
}

// liveConsumer is one open consumer: the channel it runs on and the tag it answers to.
type liveConsumer struct {
	entry       *channelEntry
	consumerTag string
}

// handleConsumeServe opens the consumers of one supervised worker and streams their
// deliveries under a single task.
func (f *AmqpFeature) handleConsumeServe(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()
	message := task.GetMessage()

	var params payloads.ConsumeServeParams

	if !decodeParams(task, raw, &params, "consume serve params") {
		return
	}

	handle := getConnections().find(params.ConnectionId)

	if handle == nil {
		task.AddResult(dto.NewErrorResult(message, networkErrorPayload("No connection available.")))

		return
	}

	if len(params.Queues) == 0 {
		task.AddResult(dto.NewErrorResult(message, errorPayload(scopeCommand, 0, "No queues to consume.")))

		return
	}

	state := &consumeServeState{
		ctx:       task.GetContext(),
		message:   message,
		task:      task,
		handle:    handle,
		params:    params,
		startTime: startTime,
		live:      make(map[int]liveConsumer),
		entries:   make(map[string]*channelEntry),
	}

	// Registered by flow key so a drain can cancel the consumers early without cancelling
	// the flow, the way the servers close their listener early. Cleaned in close().
	consumeServeStates.Store(message.FlowKey, state)

	// The flow ending is what closes the channels — a hard stopFlow with no drain before
	// it included.
	context.AfterFunc(task.GetContext(), state.close)

	startConsumerTelemetry()

	slot := 0

	for _, queue := range params.Queues {
		for index := 0; index < max(queue.Consumers, 1); index++ {
			go state.runSlot(slot, queue)

			slot++
		}
	}
}

// StopConsuming cancels every consumer of a worker's stream, leaving its channels open.
//
// Leaving them open is the whole point: the handlers PHP has already been given are still
// running, and their acknowledgements travel on those channels. Closing here would hand
// finished messages back to the broker for another worker to do again.
func StopConsuming(flowKey string) {
	value, ok := consumeServeStates.Load(flowKey)

	if !ok {
		return
	}

	if state, ok := value.(*consumeServeState); ok {
		state.stopConsuming()
	}
}

// stopConsuming cancels the live consumers, all at once: each basic.cancel waits on the
// broker, and a worker with a dozen of them would otherwise drain a dozen waits deep.
func (s *consumeServeState) stopConsuming() {
	s.mutex.Lock()

	s.stopping = true

	consumers := make([]liveConsumer, 0, len(s.live))

	for _, consumer := range s.live {
		consumers = append(consumers, consumer)
	}

	s.mutex.Unlock()

	var cancelling sync.WaitGroup

	for _, consumer := range consumers {
		cancelling.Add(1)

		go func(consumer liveConsumer) {
			defer cancelling.Done()

			consumer.entry.cancelConsumer(consumer.consumerTag)
		}(consumer)
	}

	cancelling.Wait()
}

// close releases everything the stream owns. It runs when the flow ends — the serve loop
// stops the flow on its way out, after the last handler has finished.
func (s *consumeServeState) close() {
	consumeServeStates.Delete(s.message.FlowKey)

	s.mutex.Lock()

	s.stopping = true

	entries := make([]*channelEntry, 0, len(s.entries))

	for _, entry := range s.entries {
		entries = append(entries, entry)
	}

	s.live = make(map[int]liveConsumer)
	s.entries = make(map[string]*channelEntry)

	s.mutex.Unlock()

	var closing sync.WaitGroup

	for _, entry := range entries {
		closing.Add(1)

		go func(entry *channelEntry) {
			defer closing.Done()

			getChannels().close(entry.id)
		}(entry)
	}

	closing.Wait()
}

// runSlot keeps one consumer on one queue alive for as long as this worker consumes.
//
// A consumer is taken away by more than the queue being deleted: a channel dies over an
// unrelated 404, a cluster node fails over. That leaves the queue unread while its
// neighbours carry on, so the slot opens a fresh channel and a fresh consumer a moment
// later, for as long as reopening can work.
//
// What ends the whole stream is the connection going away. It is shared by every slot and
// cannot be reopened from here — the pooled connection behind it is gone with its
// channels — so the stream fails, PHP's serve loop raises, the worker exits, and its
// master starts a fresh process with a fresh connection.
func (s *consumeServeState) runSlot(slot int, queue payloads.ConsumeServeQueue) {
	first := true

	for {
		entry, consumerTag, deliveries, err := s.openConsumer(slot, queue)

		if err != nil {
			if s.finished() {
				return
			}

			// The first open is the worker's start-up: a queue that is not there, or
			// credentials that cannot consume it, must be heard about now rather than
			// retried silently for the life of the worker.
			if first {
				s.fail("consumer "+queue.Name+" could not be opened", entry, err)

				return
			}

			if s.connectionGone() {
				s.failConnection(queue.Name)

				return
			}

			logger.Write("amqp: consumer " + queue.Name + " could not be reopened: " + err.Error() + "\n")

			if !s.pause() {
				return
			}

			continue
		}

		first = false

		lost := s.pump(entry, deliveries)

		s.forget(slot)

		if !lost {
			// Asked to stop, or the flow is going away. The channel stays open either
			// way: the handlers still holding a delivery of it answer the broker on it,
			// and close() is what releases it once the flow ends.
			return
		}

		s.release(entry)

		if s.connectionGone() {
			s.failConnection(queue.Name)

			return
		}

		logger.Write("amqp: consumer " + queue.Name + " (" + consumerTag + ") was taken away; reopening\n")

		if !s.pause() {
			return
		}
	}
}

// openConsumer gives the slot a channel of its own and registers its consumer on it.
//
// A channel is never shared between slots: the commands of one are serialized on the
// broker, so sharing would turn N consumers into a queue of N — and a reopened consumer
// gets a fresh one, since whatever ended the last one usually took the channel with it.
func (s *consumeServeState) openConsumer(
	slot int,
	queue payloads.ConsumeServeQueue,
) (*channelEntry, string, <-chan amqp091.Delivery, error) {
	ctx, cancel := context.WithTimeout(s.ctx, msOrDefault(s.params.TimeoutMs, defaultRpcTimeout))
	defer cancel()

	prefetchCount := queue.PrefetchCount

	if prefetchCount <= 0 {
		prefetchCount = s.params.PrefetchCount
	}

	entry, err := getChannels().openBounded(ctx, s.handle, payloads.ChannelOpenParams{
		ConnectionId:  s.params.ConnectionId,
		PrefetchCount: max(prefetchCount, 0),
	})

	if err != nil {
		return nil, "", nil, err
	}

	s.own(entry)

	consumerTag := nextConsumerTag()

	deliveries, err := entry.consume(ctx, consumerTag, payloads.ConsumeParams{
		ChannelId: entry.id,
		QueueName: queue.Name,
		AutoAck:   s.params.AutoAck,
	})

	if err != nil {
		s.release(entry)

		return entry, "", nil, err
	}

	entry.registerConsumer(consumerTag, "")

	consumerStatsInstance.consumerOpened(entry.id, consumerTag)

	// Recorded only once it is fully open: a drain that arrives in the middle cancels
	// what exists, and the slot itself sees the stop on its next turn.
	if !s.remember(slot, liveConsumer{entry: entry, consumerTag: consumerTag}) {
		s.release(entry)

		return entry, consumerTag, nil, context.Canceled
	}

	return entry, consumerTag, deliveries, nil
}

// pump publishes every delivery of one consumer, and answers whether the consumer was
// taken away — as opposed to this side ending it.
func (s *consumeServeState) pump(entry *channelEntry, deliveries <-chan amqp091.Delivery) bool {
	for {
		select {
		case delivery, ok := <-deliveries:
			if !ok {
				return !s.finished()
			}

			s.publish(entry, delivery)
		case <-s.ctx.Done():
			return false
		}
	}
}

// publish hands one delivery to PHP as the next result of the stream.
func (s *consumeServeState) publish(entry *channelEntry, delivery amqp091.Delivery) {
	if s.ended.Load() {
		return
	}

	serialized, err := msgpack.Marshal(deliveryToPayload(delivery, entry.id))

	if err != nil {
		s.end(dto.NewErrorResult(s.message, errFactory.ByErr("marshal delivery", err)))

		return
	}

	// Counted where the delivery leaves for PHP: the acknowledgement that settles it
	// arrives as an ordinary command, so the pair needs nothing extra on the wire.
	consumerStatsInstance.deliveryDispatched(entry.id, delivery.DeliveryTag, s.params.AutoAck)

	s.task.AddResult(dto.NewSuccessResultWithNext(
		s.message,
		string(serialized),
		helpers.CalcExecutionMs(s.startTime),
	))
}

// fail ends the stream with the failure of one consumer, scoped the way every other
// command of this feature is.
func (s *consumeServeState) fail(what string, entry *channelEntry, err error) {
	scope, code, text := classify(entry, what, err)

	s.end(dto.NewErrorResult(s.message, errorPayload(scope, code, text)))
}

// failConnection ends the stream because the connection every consumer shares is gone.
func (s *consumeServeState) failConnection(queueName string) {
	s.end(dto.NewErrorResult(s.message, networkErrorPayload(
		"Consumer "+queueName+" ended: no connection available.",
	)))
}

// end publishes the last result of the stream, once. Whatever else was still on its way
// is dropped: PHP has left the loop by then.
func (s *consumeServeState) end(result *dto.Result) {
	if s.ended.Swap(true) {
		return
	}

	s.task.AddResult(result)
}

// pause waits out the reopen delay, answering false when the stream ended while it waited.
func (s *consumeServeState) pause() bool {
	timer := time.NewTimer(msOrDefault(s.params.ReopenDelayMs, defaultReopenDelay))
	defer timer.Stop()

	select {
	case <-timer.C:
		return !s.finished()
	case <-s.ctx.Done():
		return false
	}
}

// finished answers whether there is any point going on: the flow is gone, or a stop was
// asked for.
func (s *consumeServeState) finished() bool {
	if s.ctx.Err() != nil {
		return true
	}

	s.mutex.Lock()
	defer s.mutex.Unlock()

	return s.stopping
}

// connectionGone answers whether the connection every slot shares is beyond reopening —
// the socket died, or PHP handed the connection back, which closes the channels of every
// slot on it just the same.
func (s *consumeServeState) connectionGone() bool {
	if s.handle.isReleased() {
		return true
	}

	return s.handle.pooled == nil || s.handle.pooled.connection == nil || s.handle.pooled.connection.IsClosed()
}

// own records a channel this stream opened, so close() releases it whatever the slot that
// opened it went on to do.
func (s *consumeServeState) own(entry *channelEntry) {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	s.entries[entry.id] = entry
}

// release closes a channel this stream is done with — a consumer that was taken away gets
// a fresh one, and the old one has nothing left on it.
func (s *consumeServeState) release(entry *channelEntry) {
	s.mutex.Lock()

	delete(s.entries, entry.id)

	s.mutex.Unlock()

	getChannels().close(entry.id)
}

// remember records a slot's consumer, answering false when a stop got there first — the
// caller then gives the channel back instead of leaving one nobody will cancel.
func (s *consumeServeState) remember(slot int, consumer liveConsumer) bool {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	if s.stopping {
		return false
	}

	s.live[slot] = consumer

	return true
}

func (s *consumeServeState) forget(slot int) {
	s.mutex.Lock()
	defer s.mutex.Unlock()

	delete(s.live, slot)
}
