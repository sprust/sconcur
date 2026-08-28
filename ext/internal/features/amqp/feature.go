package amqp_feature

import (
	"context"
	"errors"
	"fmt"
	"net"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/amqp/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/logger"
	"sconcur/internal/states"
	"sconcur/internal/tasks"
	"sconcur/internal/types"
	"strconv"
	"sync"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
	"github.com/vmihailenco/msgpack/v5"
)

var _ contracts.FeatureContract = (*AmqpFeature)(nil)

var once sync.Once
var instance *AmqpFeature

var errFactory = errs.NewErrorsFactory("amqp")

// The scope markers a failure is prefixed with, so the PHP side knows which exception to
// raise and what the failure did to the resource. The payload of a failed task is
// "<scope>:<code>: <text>", where the code is the AMQP reply code (0 when the broker named
// none) — ext-amqp puts that code into the exception, and application code branches on it.
const (
	// scopeNetwork: the broker is unreachable or the connection died. PHP raises
	// ConnectionException, whichever exception the caller asked for.
	scopeNetwork = "net"
	// scopeChannel: the broker closed the channel over this failure. PHP raises the
	// caller's exception and marks its Channel closed.
	scopeChannel = "chn"
	// scopeChannelGone: the channel was already gone when this command reached it, and the
	// broker said why. It is not scopeChannel, because that one means "the broker refused
	// this method" and PHP raises the exception of the call — a confirm wait that finds the
	// channel closed by an earlier publish's 404 is not a confirm timeout. Here the failure
	// belongs to the channel, so PHP always raises ChannelException, carrying the reply code
	// that actually closed it.
	scopeChannelGone = "chg"
	// scopeCommand: the command failed with the channel left usable.
	scopeCommand = "err"

	// connectionErrorCode is the lowest AMQP reply code that closes the connection
	// rather than the channel (the 5xx class of AMQP 0-9-1).
	connectionErrorCode = 500
)

// AmqpFeature runs the AMQP 0-9-1 methods the PHP feature exposes: it owns the pooled
// connections, the channel registry and the delivery streams the consumers feed.
// Singleton.
type AmqpFeature struct{}

func Get() *AmqpFeature {
	once.Do(func() {
		instance = &AmqpFeature{}
	})

	return instance
}

func (f *AmqpFeature) Handle(task *tasks.Task) {
	message := task.GetMessage()

	var envelope payloads.Envelope

	if err := msgpack.Unmarshal(message.Payload, &envelope); err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse envelope", err)))

		return
	}

	// A detached push carries no flow and awaits no result: it is the last word of a PHP
	// object that was destroyed with its coroutine. Only the two commands that release a
	// resource are accepted, and they run off the PHP thread — the detached path executes
	// the handler inline, and closing a channel waits on the broker.
	if message.FlowKey == "" {
		f.handleDetached(envelope)

		return
	}

	switch envelope.Command {
	case types.AmqpConnect:
		f.handleConnect(task, envelope.Params)
	case types.AmqpDisconnect:
		f.handleDisconnect(task, envelope.Params)
	case types.AmqpUsedChannels:
		f.handleUsedChannels(task, envelope.Params)
	case types.AmqpChannelOpen:
		f.handleChannelOpen(task, envelope.Params)
	case types.AmqpChannelClose:
		f.handleChannelClose(task, envelope.Params)
	case types.AmqpQos:
		f.handleQos(task, envelope.Params)
	case types.AmqpExchangeDeclare:
		f.handleExchangeDeclare(task, envelope.Params)
	case types.AmqpExchangeDelete:
		f.handleExchangeDelete(task, envelope.Params)
	case types.AmqpExchangeBind, types.AmqpExchangeUnbind:
		f.handleExchangeBinding(task, envelope.Params, envelope.Command == types.AmqpExchangeBind)
	case types.AmqpQueueDeclare:
		f.handleQueueDeclare(task, envelope.Params)
	case types.AmqpQueueDelete:
		f.handleQueueDelete(task, envelope.Params)
	case types.AmqpQueueBind, types.AmqpQueueUnbind:
		f.handleQueueBinding(task, envelope.Params, envelope.Command == types.AmqpQueueBind)
	case types.AmqpQueuePurge:
		f.handleQueuePurge(task, envelope.Params)
	case types.AmqpPublish:
		f.handlePublish(task, envelope.Params)
	case types.AmqpGet:
		f.handleGet(task, envelope.Params)
	case types.AmqpConsume:
		f.handleConsume(task, envelope.Params)
	case types.AmqpConsumeServe:
		f.handleConsumeServe(task, envelope.Params)
	case types.AmqpCancel:
		f.handleCancel(task, envelope.Params)
	case types.AmqpAck:
		f.handleAck(task, envelope.Params)
	case types.AmqpNack:
		f.handleNack(task, envelope.Params)
	case types.AmqpReject:
		f.handleReject(task, envelope.Params)
	case types.AmqpConfirmSelect:
		f.handleConfirmSelect(task, envelope.Params)
	case types.AmqpConfirmWait:
		f.handleConfirmWait(task, envelope.Params)
	default:
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("unknown command")))
	}
}

