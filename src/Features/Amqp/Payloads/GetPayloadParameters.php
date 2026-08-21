<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a basic.get command: one message from the queue, or nothing.
 *
 * Go: payloads.GetParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class GetPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $channelId,
        protected string $queueName,
        protected bool $autoAck,
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
            'na'   => $this->queueName,
            'aa'   => $this->autoAck,
            'to'   => $this->timeoutMs,
        ];
    }
}
