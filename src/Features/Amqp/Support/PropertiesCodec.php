<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Features\Amqp\AMQPBasicProperties;
use const SConcur\Features\Amqp\AMQP_DELIVERY_MODE_TRANSIENT;

/**
 * Translates between the publish attributes an application passes to
 * AMQPExchange::publish() (the snake_case array ext-amqp accepts) and the properties map
 * that crosses to Go, and back from that map into AMQPBasicProperties.
 *
 * The rules are the extension's, kept deliberately: a message with no content_type is
 * published as text/plain, and an empty string in any of the string properties means "do
 * not set the property at all" rather than "set it to an empty value".
 */
readonly class PropertiesCodec
{
    /** The content type ext-amqp publishes with when the caller names none. */
    protected const string DEFAULT_CONTENT_TYPE = 'text/plain';

    /** Publish attribute name => the key it travels under. */
    protected const array STRING_PROPERTIES = [
        'content_type'     => 'ct',
        'content_encoding' => 'ce',
        'correlation_id'   => 'ci',
        'reply_to'         => 'rp',
        'expiration'       => 'ep',
        'message_id'       => 'mi',
        'type'             => 'ty',
        'user_id'          => 'ui',
        'app_id'           => 'ai',
    ];

    /** Publish attribute name => the key it travels under. */
    protected const array INTEGER_PROPERTIES = [
        'delivery_mode' => 'dm',
        'priority'      => 'pr',
        'timestamp'     => 'ts',
    ];

    /**
     * Builds the wire properties of a published message from the attributes array.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public static function encode(array $attributes): array
    {
        $properties = [
            'ct' => self::DEFAULT_CONTENT_TYPE,
        ];

        foreach (self::STRING_PROPERTIES as $attribute => $key) {
            if (!isset($attributes[$attribute])) {
                continue;
            }

            $value = (string) $attributes[$attribute];

            if ($value === '') {
                continue;
            }

            $properties[$key] = $value;
        }

        foreach (self::INTEGER_PROPERTIES as $attribute => $key) {
            if (!isset($attributes[$attribute])) {
                continue;
            }

            $properties[$key] = (int) $attributes[$attribute];
        }

        if (isset($attributes['headers']) && is_array($attributes['headers'])) {
            /** @var array<string, mixed> $headers */
            $headers = $attributes['headers'];

            $properties['hd'] = TableCodec::encode($headers);
        }

        return $properties;
    }

    /**
     * Rebuilds the basic properties of a delivered message from the wire map.
     *
     * @param array<mixed> $properties
     */
    public static function decode(array $properties): AMQPBasicProperties
    {
        /** @var array<string, mixed> $rawHeaders */
        $rawHeaders = is_array($properties['hd'] ?? null) ? $properties['hd'] : [];

        $headers = TableCodec::decode($rawHeaders);

        return new AMQPBasicProperties(
            contentType: self::readString($properties, 'ct'),
            contentEncoding: self::readString($properties, 'ce'),
            headers: $headers,
            deliveryMode: self::readInt($properties, 'dm') ?? AMQP_DELIVERY_MODE_TRANSIENT,
            priority: self::readInt($properties, 'pr') ?? 0,
            correlationId: self::readString($properties, 'ci'),
            replyTo: self::readString($properties, 'rp'),
            expiration: self::readString($properties, 'ep'),
            messageId: self::readString($properties, 'mi'),
            timestamp: self::readInt($properties, 'ts'),
            type: self::readString($properties, 'ty'),
            userId: self::readString($properties, 'ui'),
            appId: self::readString($properties, 'ai'),
            clusterId: self::readString($properties, 'cl'),
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
