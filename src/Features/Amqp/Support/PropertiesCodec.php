<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Features\Amqp\Message;
use SConcur\Features\Amqp\MessageProperties;

/**
 * Translates between the message objects the API takes and gives back and the properties
 * map that crosses to Go.
 *
 * A property nobody set does not travel: AMQP distinguishes an absent content type from an
 * empty one, and a message published here carries exactly the properties it was built
 * with. The calque published `text/plain` for a message that named no content type,
 * because that is what the extension does; inventing a content type for an application
 * that did not ask for one is not worth keeping.
 */
readonly class PropertiesCodec
{
    /** Message property => the key it travels under. */
    protected const array STRING_PROPERTIES = [
        'contentType'     => 'ct',
        'contentEncoding' => 'ce',
        'correlationId'   => 'ci',
        'replyTo'         => 'rp',
        'expiration'      => 'ep',
        'messageId'       => 'mi',
        'type'            => 'ty',
        'userId'          => 'ui',
        'appId'           => 'ai',
    ];

    /**
     * Builds the wire properties of a published message.
     *
     * @return array<string, mixed>
     */
    public static function encode(Message $message): array
    {
        $properties = [];

        foreach (self::STRING_PROPERTIES as $property => $key) {
            $value = $message->{$property};

            if ($value === null || $value === '') {
                continue;
            }

            $properties[$key] = (string) $value;
        }

        if ($message->persistent) {
            $properties['dm'] = MessageProperties::DELIVERY_MODE_PERSISTENT;
        }

        if ($message->priority !== null) {
            $properties['pr'] = $message->priority;
        }

        if ($message->timestamp !== null) {
            $properties['ts'] = $message->timestamp;
        }

        if ($message->headers !== []) {
            $properties['hd'] = TableCodec::encode($message->headers);
        }

        return $properties;
    }

    /**
     * Rebuilds the properties of a delivered message from the wire map.
     *
     * @param array<mixed> $properties
     */
    public static function decode(array $properties): MessageProperties
    {
        /** @var array<string, mixed> $rawHeaders */
        $rawHeaders = is_array($properties['hd'] ?? null) ? $properties['hd'] : [];

        return new MessageProperties(
            contentType: self::readString($properties, 'ct'),
            contentEncoding: self::readString($properties, 'ce'),
            deliveryMode: self::readInt($properties, 'dm') ?? MessageProperties::DELIVERY_MODE_TRANSIENT,
            priority: self::readInt($properties, 'pr'),
            correlationId: self::readString($properties, 'ci'),
            replyTo: self::readString($properties, 'rp'),
            expiration: self::readString($properties, 'ep'),
            messageId: self::readString($properties, 'mi'),
            timestamp: self::readInt($properties, 'ts'),
            type: self::readString($properties, 'ty'),
            userId: self::readString($properties, 'ui'),
            appId: self::readString($properties, 'ai'),
            clusterId: self::readString($properties, 'cl'),
            headers: TableCodec::decode($rawHeaders),
        );
    }

    /**
     * Rebuilds a publishable message out of a body and the wire properties — what a
     * returned message is made of.
     *
     * @param array<mixed> $properties
     */
    public static function messageFrom(string $body, array $properties): Message
    {
        $decoded = self::decode($properties);

        return new Message(
            body: $body,
            contentType: $decoded->contentType,
            contentEncoding: $decoded->contentEncoding,
            persistent: $decoded->isPersistent(),
            priority: $decoded->priority,
            correlationId: $decoded->correlationId,
            replyTo: $decoded->replyTo,
            expiration: $decoded->expiration,
            messageId: $decoded->messageId,
            timestamp: $decoded->timestamp,
            type: $decoded->type,
            userId: $decoded->userId,
            appId: $decoded->appId,
            headers: $decoded->headers,
        );
    }

    /**
     * @param array<mixed> $properties
     */
    protected static function readString(array $properties, string $key): ?string
    {
        if (!isset($properties[$key])) {
            return null;
        }

        $value = (string) $properties[$key];

        return $value === '' ? null : $value;
    }

    /**
     * @param array<mixed> $properties
     */
    protected static function readInt(array $properties, string $key): ?int
    {
        if (!isset($properties[$key])) {
            return null;
        }

        return (int) $properties[$key];
    }
}
