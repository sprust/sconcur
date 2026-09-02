<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

/**
 * What a failed command did to the resource it ran on, as the Go side reports it in the
 * error payload's prefix.
 *
 * Go: the scope* constants (ext-go-legacy/internal/features/amqp/feature.go).
 */
enum FailureScopeEnum: string
{
    /** The broker is unreachable or the connection died. */
    case Connection = 'net';

    /** The broker closed the channel over this failure; it cannot be used again. */
    case Channel = 'chn';

    /**
     * The channel was already gone when the command reached it, and the broker said why.
     * Told apart from Channel, which means "the broker refused this method" and raises the
     * caller's own exception: a confirm wait that finds the channel closed by an earlier
     * publish's 404 is not a confirm timeout.
     */
    case ChannelGone = 'chg';

    /** The command failed, the channel is still usable. */
    case Command = 'err';
}
