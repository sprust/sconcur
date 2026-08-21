<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Features\Amqp\AMQPDecimal;
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
        $queue    = $this->declareQueue($channel, AMQP_DURABLE);

        $queue->bind((string) $exchange->getName(), 'key');

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
        self::assertNull($envelope->getConsumerTag());

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
        $queue   = $this->declareQueue($channel, AMQP_DURABLE);

        $this->publishToQueue($channel, (string) $queue->getName(), 'plain');

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);
        // ext-amqp publishes text/plain when the caller names no content type; the calque
        // keeps that default.
        self::assertSame('text/plain', $envelope->getContentType());

        $queue->ack($envelope->getDeliveryTag());
    }

    public function testHeaderValueObjectsTravelAsScalars(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue($channel, AMQP_DURABLE);

        $exchange = $this->declareExchange($channel);

        $queue->bind((string) $exchange->getName(), 'key');

        $exchange->publish(
            message: 'values',
            routingKey: 'key',
            headers: [
                'headers' => [
                    'x-decimal'   => new AMQPDecimal(2, 314),
                    'x-timestamp' => new AMQPTimestamp(1_700_000_000.0),
                ],
            ],
        );

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);
        self::assertSame(3.14, $envelope->getHeader('x-decimal'));
        self::assertSame(1_700_000_000, $envelope->getHeader('x-timestamp'));

        $queue->ack($envelope->getDeliveryTag());
    }

    public function testAnAcknowledgedMessageIsGoneAndAnUnacknowledgedOneComesBack(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue($channel, AMQP_DURABLE);

        $this->publishToQueue($channel, (string) $queue->getName(), 'first');

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);

        $queue->ack($envelope->getDeliveryTag());

        self::assertNull($queue->get());

        $this->publishToQueue($channel, (string) $queue->getName(), 'second');

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);

        $queue->nack($envelope->getDeliveryTag(), AMQP_REQUEUE);

        $requeued = $this->waitForMessage($queue);

        self::assertNotNull($requeued);
        self::assertSame('second', $requeued->getBody());
        self::assertTrue($requeued->isRedelivery());

        $queue->ack($requeued->getDeliveryTag());
    }

    public function testARefusedMessageWithoutRequeueIsDropped(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue($channel, AMQP_DURABLE);

        $this->publishToQueue($channel, (string) $queue->getName(), 'rejected');

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);

        $queue->reject($envelope->getDeliveryTag());

        self::assertNull($queue->get());
    }

    public function testAutoAckLeavesNothingToAcknowledge(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue($channel, AMQP_DURABLE);

        $this->publishToQueue($channel, (string) $queue->getName(), 'auto');

        $envelope = null;
        $deadline = microtime(true) + 2;

        do {
            $envelope = $queue->get(AMQP_AUTOACK);

            if ($envelope !== null) {
                break;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::assertNotNull($envelope);
        self::assertSame('auto', $envelope->getBody());

        // Nothing is left outstanding: the message was acknowledged as it was delivered,
        // so re-declaring the queue reports it empty.
        self::assertSame(0, $queue->declareQueue());
    }

    public function testAcknowledgingSeveralMessagesAtOnce(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue($channel, AMQP_DURABLE);

        foreach (['one', 'two', 'three'] as $body) {
            $this->publishToQueue($channel, (string) $queue->getName(), $body);
        }

        $lastDeliveryTag = 0;

        for ($index = 0; $index < 3; ++$index) {
            $envelope = $this->waitForMessage($queue);

            self::assertNotNull($envelope);

            $lastDeliveryTag = (int) $envelope->getDeliveryTag();
        }

        $queue->ack($lastDeliveryTag, AMQP_MULTIPLE);

        self::assertSame(0, $queue->declareQueue());
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
        $queue   = $this->declareQueue($channel, AMQP_DURABLE);

        $this->publishToQueue($channel, (string) $queue->getName(), 'recovered');

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);

        $queue->recover();

        $again = $this->waitForMessage($queue);

        self::assertNotNull($again);
        self::assertSame('recovered', $again->getBody());
        self::assertTrue($again->isRedelivery());

        $queue->ack($again->getDeliveryTag());
    }
}
