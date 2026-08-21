<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of the commands addressing a connection handle as a whole: releasing it,
 * opening a channel on it, counting its channels.
 *
 * Go: payloads.ConnectionParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ConnectionPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $connectionId,
        protected int $timeoutMs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return [
            'cid' => $this->connectionId,
            'to'  => $this->timeoutMs,
        ];
    }
}