// handleDetached releases a resource whose owner is gone. Nothing is answered: the caller
// is a destructor that cannot wait, and by the time this runs its coroutine may not exist.
func (f *AmqpFeature) handleDetached(envelope payloads.Envelope) {
	switch envelope.Command {
	case types.AmqpChannelClose:
		var params payloads.ChannelParams

		if err := msgpack.Unmarshal(envelope.Params, &params); err != nil {
			return
		}

		go getChannels().close(params.ChannelId)
	case types.AmqpDisconnect:
		var params payloads.ConnectionParams

		if err := msgpack.Unmarshal(envelope.Params, &params); err != nil {
			return
		}

		go getConnections().release(params.ConnectionId)
	case types.AmqpCancel:
		// A consume loop that was unwound cancels its consumer on the way out. The
		// coroutine's flow is already gone, so there is nothing to answer on — the
		// method goes to the broker and the stream is dropped, with nobody waiting.
		var params payloads.CancelParams

		if err := msgpack.Unmarshal(envelope.Params, &params); err != nil {
			return
		}

		go cancelDetached(params)
	default:
		logger.Write("amqp: command " + string(envelope.Command) + " cannot be pushed detached\n")
	}
}

// cancelDetached sends a basic.cancel nobody is waiting for and drops the delivery stream
// behind the consumer. Its deadline is its own: the task that would have bounded it does
// not exist.
func cancelDetached(params payloads.CancelParams) {
	entry, err := getChannels().find(params.ChannelId)

	if err != nil {
		return
	}

	// Claimed before the method goes out, the way cancelConsumer() claims it: the registry
	// entry is what says the tag has not been cancelled yet, and sending first leaves a
	// window in which the consumer's own teardown — doAbandoning's abandon goroutine, which
	// sends its own cancel for a registration that outran its deadline — puts a second
	// basic.cancel on the wire for a tag the broker no longer has.
	taskKey, exists := entry.forgetConsumer(params.ConsumerTag)

	if !exists {
		return
	}

	if taskKey != "" {
		states.Get().DeleteState(taskKey)
	}

	ctx, cancel := context.WithTimeout(context.Background(), consumerCancelTimeout)
	defer cancel()

	_ = entry.do(ctx, func(channel *amqp091.Channel) error {
		return channel.Cancel(params.ConsumerTag, params.NoWait)
	})
}

// Shutdown closes every channel and connection the feature holds. A process that never
// opened one is left alone: building the registry here would start its sweeper for
// nothing.
func Shutdown() {
	stopConsumerTelemetry()

	if !connectionsCreated.Load() {
		return
	}

	getConnections().closeAll()
}

// decodeParams unpacks the `p` content of the envelope into the command's own parameters.
func decodeParams[T any](task *tasks.Task, raw msgpack.RawMessage, params *T, what string) bool {
	if err := msgpack.Unmarshal(raw, params); err != nil {
		task.AddResult(dto.NewErrorResult(task.GetMessage(), errFactory.ByErr("parse "+what, err)))

		return false
	}

	return true
}

