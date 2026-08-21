<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of an exchange.bind or exchange.unbind command: messages flow from the
 * source exchange to the destination one.
 *
 * Go: payloads.ExchangeBindParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ExchangeBindPayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        protected string $channelId,
        protected string $destination,
        protected string $source,
        protected string $routingKey,
        protected bool $noWait,
        protected array $arguments,
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
            'ds'   => $this->destination,
            'sr'   => $this->source,
            'rk'   => $this->routingKey,
            'nw'   => $this->noWait,
            'ar'   => $this->arguments,
            'to'   => $this->timeoutMs,
        ];
    }
}
