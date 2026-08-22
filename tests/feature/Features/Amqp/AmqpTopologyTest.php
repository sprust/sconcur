<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Exceptions\Amqp\ExchangeException;
use SConcur\Exceptions\Amqp\QueueException;
use SConcur\Features\Amqp\ExchangeTypeEnum;
use SConcur\Tests\Impl\TestAmqpResolver;

/**
 * The topology half of the feature: declaring, binding, purging and deleting queues and
 * exchanges, and what each call reports back.
 */
class AmqpTopologyTest extends AmqpTestCase
{
    public function testDeclaringAQueueReportsHowManyMessagesItHolds(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, durable: true);

        self::assertSame(0, $queue->declare(durable: true)->messageCount);

        $this->publishToQueue(channel: $channel, queueName: $queue->name(), message: 'one');
        $this->publishToQueue(channel: $channel, queueName: $queue->name(), message: 'two');

        // The broker counts messages at declare time, and the two publishes above are
        // asynchronous — re-declaring until it catches up keeps the test honest without
        // making it flaky.
        self::assertSame(2, $this->waitForMessageCount(queue: $queue, expected: 2));
    }

    /**
     * The broker answers queue.declare with the consumer count as well. ext-amqp returns
     * only the message count, so the calque threw this half away.
     */
    public function testDeclaringAQueueReportsHowManyConsumersItHas(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, durable: true);

        $before = $queue->declare(durable: true);

        self::assertSame(0, $before->consumerCount);

        // Advancing the generator is what registers the consumer, and it waits for the
        // first delivery — so there has to be one waiting, or the test would hang here
        // for as long as the connection's read timeout allows.
        $this->publishToQueue(channel: $channel, queueName: $queue->name(), message: 'one');

        $consuming = $this->connection()->channel()->queue($queue->name())->consume();

        $delivery = $consuming->current();

        // The broker's own count follows the consumer a moment later.
        $deadline = microtime(true) + 2.0;

        $after = $queue->declare(durable: true);

        while ($after->consumerCount === 0 && microtime(true) < $deadline) {
            usleep(20_000);

            $after = $queue->declare(durable: true);
        }

        self::assertSame(1, $after->consumerCount);

        $delivery->ack();
    }

    public function testAQueueWithNoNameGetsOneFromTheBroker(): void
    {
        $queue = $this->channel()->queue('');

        $info = $queue->declare(autoDelete: true);

        $this->declaredQueues[] = $queue->name();

        self::assertStringStartsWith('amq.gen-', $info->name);
        // The generated name becomes the handle's own, so the next call reaches the queue
        // the broker made rather than asking for another one.
        self::assertSame($info->name, $queue->name());
    }

    public function testAPassiveDeclarationFailsOnAQueueThatDoesNotExist(): void
    {
        $queue = $this->channel()->queue(TestAmqpResolver::uniqueName('missing'));

        $this->expectException(QueueException::class);

        $queue->declarePassive();
    }

    public function testAPassiveDeclarationPassesOnAQueueThatExists(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, durable: true);

        // A passive declaration reads the queue without touching its settings, so it needs
        // none of the ones it was created with.
        self::assertSame(0, $channel->queue($queue->name())->declarePassive()->messageCount);
    }

    public function testPurgingAQueueReportsHowManyMessagesItRemoved(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue(channel: $channel, queueName: $queue->name(), message: 'one');
        $this->publishToQueue(channel: $channel, queueName: $queue->name(), message: 'two');

        $this->waitForMessageCount(queue: $queue, expected: 2);

        self::assertSame(2, $queue->purge());
        self::assertNull($queue->get());
    }

    public function testDeletingAQueueReportsHowManyMessagesWentWithIt(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue(channel: $channel, queueName: $queue->name(), message: 'one');

        $this->waitForMessageCount(queue: $queue, expected: 1);

        self::assertSame(1, $queue->delete());

        $this->declaredQueues = [];
    }

    public function testABoundQueueReceivesWhatItsExchangeRoutes(): void
    {
        $channel  = $this->channel();
        $exchange = $this->declareExchange(channel: $channel, type: ExchangeTypeEnum::Topic);
        $queue    = $this->declareQueue(channel: $channel, durable: true);

        $queue->bind(exchange: $exchange->name(), routingKey: 'order.*');

        $exchange->publish(message: 'routed', routingKey: 'order.created');

        $delivery = $this->waitForMessage($queue);

        self::assertNotNull($delivery);
        self::assertSame('routed', $delivery->body);
        self::assertSame('order.created', $delivery->routingKey);
        self::assertSame($exchange->name(), $delivery->exchange);

        $delivery->ack();
    }

    public function testUnbindingStopsTheRouting(): void
    {
        $channel  = $this->channel();
        $exchange = $this->declareExchange(channel: $channel, type: ExchangeTypeEnum::Fanout);
        $queue    = $this->declareQueue(channel: $channel, durable: true);

        $queue->bind(exchange: $exchange->name());
        $queue->unbind(exchange: $exchange->name());

        $exchange->publish(message: 'dropped');

        $this->assertQueueStaysEmpty($queue);
    }

    public function testAnExchangeBoundToAnotherPassesMessagesOn(): void
    {
        $channel     = $this->channel();
        $source      = $this->declareExchange(channel: $channel, type: ExchangeTypeEnum::Fanout);
        $destination = $this->declareExchange(channel: $channel, type: ExchangeTypeEnum::Fanout);
        $queue       = $this->declareQueue(channel: $channel, durable: true);

        $destination->bind(source: $source->name());
        $queue->bind(exchange: $destination->name());

        $source->publish(message: 'chained');

        $delivery = $this->waitForMessage($queue);

        self::assertNotNull($delivery);
        self::assertSame('chained', $delivery->body);

        $delivery->ack();
    }

    public function testQueueArgumentsReachTheBroker(): void
    {
        $channel = $this->channel();

        $arguments = [
            'x-max-length'  => 10,
            'x-message-ttl' => 60000,
        ];

        $queue = $this->declareQueue(channel: $channel, durable: true, arguments: $arguments);

        // A second declaration with the same arguments passes; the broker answers a
        // mismatch by closing the channel, so this is what proves they travelled.
        self::assertSame(0, $queue->declare(durable: true, arguments: $arguments)->messageCount);
    }

    public function testRedeclaringAQueueWithOtherArgumentsIsRefused(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, durable: true, arguments: ['x-max-length' => 10]);

        $this->expectException(QueueException::class);

        $queue->declare(durable: true, arguments: ['x-max-length' => 20]);
    }

    public function testAPassiveExchangeDeclarationFailsOnOneThatDoesNotExist(): void
    {
        $exchange = $this->channel()->exchange(TestAmqpResolver::uniqueName('missing'));

        $this->expectException(ExchangeException::class);

        $exchange->declarePassive();
    }

    public function testDeletingAnExchangeStopsTheRouting(): void
    {
        $channel  = $this->channel();
        $exchange = $this->declareExchange(channel: $channel, type: ExchangeTypeEnum::Fanout);
        $queue    = $this->declareQueue(channel: $channel, durable: true);

        $queue->bind(exchange: $exchange->name());

        $exchange->delete();

        $this->declaredExchanges = [];

        // The queue outlives the exchange; only the routing to it is gone.
        self::assertSame(0, $queue->declare(durable: true)->messageCount);
    }
}
