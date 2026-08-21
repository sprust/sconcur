<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Features\Amqp\AMQPExchange;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Features\Amqp\AMQPExchangeException;
use SConcur\Features\Amqp\AMQPQueueException;
use SConcur\Tests\Impl\TestAmqpResolver;
use const SConcur\Features\Amqp\AMQP_AUTODELETE;
use const SConcur\Features\Amqp\AMQP_DURABLE;
use const SConcur\Features\Amqp\AMQP_EX_TYPE_FANOUT;
use const SConcur\Features\Amqp\AMQP_EX_TYPE_TOPIC;
use const SConcur\Features\Amqp\AMQP_NOPARAM;
use const SConcur\Features\Amqp\AMQP_PASSIVE;

/**
 * The topology half of the feature: declaring, binding, purging and deleting queues and
 * exchanges, and what each call reports back.
 */
class AmqpTopologyTest extends AmqpTestCase
{
    public function testDeclaringAQueueReportsHowManyMessagesItHolds(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        self::assertSame(0, $queue->declareQueue());

        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'one');
        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'two');

        // The broker counts messages at declare time, and the two publishes above are
        // asynchronous — re-declaring until it catches up keeps the test honest without
        // making it flaky.
        self::assertSame(2, $this->waitForMessageCount(queue: $queue, expected: 2));
    }

    public function testAQueueWithNoNameGetsOneFromTheBroker(): void
    {
        $channel = $this->channel();

        // Naming no queue is how a server-named one is asked for; an empty name is refused
        // outright, exactly as in ext-amqp.
        $queue = new AMQPQueue($channel);

        $queue->setFlags(AMQP_AUTODELETE);
        $queue->declareQueue();

        $this->declaredQueues[] = (string) $queue->getName();

        self::assertNotNull($queue->getName());
        self::assertStringStartsWith('amq.gen-', (string) $queue->getName());
    }

    public function testAnEmptyOrOversizeQueueNameIsRefused(): void
    {
        $queue = new AMQPQueue($this->channel());

        try {
            $queue->setName('');

            self::fail('an empty queue name must be refused');
        } catch (AMQPQueueException $exception) {
            self::assertStringContainsString('between 1 and 255', $exception->getMessage());
        }

        $this->expectException(AMQPQueueException::class);

        $queue->setName(str_repeat('q', 256));
    }

    public function testAnOversizeExchangeNameIsRefused(): void
    {
        $exchange = new AMQPExchange($this->channel());

        $this->expectException(AMQPExchangeException::class);

        $exchange->setName(str_repeat('x', 256));
    }

    public function testANamelessExchangeCannotBeDeclared(): void
    {
        $exchange = new AMQPExchange($this->channel());

        $exchange->setType(AMQP_EX_TYPE_FANOUT);

        $this->expectException(AMQPExchangeException::class);

        // The default exchange exists already and may not be redeclared; the extension
        // refuses this locally instead of letting the broker kill the channel.
        $exchange->declareExchange();
    }

    public function testAnUnsetNameAndTypeReadBackAsNull(): void
    {
        $exchange = new AMQPExchange($this->channel());

        self::assertNull($exchange->getName());
        self::assertNull($exchange->getType());

        $exchange->setName('');
        $exchange->setType('');

        self::assertNull($exchange->getName());
        self::assertNull($exchange->getType());
    }

    public function testAPassiveDeclarationFailsOnAQueueThatDoesNotExist(): void
    {
        $channel = $this->channel();

        $queue = new AMQPQueue($channel);

        $queue->setName(TestAmqpResolver::uniqueName('missing'));
        $queue->setFlags(AMQP_PASSIVE);

        $this->expectException(AMQPQueueException::class);

        $queue->declareQueue();
    }

    public function testAPassiveDeclarationPassesOnAQueueThatExists(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $passive = new AMQPQueue($channel);

        $passive->setName((string) $queue->getName());
        $passive->setFlags(AMQP_PASSIVE);

        self::assertSame(0, $passive->declareQueue());
    }

    public function testPurgingAQueueReportsHowManyMessagesItRemoved(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'one');
        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'two');

        $this->waitForMessageCount(queue: $queue, expected: 2);

        self::assertSame(2, $queue->purge());
        self::assertNull($queue->get());
    }

    public function testDeletingAQueueReportsHowManyMessagesWentWithIt(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'one');

        $this->waitForMessageCount(queue: $queue, expected: 1);

        self::assertSame(1, $queue->delete());

        $this->declaredQueues = [];
    }

    public function testABoundQueueReceivesWhatItsExchangeRoutes(): void
    {
        $channel  = $this->channel();
        $exchange = $this->declareExchange(channel: $channel, type: AMQP_EX_TYPE_TOPIC);
        $queue    = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $queue->bind(exchangeName: (string) $exchange->getName(), routingKey: 'order.*');

        $exchange->publish(message: 'routed', routingKey: 'order.created');

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);
        self::assertSame('routed', $envelope->getBody());
        self::assertSame('order.created', $envelope->getRoutingKey());
        self::assertSame($exchange->getName(), $envelope->getExchangeName());

        $queue->ack($envelope->getDeliveryTag());
    }

    public function testUnbindingStopsTheRouting(): void
    {
        $channel  = $this->channel();
        $exchange = $this->declareExchange(channel: $channel, type: AMQP_EX_TYPE_FANOUT);
        $queue    = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $queue->bind(exchangeName: (string) $exchange->getName());
        $queue->unbind(exchangeName: (string) $exchange->getName());

        $exchange->publish(message: 'dropped');

        $this->assertQueueStaysEmpty($queue);
    }

    public function testAnExchangeBoundToAnotherPassesMessagesOn(): void
    {
        $channel     = $this->channel();
        $source      = $this->declareExchange(channel: $channel, type: AMQP_EX_TYPE_FANOUT);
        $destination = $this->declareExchange(channel: $channel, type: AMQP_EX_TYPE_FANOUT);
        $queue       = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $destination->bind(exchangeName: (string) $source->getName());
        $queue->bind(exchangeName: (string) $destination->getName());

        $source->publish(message: 'chained');

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);
        self::assertSame('chained', $envelope->getBody());

        $queue->ack($envelope->getDeliveryTag());
    }

    public function testTheFlagsSurviveARoundTripThroughTheBitMask(): void
    {
        $channel = $this->channel();

        $queue = new AMQPQueue($channel);

        $queue->setFlags(AMQP_DURABLE | AMQP_AUTODELETE);

        self::assertSame(AMQP_DURABLE | AMQP_AUTODELETE, $queue->getFlags());

        $exchange = new AMQPExchange($channel);

        $exchange->setFlags(AMQP_DURABLE);

        self::assertSame(AMQP_DURABLE, $exchange->getFlags());

        // A fresh queue is auto-delete in ext-amqp, and a mask without the flag turns it
        // off — the calque must behave the same way.
        $fresh = new AMQPQueue($channel);

        self::assertSame(AMQP_AUTODELETE, $fresh->getFlags());

        $fresh->setFlags(AMQP_NOPARAM);

        self::assertSame(AMQP_NOPARAM, $fresh->getFlags());
    }

    public function testQueueArgumentsReachTheBroker(): void
    {
        $channel = $this->channel();

        $queue = new AMQPQueue($channel);

        $queue->setName(TestAmqpResolver::uniqueName('args'));
        $queue->setFlags(AMQP_DURABLE);
        $queue->setArgument('x-max-length', 10);
        $queue->setArgument('x-message-ttl', 60000);

        $queue->declareQueue();

        $this->declaredQueues[] = (string) $queue->getName();

        self::assertTrue($queue->hasArgument('x-max-length'));
        self::assertSame(10, $queue->getArgument('x-max-length'));

        // A second declaration with the same arguments passes; the broker would reject a
        // mismatch, so this is what proves the arguments actually travelled.
        self::assertSame(0, $queue->declareQueue());

        $queue->removeArgument('x-message-ttl');

        self::assertFalse($queue->hasArgument('x-message-ttl'));
    }

    public function testAnUnknownArgumentIsReported(): void
    {
        $queue = new AMQPQueue($this->channel());

        $this->expectException(AMQPQueueException::class);

        $queue->getArgument('x-nothing');
    }
}
