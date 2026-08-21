<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The queue.delete command; the result carries the number of messages deleted with it.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class QueueDeletePayload extends BaseAmqpPayload
{
    public function __construct(
        protected QueueDeletePayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::QueueDelete;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