// commandContext bounds one broker method with the deadline PHP sent, on top of the flow
// context — so a stopped flow aborts a command in flight, and no command runs unbounded.
//
// The fallback is the deadline to use when PHP sent none, and it is not the same for every
// command: publishing is bounded by write_timeout, everything else by rpc_timeout.
func commandContext(
	task *tasks.Task,
	timeoutMs int,
	fallback time.Duration,
) (context.Context, context.CancelFunc) {
	return context.WithTimeout(task.GetContext(), msOrDefault(timeoutMs, fallback))
}

// channelCommand is what onChannel needs of a command's parameters: which channel it runs
// on and how long it may take. payloads.ChannelCommand answers both, and every channel
// command embeds it.
type channelCommand interface {
	Channel() string
	Timeout() int
}

// onChannel runs one broker method on the channel a command names.
//
// Every command on an open channel is the same five steps — decode the parameters, resolve
// the channel, bound the call, run it serialized against the channel's other commands,
// answer the task — and differs only in the driver call and in what it reports. That
// difference is the closure; the steps are here, so a new command cannot get the deadline,
// the failure scope or the execution time subtly wrong by being copied from its neighbour.
//
// The closure answers nil for a command that reports nothing but its success. It runs while
// the channel's lock is held, which is what makes it the right place for a side effect that
// must not outrun the method it belongs to.
//
// A command whose shape differs — one that hands its result to an abandon function, or that
// does not go through do() at all — uses resolveChannel and writes its own tail.
func onChannel[P channelCommand](
	task *tasks.Task,
	raw msgpack.RawMessage,
	what string,
	fallback time.Duration,
	call func(channel *amqp091.Channel, params P) (any, error),
) {
	startTime := time.Now()

	entry, params, ok := resolveChannel[P](task, raw, what)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.Timeout(), fallback)
	defer cancel()

	var result any

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		var callError error

		result, callError = call(channel, params)

		return callError
	})

	if err != nil {
		fail(task, entry, what, err)

		return
	}

	if result == nil {
		respondDone(task, startTime)

		return
	}

	respond(task, result, startTime)
}

// resolveChannel decodes a channel command's parameters and finds the channel it names,
// answering the task itself on either failure.
func resolveChannel[P channelCommand](
	task *tasks.Task,
	raw msgpack.RawMessage,
	what string,
) (*channelEntry, P, bool) {
	var params P

	if !decodeParams(task, raw, &params, what+" params") {
		return nil, params, false
	}

	entry, ok := channelOf(task, params.Channel())

	if !ok {
		return nil, params, false
	}

	return entry, params, true
}

// channelOf resolves a channel id, answering the task with an error when nothing answers
// to it (it was closed, its connection died, or the sweeper collected it).
func channelOf(task *tasks.Task, channelId string) (*channelEntry, bool) {
	entry, err := getChannels().find(channelId)

	if err != nil {
		task.AddResult(dto.NewErrorResult(
			task.GetMessage(),
			errorPayload(scopeChannel, 0, "No channel available."),
		))

		return nil, false
	}

	return entry, true
}

// respond answers the task with a MessagePack-encoded value.
func respond(task *tasks.Task, value any, startTime time.Time) {
	message := task.GetMessage()

	serialized, err := msgpack.Marshal(value)

	if err != nil {
		task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("marshal result", err)))

		return
	}

	task.AddResult(dto.NewSuccessResult(message, string(serialized), helpers.CalcExecutionMs(startTime)))
}

// respondDone answers a command that reports nothing but its success.
func respondDone(task *tasks.Task, startTime time.Time) {
	task.AddResult(dto.NewSuccessResult(task.GetMessage(), "", helpers.CalcExecutionMs(startTime)))
}

// fail answers the task with an error carrying its scope and the AMQP reply code. The
// channel the command ran on (nil when there is none) decides how a "not open" failure is
// classified: the same driver error means a dead channel in one case and a dead connection
// in the other.
func fail(task *tasks.Task, entry *channelEntry, what string, err error) {
	scope, code, text := classify(entry, what, err)

	task.AddResult(dto.NewErrorResult(task.GetMessage(), errorPayload(scope, code, text)))
}

