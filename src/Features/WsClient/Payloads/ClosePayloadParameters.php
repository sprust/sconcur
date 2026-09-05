<?php

declare(strict_types=1);

namespace SConcur\Features\WsClient\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a Close command: the connection to close.
 *
 * Rust: payloads::CloseParams (ext/src/features/wsclient/payloads.rs).
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
