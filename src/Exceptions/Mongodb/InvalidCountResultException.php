<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Mongodb;

use RuntimeException;

/**
 * A MongoDB count operation returned a payload that is not a non-negative
 * integer — an unexpected response from the extension.
 */
class InvalidCountResultException extends RuntimeException
{
}
