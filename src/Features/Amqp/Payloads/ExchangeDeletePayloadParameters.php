<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of an exchange.delete command.
 *
 * Go: payloads.ExchangeDeleteParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ExchangeDeletePayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $channelId,
        protected string $name,
        protected bool $ifUnused,
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
            'na'   => $this->name,
            'iu'   => $this->ifUnused,
            'nw'   => $this->noWait,
            'to'   => $this->timeoutMs,
        ];
    }
}
