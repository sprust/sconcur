<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of an exchange.declare command: one boolean per protocol field, as
 * Exchange::declare() takes them. passive selects the declare-passive form.
 *
 * Go: payloads.ExchangeDeclareParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ExchangeDeclarePayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        protected string $channelId,
        protected string $name,
        protected string $type,
        protected bool $passive,
        protected bool $durable,
        protected bool $autoDelete,
        protected bool $internal,
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
            'ty'   => $this->type,
            'pa'   => $this->passive,
            'du'   => $this->durable,
            'ad'   => $this->autoDelete,
            'in'   => $this->internal,
            'nw'   => $this->noWait,
            'ar'   => $this->arguments,
            'to'   => $this->timeoutMs,
        ];
    }
}
