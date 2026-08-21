<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Features\Amqp\AMQPExchange;
use SConcur\Features\Amqp\AMQPQueue;
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
        $queue   = $this->declareQueue($channel, AMQP_DURABLE);

        self::assertSame(0, $queue->declareQueue());

        $this->publishToQueue($channel, (string) $queue->getName(), 'one');
        $this->publishToQueue($channel, (string) $queue->getName(), 'two');

        // The broker counts messages at declare time, and the two publishes above are
        // asynchronous — re-declaring until it catches up keeps the test honest without
        // making it flaky.
        self::assertSame(2, $this->waitForMessageCount($queue, 2));
    }

    public function testAnEmptyNameAsksTheBrokerForOne(): void
    {
        $channel = $this->channel();

        $queue = new AMQPQueue($channel);

        $queue->setName('');
        $queue->setFlags(AMQP_AUTODELETE);
        $queue->declareQueue();

        self::assertNotSame('', $queue->getName());
        self::assertStringStartsWith('amq.gen-', (string) $queue->getName());
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
        $queue   = $this->declareQueue($channel, AMQP_DURABLE);

        $passive = new AMQPQueue($channel);

        $passive->setName((string) $queue->getName());
        $passive->setFlags(AMQP_PASSIVE);

        self::assertSame(0, $passive->declareQueue());
    }

    public function testPurgingAQueueReportsHowManyMessagesItRemoved(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue($channel, AMQP_DURABLE);

        $this->publishToQueue($channel, (string) $queue->getName(), 'one');
        $this->publishToQueue($channel, (string) $queue->getName(), 'two');

        $this->waitForMessageCount($queue, 2);

        self::assertSame(2, $queue->purge());
        self::assertNull($queue->get());
    }

    public function testDeletingAQueueReportsHowManyMessagesWentWithIt(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue($channel, AMQP_DURABLE);

        $this->publishToQueue($channel, (string) $queue->getName(), 'one');

        $this->waitForMessageCount($queue, 1);

        self::assertSame(1, $queue->delete());

        $this->declaredQueues = [];
    }

    public function testABoundQueueReceivesWhatItsExchangeRoutes(): void
    {
        $channel  = $this->channel();
        $exchange = $this->declareExchange($channel, AMQP_EX_TYPE_TOPIC);
        $queue    = $this->declareQueue($channel, AMQP_DURABLE);

        $queue->bind((string) $exchange->getName(), 'order.*');

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
        $exchange = $this->declareExchange($channel, AMQP_EX_TYPE_FANOUT);
        $queue    = $this->declareQueue($channel, AMQP_DURABLE);

        $queue->bind((string) $exchange->getName());
        $queue->unbind((string) $exchange->getName());

        $exchange->publish(message: 'dropped');

        self::assertNull($queue->get());
    }

    public function testAnExchangeBoundToAnotherPassesMessagesOn(): void
    {
        $channel     = $this->channel();
        $source      = $this->declareExchange($channel, AMQP_EX_TYPE_FANOUT);
        $destination = $this->declareExchange($channel, AMQP_EX_TYPE_FANOUT);
        $queue       = $this->declareQueue($channel, AMQP_DURABLE);

        $destination->bind((string) $source->getName());
        $queue->bind((string) $destination->getName());

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
