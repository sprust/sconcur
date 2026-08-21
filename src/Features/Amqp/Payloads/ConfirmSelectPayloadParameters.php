<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a confirm.select command, which puts the channel into publisher-confirm
 * mode.
 *
 * Go: payloads.ConfirmSelectParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ConfirmSelectPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $channelId,
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
            'nw'   => $this->noWait,
            'to'   => $this->timeoutMs,
        ];
    }
}
