<?php

declare(strict_types=1);

namespace SConcur\Features\SocketClient\Payloads\Base;

use SConcur\Features\MethodEnum;
use SConcur\Features\SocketClient\SocketClientCommandEnum;
use SConcur\Transport\PayloadInterface;
use SConcur\Transport\PayloadParametersInterface;

/**
 * Builds the command envelope (cm/p) every socket-client payload sends: the
 * sub-operation command plus its parameters. Mirrors Base\BaseHttpClientPayload.
 *
 * Go: payloads.Envelope (ext/internal/features/socketclient/payloads/payloads.go).
 */
abstract readonly class BaseSocketClientPayload implements PayloadInterface
{
    abstract protected function getCommand(): SocketClientCommandEnum;

    abstract protected function getParameters(): PayloadParametersInterface;

    public function getMethod(): MethodEnum
    {
        return MethodEnum::SocketClient;
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
