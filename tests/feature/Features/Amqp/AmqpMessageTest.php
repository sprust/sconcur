<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Exceptions\Amqp\ChannelException;
use SConcur\Exceptions\Amqp\InvalidPrefetchException;
use SConcur\Exceptions\Amqp\InvalidAmqpValueException;
use SConcur\Features\Amqp\Channel;
use SConcur\Features\Amqp\Decimal;
use SConcur\Features\Amqp\Message;
use SConcur\Features\Amqp\MessageProperties;
use SConcur\Features\Amqp\Timestamp;

/**
 * Publishing and receiving one message: the properties and headers it carries, and what
 * acknowledging, refusing and requeueing it does.
 */
class AmqpMessageTest extends AmqpTestCase
{
    public function testAMessageKeepsItsPropertiesAndHeaders(): void
    {
        $channel  = $this->channel();
        $exchange = $this->declareExchange($channel);
        $queue    = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $queue->bind(
            exchange: $exchange->name(),
            routingKey: 'key',
        );

        $exchange->publish(
            message: new Message(
                body: '{"id":1}',
                contentType: 'application/json',
                contentEncoding: 'utf-8',
                persistent: true,
                priority: 4,
                correlationId: 'correlation-1',
                replyTo: 'reply-queue',
                expiration: '60000',
                messageId: 'message-1',
                timestamp: 1_700_000_000,
                type: 'order.created',
                userId: (string) $_ENV['RABBITMQ_USER'],
                appId: 'tests',
                headers: [
                    'x-attempt' => 3,
                    'x-source'  => 'unit',
                    'x-nested'  => ['a' => 1],
                ],
            ),
            routingKey: 'key',
        );

        $delivery = $this->waitForMessage($queue);

        self::assertNotNull($delivery);
        self::assertSame('{"id":1}', $delivery->body);
        self::assertSame('key', $delivery->routingKey);
        self::assertFalse($delivery->redelivered);
        // A message pulled with basic.get belongs to no consumer, and reports an empty tag.
        self::assertSame('', $delivery->consumerTag);

        $properties = $delivery->properties;

        self::assertSame('application/json', $properties->contentType);
        self::assertSame('utf-8', $properties->contentEncoding);
        self::assertTrue($properties->isPersistent());
        self::assertSame(MessageProperties::DELIVERY_MODE_PERSISTENT, $properties->deliveryMode);
        self::assertSame(4, $properties->priority);
        self::assertSame('correlation-1', $properties->correlationId);
        self::assertSame('reply-queue', $properties->replyTo);
        self::assertSame('60000', $properties->expiration);
        self::assertSame('message-1', $properties->messageId);
        self::assertSame(1_700_000_000, $properties->timestamp);
        self::assertSame('order.created', $properties->type);
        self::assertSame($_ENV['RABBITMQ_USER'], $properties->userId);
        self::assertSame('tests', $properties->appId);

        self::assertTrue($delivery->hasHeader('x-attempt'));
        self::assertSame(3, $delivery->header('x-attempt'));
        self::assertSame('unit', $delivery->header('x-source'));
        self::assertSame(['a' => 1], $delivery->header('x-nested'));
        self::assertFalse($delivery->hasHeader('x-missing'));
        self::assertNull($delivery->header('x-missing'));

        $delivery->ack();
    }

    /**
     * ext-amqp publishes text/plain for a message that names no content type, and the
     * calque copied that. A property nobody set is now simply absent, which is what AMQP
     * means by one.
     */
    public function testAMessageWithNoContentTypeCarriesNone(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue(
            channel: $channel,
            queueName: $queue->name(),
            message: 'plain',
        );

        $delivery = $this->waitForMessage($queue);

        self::assertNotNull($delivery);
        self::assertNull($delivery->properties->contentType);

        $delivery->ack();
    }

