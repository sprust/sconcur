<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Redis;

use RuntimeException;

/**
 * The base of every Redis failure that happens while talking to the server.
 *
 * It extends RuntimeException because a server refusal, a dropped connection and a
 * deadline are runtime conditions whatever the caller does — the project's rule for the
 * kind (.ai/README.md, "Exceptions"). The usage mistakes of this feature extend
 * LogicException instead and do not descend from here.
 */
class RedisException extends RuntimeException
{
}
