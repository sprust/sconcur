<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a basic.nack command.
 *
 * Go: payloads.NackParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class NackPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $channelId,
        protected int $deliveryTag,
        protected bool $multiple,
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
            'dt'   => $this->deliveryTag,
            'mu'   => $this->multiple,
            'rq'   => $this->requeue,
            'to'   => $this->timeoutMs,
        ];
    }
}
