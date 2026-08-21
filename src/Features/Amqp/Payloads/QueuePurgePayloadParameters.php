<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a queue.purge command.
 *
 * Go: payloads.QueuePurgeParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class QueuePurgePayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $channelId,
        protected string $name,
        protected bool $noWait,
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
            'na'   => $this->name,
            'nw'   => $this->noWait,
            'to'   => $this->timeoutMs,
        ];
    }
}
