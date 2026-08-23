package amqp_feature

import (
	"time"

	"sconcur/internal/features/amqp/payloads"
	"sconcur/internal/tasks"

	amqp091 "github.com/rabbitmq/amqp091-go"
	"github.com/vmihailenco/msgpack/v5"
)

// handlePublish publishes one message. basic.publish carries no reply, so a message the
// broker cannot route is only reported when it was published as mandatory and the
// application waits for the returns.
func (f *AmqpFeature) handlePublish(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.PublishParams

	if !decodeParams(task, raw, &params, "publish params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs, defaultWriteTimeout)
	defer cancel()

	publishing := publishingFromProperties(params.Properties, params.Body)

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		// Counted here, inside the closure that publishes, and not before do(): a publish
		// whose deadline passed while it was still queued behind another command on this
		// channel never reaches the driver at all, and one counted up front would leave a
		// message pending for good — the next wait for confirms would then sit out its
		// whole deadline waiting for a confirmation with nothing to confirm.
		entry.publishing()

		publishError := channel.PublishWithContext(
			ctx,
			params.ExchangeName,
			params.RoutingKey,
			params.Mandatory,
			params.Immediate,
			publishing,
		)

		if publishError != nil {
			// Nothing went out — PublishWithContext checks the context before it writes,
			// and a write that failed carries no confirmation either — so the count goes
			// straight back rather than waiting for an answer that cannot come.
			entry.publishFailed()
		}

		return publishError
	})

	if err != nil {
		fail(task, entry, "publish", err)

		return
	}

	respondDone(task, startTime)
}

// publishingFromProperties builds the message the driver publishes. ClusterId is absent
// on purpose: AMQP 0-9-1 excludes it from publishing, and so does the driver.
func publishingFromProperties(properties payloads.Properties, body string) amqp091.Publishing {
	return amqp091.Publishing{
		Headers:         mapToTable(properties.Headers),
		ContentType:     properties.ContentType,
		ContentEncoding: properties.ContentEncoding,
		DeliveryMode:    uint8(properties.DeliveryMode),
		Priority:        uint8(properties.Priority),
		CorrelationId:   properties.CorrelationId,
		ReplyTo:         properties.ReplyTo,
		Expiration:      properties.Expiration,
		MessageId:       properties.MessageId,
		Timestamp:       unixToTimestamp(properties.Timestamp),
		Type:            properties.Type,
		UserId:          properties.UserId,
		AppId:           properties.AppId,
		Body:            []byte(body),
	}
}
