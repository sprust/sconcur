package amqp_feature

import (
	"context"
	"errors"
	"net"
	"sconcur/internal/contracts"
	"sconcur/internal/dto"
	"sconcur/internal/errs"
	"sconcur/internal/features/amqp/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/tasks"
	"sconcur/internal/types"
	"sync"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
	"github.com/vmihailenco/msgpack/v5"
)

var _ contracts.FeatureContract = (*AmqpFeature)(nil)

var once sync.Once
var instance *AmqpFeature

var errFactory = errs.NewErrorsFactory("amqp")

// networkErrorMarker is prefixed onto a failure that means the broker is unreachable
// rather than unhappy, so the PHP side raises AMQPConnectionException instead of the
// protocol-level exception the caller asked for (mirrors the socket and ws clients).
const networkErrorMarker = "net"

// AmqpFeature runs the AMQP 0-9-1 methods of the PHP calque: it owns the pooled
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
	case types.AmqpCancel:
		f.handleCancel(task, envelope.Params)
	case types.AmqpAck:
		f.handleAck(task, envelope.Params)
	case types.AmqpNack:
		f.handleNack(task, envelope.Params)
	case types.AmqpReject:
		f.handleReject(task, envelope.Params)
	case types.AmqpRecover:
		f.handleRecover(task, envelope.Params)
	case types.AmqpTransactionSelect, types.AmqpTransactionCommit, types.AmqpTransactionRollback:
		f.handleTransaction(task, envelope.Params, envelope.Command)
	case types.AmqpConfirmSelect:
		f.handleConfirmSelect(task, envelope.Params)
	case types.AmqpConfirmWait:
		f.handleConfirmWait(task, envelope.Params)
	case types.AmqpReturnWait:
		f.handleReturnWait(task, envelope.Params)
	default:
		task.AddResult(dto.NewErrorResult(message, errFactory.ByText("unknown command")))
	}
}

// Shutdown closes every channel and connection the feature holds. A process that never
// opened one is left alone: building the registry here would start its sweeper for
// nothing.
func Shutdown() {
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
func commandContext(task *tasks.Task, timeoutMs int) (context.Context, context.CancelFunc) {
	return commandContextWithDefault(task, timeoutMs, defaultRpcTimeout)
}

// commandContextWithDefault is commandContext for the commands carrying a deadline of
// their own kind — publishing is bounded by write_timeout, not by rpc_timeout.
func commandContextWithDefault(
	task *tasks.Task,
	timeoutMs int,
	fallback time.Duration,
) (context.Context, context.CancelFunc) {
	return context.WithTimeout(task.GetContext(), msOrDefault(timeoutMs, fallback))
}

// channelOf resolves a channel id, answering the task with an error when nothing answers
// to it (it was closed, its connection died, or the sweeper collected it).
func channelOf(task *tasks.Task, channelId string) (*channelEntry, bool) {
	entry, err := getChannels().find(channelId)

	if err != nil {
		task.AddResult(dto.NewErrorResult(task.GetMessage(), errFactory.ByText("unknown channel "+channelId)))

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

// fail answers the task with an error, marked as network-class when the broker turned out
// to be unreachable.
func fail(task *tasks.Task, what string, err error) {
	message := task.GetMessage()

	if isNetworkError(err) {
		task.AddResult(dto.NewErrorResult(message, networkErrorPayload(what+": "+err.Error())))

		return
	}

	task.AddResult(dto.NewErrorResult(message, errFactory.ByErr(what, err)))
}

// isNetworkError tells a dead connection from a broker that refused a method: the first
// is what AMQPConnectionException is for.
func isNetworkError(err error) bool {
	if err == nil {
		return false
	}

	if errors.Is(err, amqp091.ErrClosed) {
		return true
	}

	var networkError net.Error

	if errors.As(err, &networkError) {
		return true
	}

	var operationError *net.OpError

	return errors.As(err, &operationError)
}

func networkErrorPayload(text string) string {
	return networkErrorMarker + ": " + text
}
