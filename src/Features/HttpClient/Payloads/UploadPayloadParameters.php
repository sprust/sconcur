<?php

declare(strict_types=1);

namespace SConcur\Features\HttpClient\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of an upload command: the request being uploaded to (requestId) and,
 * for a chunk, the bytes to append (empty for the end marker).
 *
 * Go: payloads.UploadParams (ext/internal/features/httpclient/payloads/payloads.go).
 */
readonly class UploadPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $requestId,
        protected string $body,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getData(): array
    {
        return [
            'rid' => $this->requestId,
            'b'   => $this->body,
        ];
    }
}
