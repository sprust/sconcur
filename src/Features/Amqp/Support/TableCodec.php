<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Features\Amqp\AMQPValue;

/**
 * Prepares an AMQP field table (queue and exchange arguments, message headers) for the
 * wire: the values an application may hand in as AMQPValue objects — AMQPDecimal,
 * AMQPTimestamp, its own implementations — are replaced by the scalars they represent,
 * nested tables included.
 *
 * Nothing has to be decoded on the way back: the field-table types the broker sends have
 * a direct MessagePack counterpart, and the Go side already writes them as scalars.
 */
readonly class TableCodec
{
    /**
     * @param array<string, mixed> $table
     *
     * @return array<string, mixed>
     */
    public static function encode(array $table): array
    {
        $encoded = [];

        foreach ($table as $name => $value) {
            $encoded[$name] = static::encodeValue($value);
        }

        return $encoded;
    }

    protected static function encodeValue(mixed $value): mixed
    {
        if ($value instanceof AMQPValue) {
            return $value->toAmqpValue();
        }

        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return static::encode($value);
        }

        return $value;
    }
}
