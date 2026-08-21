<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Payloads\Base\BaseAmqpPayload;
use SConcur\Transport\PayloadParametersInterface;

/**
 * The tx.select command: put the channel into transactional mode.
 *
 * Go: payloads.Envelope with the matching params struct
 * (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class TransactionSelectPayload extends BaseAmqpPayload
{
    public function __construct(
        protected ChannelPayloadParameters $parameters,
    ) {
    }

    protected function getCommand(): AmqpCommandEnum
    {
        return AmqpCommandEnum::TransactionSelect;
    }

    protected function getParameters(): PayloadParametersInterface
    {
        return $this->parameters;
    }
}