    /**
     * A delivery carries everything a publisher would have to build, so re-publishing one
     * is a call and not a translation. This is the retry and the hand-rolled dead-letter
     * hop, which the calque made the caller assemble property by property.
     */
    public function testADeliveryCanBePublishedAgainAsItArrived(): void
    {
        $channel = $this->channel();
        $source  = $this->declareQueue(
            channel: $channel,
            durable: true,
        );
        $target = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $source->publish(new Message(
            body: 'retry me',
            contentType: 'application/json',
            persistent: true,
            correlationId: 'correlation-1',
            headers: ['x-attempt' => 1],
        ));

        $delivery = $this->waitForMessage($source);

        self::assertNotNull($delivery);

        $target->publish(Message::fromDelivery($delivery));

        $delivery->ack();

        $moved = $this->waitForMessage($target);

        self::assertNotNull($moved);
        self::assertSame('retry me', $moved->body);
        self::assertSame('application/json', $moved->properties->contentType);
        self::assertTrue($moved->properties->isPersistent());
        self::assertSame('correlation-1', $moved->properties->correlationId);
        self::assertSame(1, $moved->header('x-attempt'));

        $moved->ack();
    }

    public function testHeaderValueObjectsKeepTheirAmqpFieldKind(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $queue->publish(new Message(
            body: 'values',
            headers: [
                'x-decimal'   => new Decimal(exponent: 2, significand: 314),
                'x-timestamp' => new Timestamp(1_700_000_000.0),
            ],
        ));

        $delivery = $this->waitForMessage($queue);

        self::assertNotNull($delivery);

        // A decimal and a timestamp have field kinds of their own in AMQP 0-9-1, so they
        // come back as the objects they were sent as — not as a float and an integer.
        $decimal = $delivery->header('x-decimal');

        self::assertInstanceOf(Decimal::class, $decimal);
        self::assertSame(2, $decimal->exponent);
        self::assertSame(314, $decimal->significand);

        $timestamp = $delivery->header('x-timestamp');

        self::assertInstanceOf(Timestamp::class, $timestamp);
        self::assertSame(1_700_000_000.0, $timestamp->seconds);

        $delivery->ack();
    }

    public function testANestedListKeepsItsValues(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $queue->publish(new Message(
            body: 'nested',
            headers: [
                'x-tags'  => ['first', 'second'],
                'x-mixed' => [
                    0   => 'zero',
                    'k' => 'value',
                ],
            ],
        ));

        $delivery = $this->waitForMessage($queue);

        self::assertNotNull($delivery);
        // A header carrying a list is an AMQP field array, not a table with dropped keys.
        self::assertSame(['first', 'second'], $delivery->header('x-tags'));

        $mixed = $delivery->header('x-mixed');

        // A field table has no order, and the two sides are free to hand it over in a
        // different one; the keys and values are what must survive.
        ksort($mixed);

        self::assertSame(
            [
                0   => 'zero',
                'k' => 'value',
            ],
            $mixed,
        );

        $delivery->ack();
    }

    public function testAValueThatNestsTooDeepIsRefused(): void
    {
        $queue = $this->declareQueue($this->channel());

        $deep = 'value';

        for ($level = 0; $level < 200; ++$level) {
            $deep = ['nested' => $deep];
        }

        $this->expectException(InvalidAmqpValueException::class);
        $this->expectExceptionMessage('Maximum serialization depth of 128 reached while serializing value');

        $queue->publish(new Message(body: 'too deep', headers: ['deep' => $deep]));
    }

    public function testADeliveryWithNoTimestampReportsNone(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue(
            channel: $channel,
            queueName: $queue->name(),
            message: 'no timestamp',
        );

        $delivery = $this->waitForMessage($queue);

        self::assertNotNull($delivery);
        // The extension reports 0 here for backwards compatibility, which is a date rather
        // than an absence; a property nobody set is null.
        self::assertNull($delivery->properties->timestamp);

        $delivery->ack();
    }