// classify turns a driver error into the scope, the reply code and the message PHP will
// raise.
//
// A channel that is simply gone is a channel-scope failure whatever code the driver put on
// it: the connection behind it is usually alive, and telling an application to redial over
// a queue that does not exist would tear down everything else running on that connection.
func classify(entry *channelEntry, what string, err error) (string, int, string) {
	if errors.Is(err, errWaitTimeout) {
		return scopeCommand, 0, errWaitTimeout.Error()
	}

	// A command that outran its own deadline is a command failure, not a dead connection.
	// It has to be answered here because context.DeadlineExceeded satisfies net.Error —
	// it reports Timeout() and Temporary() — so isNetworkError below would take it for a
	// broken socket and have PHP tear down the connection and every channel on it over one
	// slow declare on a broker that is perfectly alive. context.Canceled carries no such
	// methods and falls through to scopeCommand on its own.
	if errors.Is(err, context.DeadlineExceeded) {
		return scopeCommand, 0, errCommandTimeout.Error()
	}

	// Checked before the broker errors below: the driver reports its own "not open" with
	// an *amqp091.Error carrying a 5xx code, which would otherwise read as a connection
	// the broker tore down.
	if errors.Is(err, amqp091.ErrClosed) {
		// Which of the two died is decided by asking the connection, not by asking whether
		// this side has noticed the channel go. The driver marks a channel closed inside
		// the connection's reader well before the collector here sees the NotifyClose, and
		// in that window a channel-level 404 — a publish to an exchange that is not there,
		// say — would be reported as a dead connection, marking every other channel of that
		// connection unusable over one bad routing key.
		if entry != nil && !entry.connectionClosed() {
			return channelGoneFailure(entry)
		}

		return scopeNetwork, 0, errFactory.ByErr(what, err)
	}

	var brokerError *amqp091.Error

	if errors.As(err, &brokerError) {
		if brokerError.Code >= connectionErrorCode {
			return scopeNetwork, brokerError.Code, fmt.Sprintf(
				"Server connection error: %d, message: %s",
				brokerError.Code,
				brokerError.Reason,
			)
		}

		return scopeChannel, brokerError.Code, fmt.Sprintf(
			"Server channel error: %d, message: %s",
			brokerError.Code,
			brokerError.Reason,
		)
	}

	if isNetworkError(err) {
		return scopeNetwork, 0, errFactory.ByErr(what, err)
	}

	return scopeCommand, 0, errFactory.ByErr(what, err)
}

// channelGoneFailure describes a channel that is no longer usable, naming what closed it
// when the broker said so.
//
// The reason is recorded before the channel is dropped from the registry, so a command that
// finds the channel closed finds the reason with it. What it buys is the cause: a 404 or a
// 406 the broker answered a publish or a declare with is otherwise invisible, because
// basic.publish carries no reply and the next command on that channel could only say the
// channel was gone.
func channelGoneFailure(entry *channelEntry) (string, int, string) {
	reason := entry.takeCloseReason()

	if reason == nil {
		return scopeChannel, 0, "No channel available."
	}

	return scopeChannelGone, reason.Code, fmt.Sprintf(
		"Server channel error: %d, message: %s",
		reason.Code,
		reason.Reason,
	)
}

// isNetworkError tells a dead connection from a broker that refused a method.
//
// net.Error is the interface *net.OpError implements, so matching the interface alone
// covers both.
// errCommandTimeout is what a command that outran the deadline PHP gave it reports. It
// reaches PHP as a command-scope failure, so the connection and the channel it ran on stay
// usable — the next command simply queues behind the one still finishing.
var errCommandTimeout = errors.New("command timeout exceeded")

func isNetworkError(err error) bool {
	if err == nil {
		return false
	}

	var networkError net.Error

	return errors.As(err, &networkError)
}

func errorPayload(scope string, code int, text string) string {
	return scope + ":" + strconv.Itoa(code) + ": " + text
}

// networkErrorPayload marks a failure that happened before any channel existed — a dial
// that could not reach the broker.
func networkErrorPayload(text string) string {
	return errorPayload(scopeNetwork, 0, text)
}
