<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a basic.ack command: multiple acknowledges every delivery up to and
 * including the tag.
 *
 * Go: payloads.AckParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class AckPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $channelId,
        protected int $deliveryTag,
        protected bool $multiple,
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
            'to'   => $this->timeoutMs,
        ];
    }
}
