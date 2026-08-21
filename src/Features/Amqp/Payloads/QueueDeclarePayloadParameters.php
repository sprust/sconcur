<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a queue.declare command. An empty name asks the broker to generate one,
 * which comes back in the result; passive selects the declare-passive form.
 *
 * Go: payloads.QueueDeclareParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class QueueDeclarePayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        protected string $channelId,
        protected string $name,
        protected bool $passive,
        protected bool $durable,
        protected bool $exclusive,
        protected bool $autoDelete,
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
            'na'   => $this->name,
            'pa'   => $this->passive,
            'du'   => $this->durable,
            'ex'   => $this->exclusive,
            'ad'   => $this->autoDelete,
            'nw'   => $this->noWait,
            'ar'   => $this->arguments,
            'to'   => $this->timeoutMs,
        ];
    }
}
