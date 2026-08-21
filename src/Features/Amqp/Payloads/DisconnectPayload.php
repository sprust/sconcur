<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The Disconnect command: release the connection handle. The underlying connection stays
 * in the pool until it has no owners left and its idle time runs out.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class DisconnectPayload extends BaseAmqpPayload
{
    public function __construct(
        protected ConnectionPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::Disconnect;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
