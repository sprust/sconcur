<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a basic.qos command: the prefetch window and message count, and whether
 * they apply to the whole channel or to each consumer.
 *
 * Go: payloads.QosParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class QosPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $channelId,
        protected int $prefetchSizeBytes,
        protected int $prefetchCount,
        protected bool $global,
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
            'sz'   => $this->prefetchSizeBytes,
            'ct'   => $this->prefetchCount,
            'gl'   => $this->global,
            'to'   => $this->timeoutMs,
        ];
    }
}
