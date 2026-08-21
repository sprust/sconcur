<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The queue.declare command (declare-passive when the passive parameter is set); the
 * result carries the queue name and its message count.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class QueueDeclarePayload extends BaseAmqpPayload
{
    public function __construct(
        protected QueueDeclarePayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::QueueDeclare;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
