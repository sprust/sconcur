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
	// AMQPConnectionException, whichever exception the caller asked for.
	scopeNetwork = "net"
	// scopeChannel: the broker closed the channel over this failure. PHP raises the
	// caller's exception and marks its AMQPChannel closed, as the extension does.
	scopeChannel = "chn"
	// scopeCommand: the command failed with the channel left usable.
	scopeCommand = "err"

	// connectionErrorCode is the lowest AMQP reply code that closes the connection
	// rather than the channel (the 5xx class of AMQP 0-9-1).
	connectionErrorCode = 500
)

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
// raise. The message wording follows ext-amqp, so an application that reads it (or matches
// on it) sees what it saw before.
//
// A channel that is simply gone is a channel-scope failure whatever code the driver put on
// it: the connection behind it is usually alive, and telling an application to redial over
// a queue that does not exist would tear down everything else running on that connection.
func classify(entry *channelEntry, what string, err error) (string, int, string) {
	// Checked before the broker errors below: the driver reports its own "not open" with
	// an *amqp091.Error carrying a 5xx code, which would otherwise read as a connection
	// the broker tore down.
	// The wait loops report what the extension reports; wrapping it would change a message
	// applications match on.
	if errors.Is(err, errWaitTimeout) {
		return scopeCommand, 0, errWaitTimeout.Error()
	}

	if errors.Is(err, amqp091.ErrClosed) {
		// With a channel of our own that is known closed, this is that channel; with no
		// channel in play (opening one) or one that still looks alive, the connection
		// behind it is what went away.
		if entry != nil && entry.isClosed() {
			return scopeChannel, 0, "No channel available."
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

// isNetworkError tells a dead connection from a broker that refused a method.
func isNetworkError(err error) bool {
	if err == nil {
		return false
	}

	var networkError net.Error

	if errors.As(err, &networkError) {
		return true
	}

	var operationError *net.OpError

	return errors.As(err, &operationError)
}

func errorPayload(scope string, code int, text string) string {
	return scope + ":" + strconv.Itoa(code) + ": " + text
}

// networkErrorPayload marks a failure that happened before any channel existed — a dial
// that could not reach the broker.
func networkErrorPayload(text string) string {
	return errorPayload(scopeNetwork, 0, text)
}
