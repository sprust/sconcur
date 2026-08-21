<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPDecimal;
use SConcur\Features\Amqp\AMQPException;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Features\Amqp\AMQPTimestamp;
use const SConcur\Features\Amqp\AMQP_AUTOACK;
use const SConcur\Features\Amqp\AMQP_DELIVERY_MODE_PERSISTENT;
use const SConcur\Features\Amqp\AMQP_DURABLE;
use const SConcur\Features\Amqp\AMQP_MULTIPLE;
use const SConcur\Features\Amqp\AMQP_REQUEUE;

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
        $queue    = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $queue->bind(exchangeName: (string) $exchange->getName(), routingKey: 'key');

        $exchange->publish(
            message: '{"id":1}',
            routingKey: 'key',
            headers: [
                'content_type'     => 'application/json',
                'content_encoding' => 'utf-8',
                'delivery_mode'    => AMQP_DELIVERY_MODE_PERSISTENT,
                'priority'         => 4,
                'correlation_id'   => 'correlation-1',
                'reply_to'         => 'reply-queue',
                'expiration'       => '60000',
                'message_id'       => 'message-1',
                'timestamp'        => 1_700_000_000,
                'type'             => 'order.created',
                'user_id'          => $_ENV['RABBITMQ_USER'],
                'app_id'           => 'tests',
                'headers'          => [
                    'x-attempt' => 3,
                    'x-source'  => 'unit',
                    'x-nested'  => ['a' => 1],
                ],
            ],
        );

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);
        self::assertSame('{"id":1}', $envelope->getBody());
        self::assertSame('key', $envelope->getRoutingKey());
        self::assertSame('application/json', $envelope->getContentType());
        self::assertSame('utf-8', $envelope->getContentEncoding());
        self::assertSame(AMQP_DELIVERY_MODE_PERSISTENT, $envelope->getDeliveryMode());
        self::assertSame(4, $envelope->getPriority());
        self::assertSame('correlation-1', $envelope->getCorrelationId());
        self::assertSame('reply-queue', $envelope->getReplyTo());
        self::assertSame('60000', $envelope->getExpiration());
        self::assertSame('message-1', $envelope->getMessageId());
        self::assertSame(1_700_000_000, $envelope->getTimestamp());
        self::assertSame('order.created', $envelope->getType());
        self::assertSame($_ENV['RABBITMQ_USER'], $envelope->getUserId());
        self::assertSame('tests', $envelope->getAppId());
        self::assertFalse($envelope->isRedelivery());
        // A message pulled with basic.get belongs to no consumer, and the extension
        // reports that as an empty tag.
        self::assertSame('', $envelope->getConsumerTag());

        self::assertTrue($envelope->hasHeader('x-attempt'));
        self::assertSame(3, $envelope->getHeader('x-attempt'));
        self::assertSame('unit', $envelope->getHeader('x-source'));
        self::assertSame(['a' => 1], $envelope->getHeader('x-nested'));
        self::assertFalse($envelope->hasHeader('x-missing'));
        self::assertNull($envelope->getHeader('x-missing'));

        $queue->ack($envelope->getDeliveryTag());
    }

    public function testAMessageWithNoContentTypeIsPublishedAsTextPlain(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'plain');

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);
        // ext-amqp publishes text/plain when the caller names no content type; the calque
        // keeps that default.
        self::assertSame('text/plain', $envelope->getContentType());

        $queue->ack($envelope->getDeliveryTag());
    }

    public function testHeaderValueObjectsKeepTheirAmqpFieldKind(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $exchange = $this->declareExchange($channel);

        $queue->bind(exchangeName: (string) $exchange->getName(), routingKey: 'key');

        $exchange->publish(
            message: 'values',
            routingKey: 'key',
            headers: [
                'headers' => [
                    'x-decimal'   => new AMQPDecimal(exponent: 2, significand: 314),
                    'x-timestamp' => new AMQPTimestamp(1_700_000_000.0),
                ],
            ],
        );

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);

        // A decimal and a timestamp have field kinds of their own in AMQP 0-9-1, so they
        // come back as the objects they were sent as — not as a float and an integer.
        $decimal = $envelope->getHeader('x-decimal');

        self::assertInstanceOf(AMQPDecimal::class, $decimal);
        self::assertSame(2, $decimal->getExponent());
        self::assertSame(314, $decimal->getSignificand());

        $timestamp = $envelope->getHeader('x-timestamp');

        self::assertInstanceOf(AMQPTimestamp::class, $timestamp);
        self::assertSame(1_700_000_000.0, $timestamp->getTimestamp());

        $queue->ack($envelope->getDeliveryTag());
    }

    public function testANestedListKeepsItsValues(): void
    {
        $channel  = $this->channel();
        $exchange = $this->declareExchange($channel);
        $queue    = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $queue->bind(exchangeName: (string) $exchange->getName(), routingKey: 'key');

        $exchange->publish(
            message: 'nested',
            routingKey: 'key',
            headers: [
                'headers' => [
                    'x-tags'  => ['first', 'second'],
                    'x-mixed' => [
                        0   => 'zero',
                        'k' => 'value',
                    ],
                ],
            ],
        );

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);
        // A header carrying a list is an AMQP field array, not a table with dropped keys.
        self::assertSame(['first', 'second'], $envelope->getHeader('x-tags'));
        $mixed = $envelope->getHeader('x-mixed');

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

        $queue->ack($envelope->getDeliveryTag());
    }

    public function testAValueThatNestsTooDeepIsRefused(): void
    {
        $exchange = $this->declareExchange($this->channel());

        $deep = 'value';

        for ($level = 0; $level < 200; ++$level) {
            $deep = ['nested' => $deep];
        }

        $this->expectException(AMQPException::class);
        $this->expectExceptionMessage('Maximum serialization depth of 128 reached while serializing value');

        $exchange->publish(message: 'too deep', routingKey: 'key', headers: ['headers' => $deep]);
    }

    public function testADeliveryWithNoTimestampReportsZero(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'no timestamp');

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);
        // The extension keeps 0 here for backwards compatibility, so date() on it works.
        self::assertSame(0, $envelope->getTimestamp());

        $queue->ack($envelope->getDeliveryTag());
    }

    public function testANonStringHeaderKeyIsDroppedWithAWarning(): void
    {
        $channel  = $this->channel();
        $exchange = $this->declareExchange($channel);
        $queue    = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $queue->bind(exchangeName: (string) $exchange->getName(), routingKey: 'key');

        $warnings = [];

        set_error_handler(
            static function (int $level, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            },
            E_USER_WARNING,
        );

        try {
            $exchange->publish(
                message: 'kept',
                routingKey: 'key',
                headers: [
                    'headers' => [
                        '5'      => 'five',
                        'x-name' => 'kept',
                    ],
                ],
            );
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $warnings);
        self::assertStringContainsString("Ignoring non-string header field '5'", $warnings[0]);

        // The message itself went out with the headers that could travel: a key the
        // protocol cannot carry costs that header, not the publish.
        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);
        self::assertSame('kept', $envelope->getBody());
        self::assertSame('kept', $envelope->getHeader('x-name'));
        self::assertFalse($envelope->hasHeader('5'));

        $queue->ack($envelope->getDeliveryTag());
    }

    public function testAnAcknowledgedMessageIsGoneAndAnUnacknowledgedOneComesBack(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'first');

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);

        $queue->ack($envelope->getDeliveryTag());

        $this->assertQueueStaysEmpty($queue);

        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'second');

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);

        $queue->nack(deliveryTag: $envelope->getDeliveryTag(), flags: AMQP_REQUEUE);

        $requeued = $this->waitForMessage($queue);

        self::assertNotNull($requeued);
        self::assertSame('second', $requeued->getBody());
        self::assertTrue($requeued->isRedelivery());

        $queue->ack($requeued->getDeliveryTag());
    }

    public function testARefusedMessageWithoutRequeueIsDropped(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'rejected');

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);

        $queue->reject($envelope->getDeliveryTag());

        $this->assertQueueStaysEmpty($queue);
    }

    public function testAutoAckLeavesNothingToAcknowledge(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'auto');

        $envelope = null;
        $deadline = microtime(true) + 2;

        do {
            $envelope = $queue->get(flags: AMQP_AUTOACK);

            if ($envelope !== null) {
                break;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::assertNotNull($envelope);
        self::assertSame('auto', $envelope->getBody());

        // Counted after the channel is gone: a message that was delivered but not
        // acknowledged goes back into the queue when the channel closes, so a count taken
        // here is what proves the broker really considers it acknowledged.
        self::assertSame(0, $this->countAfterTheChannelIsGone($channel, (string) $queue->getName()));
    }

    public function testAcknowledgingSeveralMessagesAtOnce(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        foreach (['one', 'two', 'three'] as $body) {
            $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: $body);
        }

        $lastDeliveryTag = 0;

        for ($index = 0; $index < 3; ++$index) {
            $envelope = $this->waitForMessage($queue);

            self::assertNotNull($envelope);

            $lastDeliveryTag = (int) $envelope->getDeliveryTag();
        }

        $queue->ack(deliveryTag: $lastDeliveryTag, flags: AMQP_MULTIPLE);

        // Same reasoning as above: only a count taken after the channel is closed tells an
        // acknowledged message from one that is merely held.
        self::assertSame(0, $this->countAfterTheChannelIsGone($channel, (string) $queue->getName()));
    }

    public function testTheChannelReportsItsPrefetchSettings(): void
    {
        $channel = $this->channel();

        // The value ext-amqp gives a fresh channel.
        self::assertSame(3, $channel->getPrefetchCount());

        $channel->setPrefetchCount(10);

        self::assertSame(10, $channel->getPrefetchCount());
        self::assertSame(0, $channel->getPrefetchSize());

        $channel->qos(size: 0, count: 5);

        self::assertSame(5, $channel->getPrefetchCount());

        $channel->setGlobalPrefetchCount(7);

        self::assertSame(7, $channel->getGlobalPrefetchCount());
        self::assertSame(0, $channel->getGlobalPrefetchSize());
    }

    public function testRecoverAsksForTheUnacknowledgedMessagesAgain(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'recovered');

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);

        $queue->recover();

        $again = $this->waitForMessage($queue);

        self::assertNotNull($again);
        self::assertSame('recovered', $again->getBody());
        self::assertTrue($again->isRedelivery());

        $queue->ack($again->getDeliveryTag());
    }

    /**
     * Closes the channel and counts what is left in the queue on a fresh one — everything
     * the closed channel held unacknowledged comes back first.
     */
    private function countAfterTheChannelIsGone(AMQPChannel $channel, string $queueName): int
    {
        $channel->close();

        $queue = new AMQPQueue($this->channel());

        $queue->setName($queueName);
        $queue->setFlags(AMQP_DURABLE);

        // Requeueing is not instant, so a count of 0 is only trusted after the broker has
        // had a moment to put anything held back.
        usleep(200_000);

        return $queue->declareQueue();
    }
}
