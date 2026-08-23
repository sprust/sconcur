<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Exceptions\Amqp\PublishNackedException;
use SConcur\Exceptions\Amqp\UnroutableMessageException;
use SConcur\Features\Amqp\Channel;
use SConcur\Features\Amqp\Delivery;
use WeakReference;

/**
 * Reads what the broker sends back about messages: a delivery, and the two answers a
 * publisher confirm can carry. It sits beside PropertiesCodec because the shape of a
 * message on the wire is one subject, and Channel is the API over the commands.
 *
 * Go: payloads.Delivery, payloads.Confirmation, payloads.ReturnedMessage
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class DeliveryCodec
{
    /**
     * Builds a Delivery out of what the Go side sent.
     *
     * @param array<mixed>           $delivery
     * @param WeakReference<Channel> $channel  the channel the message arrived on; weak, so a
     *                                         delivery an application kept does not hold the
     *                                         channel — and through it the connection — open
     * @param bool                   $autoAck  whether the consumer or the get asked the broker
     *                                         to treat the message as answered on delivery
     */
    public static function delivery(array $delivery, WeakReference $channel, bool $autoAck): Delivery
    {
        /** @var array<mixed> $rawProperties */
        $rawProperties = is_array($delivery['ps'] ?? null) ? $delivery['ps'] : [];

        return new Delivery(
            body: isset($delivery['bd']) ? (string) $delivery['bd'] : '',
            routingKey: isset($delivery['rk']) ? (string) $delivery['rk'] : '',
            exchange: isset($delivery['en']) ? (string) $delivery['en'] : '',
            // A basic.get belongs to no consumer, so its tag is empty rather than made up.
            consumerTag: isset($delivery['tg']) ? (string) $delivery['tg'] : '',
            deliveryTag: isset($delivery['dt']) ? (int) $delivery['dt'] : 0,
            redelivered: (bool) ($delivery['rd'] ?? false),
            properties: PropertiesCodec::decode($rawProperties),
            channel: $channel,
            settled: $autoAck,
        );
    }

    /**
     * Raises on the first message the broker sent back as unroutable.
     *
     * Read before the confirmations: an unroutable message is acknowledged too, so the
     * other order would report success for a message that reached no queue.
     *
     * @param array<mixed> $returns
     *
     * @throws UnroutableMessageException
     */
    public static function failOnReturns(array $returns): void
    {
        foreach ($returns as $returned) {
            if (!is_array($returned)) {
                continue;
            }

            /** @var array<mixed> $rawProperties */
            $rawProperties = is_array($returned['ps'] ?? null) ? $returned['ps'] : [];

            $replyCode = isset($returned['rc']) ? (int) $returned['rc'] : 0;
            $replyText = isset($returned['rx']) ? (string) $returned['rx'] : '';

            throw new UnroutableMessageException(
                message: trim("The broker returned the message as unroutable: $replyCode $replyText"),
                returnedMessage: PropertiesCodec::messageFrom(
                    body: isset($returned['bd']) ? (string) $returned['bd'] : '',
                    properties: $rawProperties,
                ),
                exchange: isset($returned['en']) ? (string) $returned['en'] : '',
                routingKey: isset($returned['rk']) ? (string) $returned['rk'] : '',
                replyCode: $replyCode,
            );
        }
    }

    /**
     * Raises on the first message the broker refused to store.
     *
     * @param array<mixed> $confirmations
     *
     * @throws PublishNackedException
     */
    public static function failOnNacks(array $confirmations): void
    {
        foreach ($confirmations as $confirmation) {
            if (!is_array($confirmation) || (bool) ($confirmation['ak'] ?? false)) {
                continue;
            }

            $deliveryTag = isset($confirmation['dt']) ? (int) $confirmation['dt'] : 0;

            throw new PublishNackedException(
                message: "The broker refused to store the message published as delivery tag $deliveryTag.",
            );
        }
    }
}
