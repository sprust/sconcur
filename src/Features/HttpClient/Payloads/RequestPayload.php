<?php

declare(strict_types=1);

namespace SConcur\Features\HttpClient\Payloads;

use SConcur\Features\HttpClient\HttpClientCommandEnum;
use SConcur\Features\HttpClient\Payloads\Base\BaseHttpClientPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The Request command: open one HTTP request (buffered body, or the start of a
 * streamed-body upload when the parameters carry streamBody).
 *
 * Go: payloads.RequestParams (ext/internal/features/httpclient/payloads/payloads.go).
 */
readonly class RequestPayload extends BaseHttpClientPayload
{
    public function __construct(
        protected RequestPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): HttpClientCommandEnum
    {
        return HttpClientCommandEnum::Request;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
