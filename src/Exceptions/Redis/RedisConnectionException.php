<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Redis;

/**
 * The connection could not be made or did not survive the command: the server is
 * unreachable, it refused the login, or the socket went away with the command in flight.
 *
 * A command that failed this way was not necessarily left undone — the server may have run
 * it and lost the answer — so nothing here is retried on its own.
 */
class RedisConnectionException extends RedisException
{
}
