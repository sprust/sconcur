<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a basic.publish command: where the message goes, the two publish flags
 * and the message itself — the body plus the basic properties map built by
 * Support\PropertiesCodec.
 *
 * Go: payloads.PublishParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class PublishPayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(
        protected string $channelId,
        protected string $exchangeName,
        protected string $routingKey,
        protected bool $mandatory,
        protected bool $immediate,
        protected string $body,
        protected array $properties,
        protected int $timeoutMs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return [
            'chid' => $this->channelId,
            'en'   => $this->exchangeName,
            'rk'   => $this->routingKey,
            'ma'   => $this->mandatory,
            'im'   => $this->immediate,
            'bd'   => $this->body,
            'ps'   => $this->properties,
            'to'   => $this->timeoutMs,
        ];
    }
}
