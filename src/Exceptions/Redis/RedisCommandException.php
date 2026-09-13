<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Redis;

use Throwable;

/**
 * The server answered the command with an error. The Redis error code is kept whole in
 * errorCode — WRONGTYPE, NOSCRIPT, NOAUTH, MOVED, LOADING and the rest — so a caller
 * branches on it instead of matching the message with a pattern.
 *
 * The code is a string, which is why it is a property of its own rather than the
 * exception's numeric code.
 */
class RedisCommandException extends RedisException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            code: 0,
            previous: $previous,
        );
    }
}
