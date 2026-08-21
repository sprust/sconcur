<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a ChannelOpen command: the connection to open the channel on plus the
 * prefetch settings to apply to it right away.
 *
 * ext-amqp opens the channel and immediately sends a basic.qos with the prefetch values
 * of the new channel; the calque carries them along instead, so opening a channel is one
 * crossing of the PHP ↔ Go boundary rather than two.
 *
 * Go: payloads.ChannelOpenParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ChannelOpenPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $connectionId,
        protected int $prefetchSizeBytes,
        protected int $prefetchCount,
        protected int $globalPrefetchSizeBytes,
        protected int $globalPrefetchCount,
        protected int $timeoutMs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return [
            'cid' => $this->connectionId,
            'sz'  => $this->prefetchSizeBytes,
            'ct'  => $this->prefetchCount,
            'gsz' => $this->globalPrefetchSizeBytes,
            'gct' => $this->globalPrefetchCount,
            'to'  => $this->timeoutMs,
        ];
    }
}
