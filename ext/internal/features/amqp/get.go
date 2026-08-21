package amqp_feature

import (
	"sconcur/internal/dto"
	"sconcur/internal/features/amqp/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/tasks"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
	"github.com/vmihailenco/msgpack/v5"
)

// handleGet pulls one message from a queue. An empty queue answers with an empty result,
// which PHP hands back as null — it never waits for a message to arrive.
func (f *AmqpFeature) handleGet(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	var params payloads.GetParams

	if !decodeParams(task, raw, &params, "get params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs)
	defer cancel()

	var delivery amqp091.Delivery
	var delivered bool

	err := entry.do(ctx, func(channel *amqp091.Channel) error {
		var getError error

		delivery, delivered, getError = channel.Get(params.QueueName, params.AutoAck)

		return getError
	})

	if err != nil {
		fail(task, entry, "get", err)

		return
	}

	if !delivered {
		task.AddResult(dto.NewSuccessResult(task.GetMessage(), "", helpers.CalcExecutionMs(startTime)))

		return
	}

	respond(task, deliveryToPayload(delivery, entry.id), startTime)
}

// deliveryToPayload turns a driver delivery into the map PHP builds an AMQPEnvelope from.
// The channel id travels with it because a delivery tag is only valid on the channel that
// delivered the message.
func deliveryToPayload(delivery amqp091.Delivery, channelId string) payloads.Delivery {
	return payloads.Delivery{
		ChannelId:    channelId,
		ConsumerTag:  delivery.ConsumerTag,
		DeliveryTag:  delivery.DeliveryTag,
		Redelivered:  delivery.Redelivered,
		ExchangeName: delivery.Exchange,
		RoutingKey:   delivery.RoutingKey,
		Body:         string(delivery.Body),
		Properties: payloads.Properties{
			ContentType:     delivery.ContentType,
			ContentEncoding: delivery.ContentEncoding,
			Headers:         tableToMap(delivery.Headers),
			DeliveryMode:    int(delivery.DeliveryMode),
			Priority:        int(delivery.Priority),
			CorrelationId:   delivery.CorrelationId,
			ReplyTo:         delivery.ReplyTo,
			Expiration:      delivery.Expiration,
			MessageId:       delivery.MessageId,
			Timestamp:       timestampToUnix(delivery.Timestamp),
			Type:            delivery.Type,
			UserId:          delivery.UserId,
			AppId:           delivery.AppId,
		},
	}
}
