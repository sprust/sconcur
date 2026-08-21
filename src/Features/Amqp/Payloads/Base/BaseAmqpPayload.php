<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads\Base;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;
use SConcur\Transport\PayloadParametersInterface;

/**
 * Builds the command envelope (cm/p) every amqp payload sends: the AMQP method to run
 * plus its parameters. Mirrors Base\BaseWsClientPayload.
 *
 * Go: payloads.Envelope (ext/internal/features/amqp/payloads/payloads.go).
 */
abstract readonly class BaseAmqpPayload implements PayloadInterface
{
    abstract protected function getCommand(): AmqpCommandEnum;

    abstract protected function getParameters(): PayloadParametersInterface;

    public function getMethod(): MethodEnum
    {
        return MethodEnum::Amqp;
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