    public function testANonStringHeaderKeyIsDroppedWithAWarning(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $warnings = [];

        set_error_handler(
            static function (int $level, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            },
            E_USER_WARNING,
        );

        /** @var array<string, mixed> $headers a key the protocol cannot carry, and one it can */
        $headers = [
            '5'      => 'five',
            'x-name' => 'kept',
        ];

        try {
            $queue->publish(new Message(body: 'kept', headers: $headers));
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $warnings);
        self::assertStringContainsString("Ignoring non-string header field '5'", $warnings[0]);

        // The message itself went out with the headers that could travel: a key the
        // protocol cannot carry costs that header, not the publish.
        $delivery = $this->waitForMessage($queue);

        self::assertNotNull($delivery);
        self::assertSame('kept', $delivery->body);
        self::assertSame('kept', $delivery->header('x-name'));
        self::assertFalse($delivery->hasHeader('5'));

        $delivery->ack();
    }

    public function testAnAcknowledgedMessageIsGoneAndAnUnacknowledgedOneComesBack(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue(
            channel: $channel,
            queueName: $queue->name(),
            message: 'first',
        );

        $delivery = $this->waitForMessage($queue);

        self::assertNotNull($delivery);

        $delivery->ack();

        $this->assertQueueStaysEmpty($queue);

        $this->publishToQueue(
            channel: $channel,
            queueName: $queue->name(),
            message: 'second',
        );

        $delivery = $this->waitForMessage($queue);

        self::assertNotNull($delivery);

        $delivery->nack(requeue: true);

        $requeued = $this->waitForMessage($queue);

        self::assertNotNull($requeued);
        self::assertSame('second', $requeued->body);
        self::assertTrue($requeued->redelivered);

        $requeued->ack();
    }

    public function testARefusedMessageWithoutRequeueIsDropped(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue(
            channel: $channel,
            queueName: $queue->name(),
            message: 'rejected',
        );

        $delivery = $this->waitForMessage($queue);

        self::assertNotNull($delivery);

        $delivery->reject();

        $this->assertQueueStaysEmpty($queue);
    }

    /**
     * The broker answers a second acknowledgement of the same tag by closing the channel,
     * which takes every other consumer on it down. A delivery knows it has been settled,
     * so the second call never reaches the wire.
     */
    public function testSettlingADeliveryTwiceIsRefusedBeforeItReachesTheBroker(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue(
            channel: $channel,
            queueName: $queue->name(),
            message: 'once',
        );

        $delivery = $this->waitForMessage($queue);

        self::assertNotNull($delivery);
        self::assertFalse($delivery->isSettled());

        $delivery->ack();

        self::assertTrue($delivery->isSettled());

        try {
            $delivery->nack();

            self::fail('settling a delivery twice must be refused');
        } catch (ChannelException $exception) {
            self::assertStringContainsString('already been settled', $exception->getMessage());
        }

        // The channel is untouched, which is the whole point of refusing locally.
        self::assertTrue($channel->isOpen());
        self::assertNull($queue->get());
    }

