<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a basic.cancel command.
 *
 * Go: payloads.CancelParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class CancelPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $channelId,
        protected string $consumerTag,
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
            'tg'   => $this->consumerTag,
            'nw'   => $this->noWait,
            'to'   => $this->timeoutMs,
        ];
    }
}
