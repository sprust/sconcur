<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The queue.purge command; the result carries the number of messages removed.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class QueuePurgePayload extends BaseAmqpPayload
{
    public function __construct(
        protected QueuePurgePayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::QueuePurge;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