    public function testAutoAckLeavesNothingToAcknowledge(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue(
            channel: $channel,
            queueName: $queue->name(),
            message: 'auto',
        );

        $delivery = null;
        $deadline = microtime(true) + 2;

        do {
            $delivery = $queue->get(autoAck: true);

            if ($delivery !== null) {
                break;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::assertNotNull($delivery);
        self::assertSame('auto', $delivery->body);

        // Counted after the channel is gone: a message that was delivered but not
        // acknowledged goes back into the queue when the channel closes, so a count taken
        // here is what proves the broker really considers it acknowledged.
        self::assertSame(0, $this->countAfterTheChannelIsGone($channel, $queue->name()));
    }

    /**
     * An auto-acknowledged delivery was settled by the broker as it left, so acknowledging
     * it again is the double settle the guard exists to prevent — and the one that would
     * actually reach the wire, because nothing on this side had recorded the first.
     */
    public function testAnAutoAcknowledgedDeliveryArrivesSettled(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue(
            channel: $channel,
            queueName: $queue->name(),
            message: 'auto',
        );

        $delivery = null;
        $deadline = microtime(true) + 2;

        do {
            $delivery = $queue->get(autoAck: true);

            if ($delivery !== null) {
                break;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::assertNotNull($delivery);
        self::assertTrue($delivery->isSettled(), 'the broker already answered for it');

        try {
            $delivery->ack();

            self::fail('an auto-acknowledged delivery must not be acknowledged again');
        } catch (ChannelException $exception) {
            self::assertStringContainsString('already been settled', $exception->getMessage());
        }

        // Refused locally, so the channel the other consumers share is untouched.
        self::assertTrue($channel->isOpen());
    }

    public function testAcknowledgingSeveralMessagesAtOnce(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        foreach (['one', 'two', 'three'] as $body) {
            $this->publishToQueue(
                channel: $channel,
                queueName: $queue->name(),
                message: $body,
            );
        }

        $last = null;

        for ($index = 0; $index < 3; ++$index) {
            $last = $this->waitForMessage($queue);

            self::assertNotNull($last);
        }

        $last->ack(multiple: true);

        // Same reasoning as above: only a count taken after the channel is closed tells an
        // acknowledged message from one that is merely held.
        self::assertSame(0, $this->countAfterTheChannelIsGone($channel, $queue->name()));
    }

    public function testAPrefetchOutsideTheProtocolRangeIsRefused(): void
    {
        $channel = $this->channel();

        $this->expectException(InvalidPrefetchException::class);
        $this->expectExceptionMessage("Parameter 'prefetchCount' must be between 0 and 65535.");

        $channel->prefetch(count: 70_000);
    }

    /**
     * A decimal significand at or above 2^31 has its high bit set. Read back through a
     * signed conversion it would come out negative, Decimal would refuse it, and the
     * exception would escape while the delivery was being built — killing the consumer and
     * leaving the message to be redelivered forever.
     */
    public function testADecimalHeaderAboveTheSignedRangeSurvivesTheRoundTrip(): void
    {
        $queue = $this->declareQueue($this->channel());

        $queue->publish(new Message(
            body: 'decimal',
            headers: ['big' => new Decimal(exponent: 2, significand: 3_000_000_000)],
        ));

        $delivery = $this->waitForMessage($queue);

        self::assertNotNull($delivery);

        $header = $delivery->header('big');

        self::assertInstanceOf(Decimal::class, $header);
        self::assertSame(3_000_000_000, $header->significand);
        self::assertSame(2, $header->exponent);
    }

    /**
     * The whole unsigned 64-bit range a Timestamp accepts does not fit a PHP int, and a
     * cast would wrap it into a date before 1970. The limit is stated instead.
     */
    public function testATimestampBeyondWhatCanBeSentIsRefused(): void
    {
        $queue = $this->declareQueue($this->channel());

        $this->expectException(InvalidAmqpValueException::class);
        $this->expectExceptionMessage('cannot be sent');

        $queue->publish(new Message(body: 'timestamp', headers: ['when' => new Timestamp(1.0e19)]));
    }

    /**
     * The exact edge of that limit: 2^63 is the first second a PHP int cannot hold, and it
     * is also what (float) PHP_INT_MAX rounds up to — so a `> PHP_INT_MAX` test let it
     * through and the cast wrapped it to PHP_INT_MIN, putting a date in 1754 on the wire.
     */
    public function testATimestampExactlyAtTheIntegerEdgeIsRefused(): void
    {
        $queue = $this->declareQueue($this->channel());

        $this->expectException(InvalidAmqpValueException::class);
        $this->expectExceptionMessage('cannot be sent');

        $queue->publish(new Message(
            body: 'timestamp',
            headers: ['when' => new Timestamp(9223372036854775808.0)],
        ));
    }

    /**
     * Closes the channel and counts what is left in the queue on a fresh one — everything
     * the closed channel held unacknowledged comes back first.
     */
    private function countAfterTheChannelIsGone(Channel $channel, string $queueName): int
    {
        $channel->close();

        // Requeueing is not instant, so a count of 0 is only trusted after the broker has
        // had a moment to put anything held back.
        usleep(200_000);

        return $this->channel()->queue($queueName)->declarePassive()->messageCount;
    }
}
