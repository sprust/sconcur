<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The queue.unbind command.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class QueueUnbindPayload extends BaseAmqpPayload
{
    public function __construct(
        protected QueueBindPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::QueueUnbind;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
