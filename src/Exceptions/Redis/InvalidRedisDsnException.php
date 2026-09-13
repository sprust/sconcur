<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Redis;

use LogicException;

/**
 * The dsn cannot be used: an unknown scheme, or a query parameter the connection would
 * read as nothing. A parameter silently ignored means the connection does not behave the
 * way the configuration says it does, so it is refused instead.
 */
class InvalidRedisDsnException extends LogicException
{
}
