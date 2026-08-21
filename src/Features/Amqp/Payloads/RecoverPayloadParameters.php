<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a basic.recover command.
 *
 * Go: payloads.RecoverParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class RecoverPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $channelId,
        protected bool $requeue,
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
            'rq'   => $this->requeue,
            'to'   => $this->timeoutMs,
        ];
    }
}
