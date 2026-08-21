<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

/**
 * What a failed command did to the resource it ran on, as the Go side reports it in the
 * error payload's prefix.
 *
 * Go: the scope* constants (ext/internal/features/amqp/feature.go).
 */
enum FailureScopeEnum: string
{
    /** The broker is unreachable or the connection died. */
    case Connection = 'net';

    /** The broker closed the channel over this failure; it cannot be used again. */
    case Channel = 'chn';

    /** The command failed, the channel is still usable. */
    case Command = 'err';
}
