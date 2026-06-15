<?php

declare(strict_types=1);

namespace SConcur\Features\HttpClient\Payloads\Base;

use SConcur\Features\HttpClient\HttpClientCommandEnum;
use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;
use SConcur\Transport\PayloadParametersInterface;

/**
 * Builds the command envelope (cm/p) every HTTP-client payload sends: the
 * sub-operation command plus its parameters. Mirrors Base\BaseMongodbPayload.
 *
 * Go: payloads.Envelope (ext/internal/features/httpclient/payloads/payloads.go).
 */
abstract readonly class BaseHttpClientPayload implements PayloadInterface
{
    abstract protected function getCommand(): HttpClientCommandEnum;

    abstract protected function getParameters(): PayloadParametersInterface;

    public function getMethod(): MethodEnum
    {
        return MethodEnum::HttpClient;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return [
            'cm' => $this->getCommand()->value,
            'p'  => $this->getParameters()->getData(),
        ];
    }
}
