<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Redis;

/**
 * The command did not answer within the connection's timeoutMs. As with a dropped
 * connection, the server may still have run it.
 */
class RedisTimeoutException extends RedisException
{
}
