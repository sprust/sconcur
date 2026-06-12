<?php

declare(strict_types=1);

namespace SConcur\Transport;

use SConcur\Exceptions\ResponseIsNotMessagePackException;
use function msgpack_pack;
use function msgpack_unpack;

final readonly class MessagePackTransport
{
    public static function pack(mixed $payload): string
    {
        return msgpack_pack($payload);
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
}
