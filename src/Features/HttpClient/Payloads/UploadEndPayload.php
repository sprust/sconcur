<?php

declare(strict_types=1);

namespace SConcur\Features\HttpClient\Payloads;

use SConcur\Features\HttpClient\HttpClientCommandEnum;
use SConcur\Features\HttpClient\Payloads\Base\BaseHttpClientPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The UploadEnd command: close an open streamed request body (no more chunks).
 */
readonly class UploadEndPayload extends BaseHttpClientPayload
{
    public function __construct(
        protected string $requestId,
    ) {
    }

    protected function getCommand(): HttpClientCommandEnum
    {
        return HttpClientCommandEnum::UploadEnd;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return new UploadPayloadParameters(
            requestId: $this->requestId,
            body: '',
        );
    }
}
