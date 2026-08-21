<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The ConfirmWait command: wait until every message published on the channel since the
 * last wait has been confirmed or rejected by the broker, and return what arrived.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ConfirmWaitPayload extends BaseAmqpPayload
{
    public function __construct(
        protected ChannelPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::ConfirmWait;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
