<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Telemetry;

use RuntimeException;

/**
 * A telemetry frame declared a length above the configured maximum. The collector
 * treats this as a misbehaving (or non-telemetry) peer and drops the connection
 * rather than allocating an arbitrarily large buffer.
 */
class FrameTooLargeException extends RuntimeException
{
}
