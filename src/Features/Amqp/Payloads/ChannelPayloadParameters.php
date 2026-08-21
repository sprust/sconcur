<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of the commands that need nothing but the channel itself: closing it, the
 * transaction methods, and the publisher-confirm and return wait loops.
 *
 * Go: payloads.ChannelParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ChannelPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $channelId,
        protected int $timeoutMs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return [
            'chid' => $this->channelId,
            'to'   => $this->timeoutMs,
        ];
    }
}
