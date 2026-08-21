<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * One delivered message: the body, the routing information the broker attached to it and
 * the basic properties it was published with. A value object — nothing here talks to the
 * broker.
 *
 * Deliveries are built by Support\DeliveredEnvelope, a subclass that fills the fields
 * from the map the Go side sends: the constructor of the extension's class takes no
 * arguments, and the calque keeps that public surface untouched.
 */
class AMQPEnvelope extends AMQPBasicProperties
{
    protected string $body = '';

    protected ?string $consumerTag = null;

    protected ?int $deliveryTag = null;

    protected bool $isRedelivery = false;

    protected ?string $exchangeName = null;

    protected string $routingKey = '';

    public function __construct()
    {
        parent::__construct();
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getRoutingKey(): string
    {
        return $this->routingKey;
    }

    public function getConsumerTag(): ?string
    {
        return $this->consumerTag;
    }

    public function getDeliveryTag(): ?int
    {
        return $this->deliveryTag;
    }

    public function getExchangeName(): ?string
    {
        return $this->exchangeName;
    }

    public function isRedelivery(): bool
    {
        return $this->isRedelivery;
    }

    /**
     * The value of one header, or null when the delivery carries no such header.
     */
    public function getHeader(string $headerName): mixed
    {
        return $this->headers[$headerName] ?? null;
    }

    public function hasHeader(string $headerName): bool
    {
        return array_key_exists($headerName, $this->headers);
    }
}
