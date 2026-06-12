<?php

declare(strict_types=1);

namespace SConcur\Transport;

use SConcur\Exceptions\ResponseIsNotMessagePackException;
use function msgpack_pack;
use function msgpack_unpack;

final readonly class MessagePackTransport
{
    public static function pack(PayloadInterface $payload): string
    {
        return msgpack_pack($payload->getData());
    }

    /**
     * @return array<mixed>
     */
    public static function unpack(string $payload): array
    {
        $decoded = msgpack_unpack($payload);

        if (!is_array($decoded)) {
            throw new ResponseIsNotMessagePackException(
                message: 'Decoded MessagePack response is not an array.',
            );
        }

        return $decoded;
    }

    /**
     * @param array<int|string, mixed> $payload
     *
     * @return array<int|string, mixed>
     */
    public static function packArray(array &$payload): array
    {
        $result = [];

        $keys = array_keys($payload);

        foreach ($keys as $key) {
            $value = $payload[$key];

            unset($payload[$key]);

            if (is_array($value)) {
                $value = self::packArray($value);
            } elseif ($value instanceof PayloadInterface) {
                $value = $value->getData();

                if (is_array($value)) {
                    $value = self::packArray($value);
                }
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
