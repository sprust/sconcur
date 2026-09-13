<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Redis;

use LogicException;

/**
 * A command that would change the state of the connection it runs on — SUBSCRIBE, MULTI,
 * WATCH, SELECT, AUTH and the rest. Ordinary commands share a connection, so running one
 * of these would change what every other coroutine on that socket is talking to.
 *
 * The message names the replacement: subscribe(), transaction(), the dsn.
 */
class UnsupportedRedisCommandException extends LogicException
{
}
