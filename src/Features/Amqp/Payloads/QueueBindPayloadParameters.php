<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a queue.bind or queue.unbind command.
 *
 * Go: payloads.QueueBindParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class QueueBindPayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        protected string $channelId,
        protected string $queueName,
        protected string $exchangeName,
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
            'na'   => $this->queueName,
            'en'   => $this->exchangeName,
            'rk'   => $this->routingKey,
            'nw'   => $this->noWait,
            'ar'   => $this->arguments,
            'to'   => $this->timeoutMs,
        ];
    }
}
