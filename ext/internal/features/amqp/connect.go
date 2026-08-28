package amqp_feature

import (
	"sconcur/internal/dto"
	"sconcur/internal/features/amqp/payloads"
	"sconcur/internal/tasks"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
	"github.com/vmihailenco/msgpack/v5"
)

// handleConnect hands out a connection handle: a share of a pooled connection to the
// broker, dialed here if the pool holds none for these credentials.
func (f *AmqpFeature) handleConnect(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.ConnectParams

	if !decodeParams(task, raw, &params, "connect params") {
		return
	}

	// The dial is bounded by the connect timeout inside open(); the flow context aborts
	// it if the coroutine goes away meanwhile.
	handle, err := getConnections().open(task.GetContext(), params)

	if err != nil {
		// Connection refused, DNS failure, a rejected login, a dial timeout: all of them
		// mean the application cannot reach this broker.
		task.AddResult(dto.NewErrorResult(task.GetMessage(), networkErrorPayload("connect: "+err.Error())))

		return
	}

	respond(task, payloads.ConnectResult{
		ConnectionId: handle.id,
		MaxChannels:  handle.pooled.maxChannels,
		MaxFrameSize: handle.pooled.maxFrameSize,
		Heartbeat:    handle.pooled.heartbeat,
	}, startTime)
}

// handleDisconnect releases a handle: its channels are closed and the connection is left
// to the pool, which sweeps it once nothing holds it any more.
func (f *AmqpFeature) handleDisconnect(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.ConnectionParams

	if !decodeParams(task, raw, &params, "disconnect params") {
		return
	}

	getConnections().release(params.ConnectionId)

	respondDone(task, startTime)
}

// handleUsedChannels counts the channels the handle holds open.
func (f *AmqpFeature) handleUsedChannels(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.ConnectionParams

	if !decodeParams(task, raw, &params, "used channels params") {
		return
	}

	handle := getConnections().find(params.ConnectionId)

	if handle == nil {
		respond(task, payloads.UsedChannelsResult{}, startTime)

		return
	}

	respond(task, payloads.UsedChannelsResult{UsedChannels: getChannels().usedChannels(handle)}, startTime)
}

// handleChannelOpen opens a channel on the handle's connection and applies its prefetch
// settings.
func (f *AmqpFeature) handleChannelOpen(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.ChannelOpenParams

	if !decodeParams(task, raw, &params, "channel open params") {
		return
	}

	handle := getConnections().find(params.ConnectionId)

	if handle == nil {
		// Scoped, and scoped as the connection being gone — which is what it is: the
		// handle was released, or the connection behind it died and took its handle with
		// it. An unscoped error would reach PHP as a plain command failure, leaving the
		// Connection object reporting itself open and every later call failing the same
		// way instead of saying to reconnect.
		task.AddResult(dto.NewErrorResult(
			task.GetMessage(),
			networkErrorPayload("No connection available."),
		))

		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs, defaultRpcTimeout)
	defer cancel()

	entry, err := getChannels().openBounded(ctx, handle, params)

	if err != nil {
		fail(task, nil, "channel open", err)

		return
	}

	respond(task, payloads.ChannelOpenResult{
		ChannelId:     entry.id,
		ChannelNumber: entry.number,
	}, startTime)
}

// handleChannelClose closes a channel and drops it from the registry.
func (f *AmqpFeature) handleChannelClose(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.ChannelParams

	if !decodeParams(task, raw, &params, "channel close params") {
		return
	}

	getChannels().close(params.ChannelId)

	respondDone(task, startTime)
}

// handleQos applies one set of prefetch settings to a channel.
func (f *AmqpFeature) handleQos(task *tasks.Task, raw msgpack.RawMessage) {
	onChannel(
		task,
		raw,
		"qos",
		defaultRpcTimeout,
		func(channel *amqp091.Channel, params payloads.QosParams) (any, error) {
			return nil, channel.Qos(params.PrefetchCount, params.PrefetchSizeBytes, params.Global)
		},
	)
}
