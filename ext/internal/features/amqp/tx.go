package amqp_feature

import (
	"sconcur/internal/features/amqp/payloads"
	"sconcur/internal/tasks"
	"sconcur/internal/types"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
	"github.com/vmihailenco/msgpack/v5"
)

// handleTransaction runs one of the three transaction methods on a channel: select,
// commit or rollback.
func (f *AmqpFeature) handleTransaction(task *tasks.Task, raw msgpack.RawMessage, command types.AmqpCommand) {
	startTime := time.Now()

	var params payloads.ChannelParams

	if !decodeParams(task, raw, &params, "transaction params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs)
	defer cancel()

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		switch command {
		case types.AmqpTransactionCommit:
			return channel.TxCommit()
		case types.AmqpTransactionRollback:
			return channel.TxRollback()
		default:
			return channel.Tx()
		}
	})

	if err != nil {
		fail(task, "transaction", err)

		return
	}

	respondDone(task, startTime)
}
