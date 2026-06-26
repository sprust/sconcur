<?php

declare(strict_types=1);

namespace SConcur\Features\WsClient\Payloads\Base;

use SConcur\Features\MethodEnum;
use SConcur\Features\WsClient\WsClientCommandEnum;
use SConcur\Transport\PayloadInterface;
use SConcur\Transport\PayloadParametersInterface;

/**
 * Builds the command envelope (cm/p) every ws-client payload sends: the sub-operation
 * command plus its parameters. Mirrors Base\BaseSocketClientPayload.
 *
 * Go: payloads.Envelope (ext/internal/features/wsclient/payloads/payloads.go).
 */
abstract readonly class BaseWsClientPayload implements PayloadInterface
{
    abstract protected function getCommand(): WsClientCommandEnum;

    abstract protected function getParameters(): PayloadParametersInterface;

    public function getMethod(): MethodEnum
    {
        return MethodEnum::WsClient;
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
