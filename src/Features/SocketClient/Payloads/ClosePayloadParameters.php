<?php

declare(strict_types=1);

namespace SConcur\Features\SocketClient\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a Close command: the connection to close.
 *
 * Go: payloads.CloseParams (ext/internal/features/socketclient/payloads/payloads.go).
 */
readonly class ClosePayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $connectionId,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getData(): array
    {
        return [
            'cid' => $this->connectionId,
        ];
    }
}
