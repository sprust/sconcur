<?php

declare(strict_types=1);

namespace SConcur\Features\HttpClient\Payloads;

use SConcur\Features\HttpClient\HttpClientCommandEnum;
use SConcur\Features\HttpClient\Payloads\Base\BaseHttpClientPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The UploadChunk command: append a chunk to an open streamed request body.
 */
readonly class UploadChunkPayload extends BaseHttpClientPayload
{
    public function __construct(
        protected string $requestId,
        protected string $body,
    ) {
    }

    protected function getCommand(): HttpClientCommandEnum
    {
        return HttpClientCommandEnum::UploadChunk;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return new UploadPayloadParameters(
            requestId: $this->requestId,
            body: $this->body,
        );
    }
}
