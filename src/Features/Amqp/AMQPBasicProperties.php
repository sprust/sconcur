<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * The AMQP 0-9-1 basic properties of a message: everything that travels beside the body.
 * A value object — it never crosses the PHP ↔ Go boundary itself, the properties do, as a
 * plain map (Support\PropertiesCodec).
 *
 * AMQPEnvelope extends it with the fields only a delivered message has.
 */
class AMQPBasicProperties
{
    /**
     * @param array<string, mixed> $headers
     */
    public function __construct(
        protected ?string $contentType = null,
        protected ?string $contentEncoding = null,
        protected array $headers = [],
        protected int $deliveryMode = AMQP_DELIVERY_MODE_TRANSIENT,
        protected int $priority = 0,
        protected ?string $correlationId = null,
        protected ?string $replyTo = null,
        protected ?string $expiration = null,
        protected ?string $messageId = null,
        protected ?int $timestamp = null,
        protected ?string $type = null,
        protected ?string $userId = null,
        protected ?string $appId = null,
        protected ?string $clusterId = null,
    ) {
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function getContentEncoding(): ?string
    {
        return $this->contentEncoding;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getDeliveryMode(): int
    {
        return $this->deliveryMode;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

    public function getExpiration(): ?string
    {
        return $this->expiration;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getTimestamp(): ?int
    {
        return $this->timestamp;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getAppId(): ?string
    {
        return $this->appId;
    }

    /**
     * The cluster id of the delivery. Read-only in practice: AMQP 0-9-1 dropped the
     * property from publishing, so a message published through the calque never carries
     * one (see docs/amqp.md).
     */
    public function getClusterId(): ?string
    {
        return $this->clusterId;
    }
}
