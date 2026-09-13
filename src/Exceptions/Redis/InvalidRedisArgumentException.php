<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Redis;

use LogicException;

/**
 * A command argument that is not a string, an integer or a float.
 *
 * Booleans, null and arrays are refused rather than converted: a client that turns false
 * into an empty string writes a value the application never asked for, and the mistake
 * only ever shows up in the data.
 */
class InvalidRedisArgumentException extends LogicException
{
}
