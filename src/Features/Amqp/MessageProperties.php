<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * The AMQP basic properties a delivered message carries. Everything a publisher may set
 * on a Message arrives here, plus `clusterId`, which only a broker sets.
 *
 * A property nobody set is null rather than an empty string: AMQP distinguishes "no
 * content type" from "a content type that is the empty string", and so does this.
 */
readonly class MessageProperties
{
    /** The delivery mode of a message the broker is not asked to persist. */
    public const int DELIVERY_MODE_TRANSIENT = 1;

    /** The delivery mode of a message a durable queue writes to disk. */
    public const int DELIVERY_MODE_PERSISTENT = 2;

    /**
     * @param array<string, mixed> $headers the message's field table
     */
    public function __construct(
        public ?string $contentType = null,
        public ?string $contentEncoding = null,
        public int $deliveryMode = self::DELIVERY_MODE_TRANSIENT,
        public ?int $priority = null,
        public ?string $correlationId = null,
        public ?string $replyTo = null,
        public ?string $expiration = null,
        public ?string $messageId = null,
        public ?int $timestamp = null,
        public ?string $type = null,
        public ?string $userId = null,
        public ?string $appId = null,
        public ?string $clusterId = null,
        public array $headers = [],
    ) {
    }

    /** Whether the broker was asked to write this message to disk on a durable queue. */
    public function isPersistent(): bool
    {
        return $this->deliveryMode === self::DELIVERY_MODE_PERSISTENT;
    }

    public function header(string $name): mixed
    {
        return $this->headers[$name] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return array_key_exists($name, $this->headers);
    }
}
