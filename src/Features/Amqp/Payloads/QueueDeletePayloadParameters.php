<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a queue.delete command.
 *
 * Go: payloads.QueueDeleteParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class QueueDeletePayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $channelId,
        protected string $name,
        protected bool $ifUnused,
        protected bool $ifEmpty,
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
            'iu'   => $this->ifUnused,
            'ie'   => $this->ifEmpty,
            'nw'   => $this->noWait,
            'to'   => $this->timeoutMs,
        ];
    }
}
