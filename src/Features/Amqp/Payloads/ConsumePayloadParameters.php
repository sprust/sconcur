<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a basic.consume command — the streaming one. readTimeoutMs bounds the
 * wait for the next delivery (0 = wait indefinitely), the execution bound of a
 * long-lived consumer. AMQP_JUST_CONSUME never reaches Go: it means "read the consumer
 * this queue already opened", which AMQPQueue answers from the stream key it kept.
 *
 * Go: payloads.ConsumeParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ConsumePayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        protected string $channelId,
        protected string $queueName,
        protected string $consumerTag,
        protected bool $autoAck,
        protected bool $exclusive,
        protected bool $noLocal,
        protected bool $noWait,
        protected array $arguments,
        protected int $readTimeoutMs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return [
            'chid' => $this->channelId,
            'na'   => $this->queueName,
            'tg'   => $this->consumerTag,
            'aa'   => $this->autoAck,
            'ex'   => $this->exclusive,
            'nl'   => $this->noLocal,
            'nw'   => $this->noWait,
            'ar'   => $this->arguments,
            'rt'   => $this->readTimeoutMs,
        ];
    }
}
