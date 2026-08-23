<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use ReflectionClass;
use ReflectionMethod;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Features\Amqp\Consumer\ConsumerState;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Features\Amqp\Channel;
use SConcur\Features\Amqp\Delivery;
use SConcur\Features\Amqp\MessageProperties;
use SConcur\Tests\Feature\BaseTestCase;
use WeakReference;

/**
 * The bookkeeping the drain reads, tested without a broker: the window this is about is a
 * few microseconds wide on a live connection, and a test racing it would pass for the wrong
 * reason far more often than it caught anything.
 */
class QueueConsumerDrainAccountingTest extends BaseTestCase
{
    /**
     * A stop that lands while the acknowledgement is going out is re-thrown on purpose —
     * it must not be swallowed. The message still has to leave the busy set on the way out:
     * one left counted holds the drain open for the whole of its timeout, waiting on a
     * consumer that is already gone.
     */
    public function testAConsumerCutMidAcknowledgementIsStillCountedIdle(): void
    {
        $state = new ConsumerState();

        $consumer = new class extends QueueConsumer {
            protected function settle(Delivery $delivery, bool $failed): void
            {
                throw new FlowStoppedException(message: 'Flow stopped');
            }
        };

        $handleDelivery = new ReflectionMethod(QueueConsumer::class, 'handleDelivery');

        $handler = static function (Delivery $delivery): void {
        };

        try {
            $handleDelivery->invoke($consumer, $this->delivery(), $handler, null, $state);

            self::fail('a stop mid-settle must not be swallowed');
        } catch (FlowStoppedException) {
            // Re-thrown, as it has to be.
        }

        self::assertSame(
            0,
            $state->busyConsumers(),
            'a consumer cut mid-acknowledgement must not hold the drain open',
        );
    }

    protected function delivery(): Delivery
    {
        return new Delivery(
            body: 'body',
            routingKey: '',
            exchange: '',
            consumerTag: 'ctag',
            deliveryTag: 1,
            redelivered: false,
            properties: new MessageProperties(),
            // Never touched: settle() is overridden above, so nothing here reaches a
            // broker. Built without its constructor because opening a real one would.
            channel: WeakReference::create(
                (new ReflectionClass(Channel::class))->newInstanceWithoutConstructor(),
            ),
        );
    }
}
