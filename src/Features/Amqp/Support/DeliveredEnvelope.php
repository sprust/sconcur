<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Features\Amqp\AMQPEnvelope;

/**
 * An AMQPEnvelope filled from the delivery map the Go side sends. It exists as a subclass
 * because the extension's AMQPEnvelope takes no constructor arguments and the calque
 * keeps that surface untouched; `instanceof AMQPEnvelope` and every type hint on it hold
 * for these objects.
 */
class DeliveredEnvelope extends AMQPEnvelope
{
    /**
     * @param array<mixed> $delivery the decoded delivery: the body and routing fields
     *                               plus the basic properties under `ps`
     */
    public function __construct(array $delivery)
    {
        parent::__construct();

        /** @var array<mixed> $rawProperties */
        $rawProperties = is_array($delivery['ps'] ?? null) ? $delivery['ps'] : [];

        $properties = PropertiesCodec::decode($rawProperties);

        $this->body         = isset($delivery['bd']) ? (string) $delivery['bd'] : '';
        $this->routingKey   = isset($delivery['rk']) ? (string) $delivery['rk'] : '';
        $this->exchangeName = isset($delivery['en']) ? (string) $delivery['en'] : '';
        // A message pulled with basic.get belongs to no consumer, and ext-amqp reports
        // that as null rather than as an empty tag.
        $consumerTag = isset($delivery['tg']) ? (string) $delivery['tg'] : '';

        $this->consumerTag  = $consumerTag === '' ? null : $consumerTag;
        $this->deliveryTag  = isset($delivery['dt']) ? (int) $delivery['dt'] : null;
        $this->isRedelivery = (bool) ($delivery['rd'] ?? false);

        $this->contentType     = $properties->getContentType();
        $this->contentEncoding = $properties->getContentEncoding();
        $this->headers         = $properties->getHeaders();
        $this->deliveryMode    = $properties->getDeliveryMode();
        $this->priority        = $properties->getPriority();
        $this->correlationId   = $properties->getCorrelationId();
        $this->replyTo         = $properties->getReplyTo();
        $this->expiration      = $properties->getExpiration();
        $this->messageId       = $properties->getMessageId();
        $this->timestamp       = $properties->getTimestamp();
        $this->type            = $properties->getType();
        $this->userId          = $properties->getUserId();
        $this->appId           = $properties->getAppId();
        $this->clusterId       = $properties->getClusterId();
    }
}
