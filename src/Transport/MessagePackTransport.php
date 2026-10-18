<?php

declare(strict_types=1);

namespace SConcur\Transport;

use function msgpack_pack;

/**
 * The one direction MessagePack is still used in: a task's parameters on their
 * way to the extension. A result comes back as the PHP value the core built.
 */
readonly class MessagePackTransport
{
    public static function pack(PayloadInterface $payload): string
    {
        return msgpack_pack($payload->getData());
    }
}
