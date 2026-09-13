<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Redis;

/**
 * A subscription was used after it was closed — by close(), by the flow ending, or by the
 * connection going away.
 */
class SubscriptionClosedException extends RedisException
{
}
