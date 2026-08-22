<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * A message on its way to the broker: the body and the properties that travel with it.
 *
 * The properties are named constructor arguments rather than a string-keyed array, so a
 * misspelled one is a TypeError at the call site instead of a property the broker never
 * receives. `persistent` stands in for the delivery mode: asking for a message to survive
 * a broker restart is the common case, and it should not require knowing that the number
 * for it is two.
 *
 * Publishing a plain string is enough where no properties are needed —
 * `Channel::publish()` takes either.
 */
readonly class Message
{
    /**
     * @param bool                 $persistent whether a durable queue should write the
     *                                         message to disk before acknowledging it
     * @param ?int                 $priority   0..9 on a queue declared with `x-max-priority`
     * @param ?string              $expiration how long the message stays routable, in
     *                                         milliseconds, as a decimal string
     * @param ?int                 $timestamp  seconds since the Unix epoch
     * @param array<string, mixed> $headers    the field table; values may be scalars,
     *                                         nested tables, Decimal or Timestamp
     */
    public function __construct(
        public string $body,
        public ?string $contentType = null,
        public ?string $contentEncoding = null,
        public bool $persistent = false,
        public ?int $priority = null,
        public ?string $correlationId = null,
        public ?string $replyTo = null,
        public ?string $expiration = null,
        public ?string $messageId = null,
        public ?int $timestamp = null,
        public ?string $type = null,
        public ?string $userId = null,
        public ?string $appId = null,
        public array $headers = [],
    ) {
    }

    /**
     * The message a delivery carried, ready to be published again — a retry that puts the
     * message on another exchange, or a dead-letter hop an application does itself. The
     * delivery's own routing fields are deliberately not carried over: where it goes next
     * is the publisher's decision, not the broker's.
     */
    public static function fromDelivery(Delivery $delivery): self
    {
        $properties = $delivery->properties;

        return new self(
            body: $delivery->body,
            contentType: $properties->contentType,
            contentEncoding: $properties->contentEncoding,
            persistent: $properties->isPersistent(),
            priority: $properties->priority,
            correlationId: $properties->correlationId,
            replyTo: $properties->replyTo,
            expiration: $properties->expiration,
            messageId: $properties->messageId,
            timestamp: $properties->timestamp,
            type: $properties->type,
            userId: $properties->userId,
            appId: $properties->appId,
            headers: $properties->headers,
        );
    }
}
