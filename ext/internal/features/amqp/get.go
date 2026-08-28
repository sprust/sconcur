package amqp_feature

import (
	"context"
	"sconcur/internal/dto"
	"sconcur/internal/features/amqp/payloads"
	"sconcur/internal/helpers"
	"sconcur/internal/logger"
	"sconcur/internal/tasks"
	"time"

	amqp091 "github.com/rabbitmq/amqp091-go"
	"github.com/vmihailenco/msgpack/v5"
)

// handleGet pulls one message from a queue. An empty queue answers with an empty result,
// which PHP hands back as null — it never waits for a message to arrive.
func (f *AmqpFeature) handleGet(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()

	entry, params, ok := resolveChannel[payloads.GetParams](task, raw, "get")

	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs, defaultRpcTimeout)
	defer cancel()

	var delivery amqp091.Delivery
	var delivered bool

	err := entry.doAbandoning(
		ctx,
		func(channel *amqp091.Channel) error {
			var getError error

			delivery, delivered, getError = channel.Get(params.QueueName, params.AutoAck)

			return getError
		},
		// The message arrived after PHP stopped waiting. Unacknowledged, it goes back to
		// the queue; acknowledged on delivery, it is already gone and all that is left is
		// to say so — dropping it in silence would read as an empty queue.
		func(getError error) {
			if getError != nil || !delivered {
				return
			}

			if params.AutoAck {
				logger.Write(
					"amqp: a get on " + params.QueueName + " timed out after the broker handed" +
						" the message over; it was auto-acknowledged and is lost\n",
				)

				return
			}

			_ = entry.do(context.Background(), func(channel *amqp091.Channel) error {
				return channel.Nack(delivery.DeliveryTag, false, true)
			})
		},
	)

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

// deliveryToPayload turns a driver delivery into the map PHP builds a Delivery from.
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
