package amqp_feature

import (
	"sconcur/internal/features/amqp/payloads"
	"sconcur/internal/tasks"
	"time"

	"github.com/vmihailenco/msgpack/v5"
)

// handleConfirmSelect puts the channel into publisher-confirm mode and starts collecting
// what the broker reports about the messages published on it.
func (f *AmqpFeature) handleConfirmSelect(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.ConfirmSelectParams

	if !decodeParams(task, raw, &params, "confirm select params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs)
	defer cancel()

	if err := entry.startConfirmMode(ctx, params.NoWait); err != nil {
		fail(task, entry, "confirm select", err)

		return
	}

	respondDone(task, startTime)
}

// handleConfirmWait waits until every message published on the channel since the last
// wait has been confirmed or rejected, and hands back what arrived, the returned messages
// included.
func (f *AmqpFeature) handleConfirmWait(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.ChannelParams

	if !decodeParams(task, raw, &params, "confirm wait params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	// A zero timeout means "wait until the broker answers", so the wait rides the flow
	// context alone — a stopped coroutine still ends it.
	result, err := entry.waitForConfirms(
		task.GetContext(),
		time.Duration(max(params.TimeoutMs, 0))*time.Millisecond,
	)

	if err != nil {
		fail(task, entry, "confirm wait", err)

		return
	}

	respond(task, result, startTime)
}
