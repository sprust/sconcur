<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use RuntimeException;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Features\Amqp\Delivery;
use SConcur\Features\Sleeper\Sleeper;
use Throwable;

/**
 * The consumer worker runtime: several queues pulled at once by one process, weighted
 * per queue, ending on a limit through a drain rather than a cut.
 */
class QueueConsumerTest extends AmqpTestCase
{
    public function testItPullsSeveralQueuesAtOnce(): void
    {
        $channel = $this->channel();

        $orders   = $this->declareQueue(channel: $channel, durable: true);
        $invoices = $this->declareQueue(channel: $channel, durable: true);

        for ($index = 0; $index < 4; ++$index) {
            $this->publishToQueue($channel, $orders->name(), "order-$index");
            $this->publishToQueue($channel, $invoices->name(), "invoice-$index");
        }

        $handled = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([
                $orders->name()   => 2,
                $invoices->name() => 2,
            ]),
            maxMessages: 8,
        );

        $count = $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery) use (&$handled): void {
                $handled[] = $delivery->body;
            },
        );

        self::assertSame(8, $count);
        self::assertCount(8, $handled);

        sort($handled);

        self::assertSame(
            ['invoice-0', 'invoice-1', 'invoice-2', 'invoice-3', 'order-0', 'order-1', 'order-2', 'order-3'],
            $handled,
        );
    }

    /**
     * The point of the runtime: waiting overlaps. Three queues whose handlers each sleep
     * finish in about one sleep, not three.
     */
    public function testWaitingOverlapsAcrossQueues(): void
    {
        $channel = $this->channel();

        $names = [];

        for ($index = 0; $index < 3; ++$index) {
            $queue = $this->declareQueue(channel: $channel, durable: true);

            $names[] = $queue->name();

            $this->publishToQueue($channel, $queue->name(), "body-$index");
        }

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson(array_fill_keys($names, 1)),
            maxMessages: 3,
        );

        $startedAt = microtime(true);

        $count = $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery): void {
                Sleeper::usleep(microseconds: 200_000);
            },
        );

        $elapsedSeconds = microtime(true) - $startedAt;

        self::assertSame(3, $count);
        self::assertLessThan(0.5, $elapsedSeconds, 'three 200ms handlers must overlap, not queue up');
    }

    public function testTheWeightDecidesHowManyCoroutinesPullAQueue(): void
    {
        $channel = $this->channel();

        $hot  = $this->declareQueue(channel: $channel, durable: true);
        $cold = $this->declareQueue(channel: $channel, durable: true);

        // Four slow messages on each queue. With four coroutines on the hot one and a
        // single coroutine on the cold one, the hot queue drains in one sleep while the
        // cold one takes four.
        for ($index = 0; $index < 4; ++$index) {
            $this->publishToQueue($channel, $hot->name(), "hot-$index");
            $this->publishToQueue($channel, $cold->name(), "cold-$index");
        }

        $finishedAt = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([
                $hot->name()  => 4,
                $cold->name() => 1,
            ]),
            maxMessages: 8,
        );

        $startedAt = microtime(true);

        $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery) use (&$finishedAt, $startedAt): void {
                Sleeper::usleep(microseconds: 100_000);

                $finishedAt[$delivery->body] = microtime(true) - $startedAt;
            },
        );

        $lastHot  = max(array_intersect_key($finishedAt, array_flip(['hot-0', 'hot-1', 'hot-2', 'hot-3'])));
        $lastCold = max(array_intersect_key($finishedAt, array_flip(['cold-0', 'cold-1', 'cold-2', 'cold-3'])));

        self::assertLessThan($lastCold, $lastHot, 'the heavier queue must finish first');
    }

    /**
     * A handler that throws costs one message, not one consumer.
     *
     * The runtime owns the acknowledgement now, so it knows the handler left the delivery
     * open and can refuse it. The calque could not: the handler owned the acknowledgement,
     * a handler that threw might already have settled the message, and the only safe answer
     * was to end the coroutine and let the closing channel hand the message back.
     */
    public function testAFailingHandlerRefusesItsMessageAndKeepsGoing(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue($channel, $queue->name(), 'boom');
        $this->publishToQueue($channel, $queue->name(), 'fine');

        $handled  = [];
        $failures = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 1]),
            maxMessages: 2,
            pollIntervalMs: 50,
        );

        $count = $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery) use (&$handled): void {
                if ($delivery->body === 'boom') {
                    throw new RuntimeException('handler blew up on ' . $delivery->body);
                }

                $handled[] = $delivery->body;
            },
            onError: static function (Throwable $exception, Delivery $delivery) use (&$failures): void {
                $failures[$delivery->body] = $exception->getMessage();
            },
        );

        self::assertSame(2, $count, 'the same coroutine handled both messages');
        self::assertSame(['fine'], $handled);
        self::assertSame(['boom' => 'handler blew up on boom'], $failures);

        // Refused without requeue by default, so the poisoned message is gone rather than
        // going round for ever.
        self::assertNull($this->waitForMessage($queue, timeoutSeconds: 0.3));
    }

    /**
     * With requeueOnFailure the message goes back instead — right for a failure that may
     * pass, and a loop for one that never will, which is why it is not the default.
     */
    public function testAFailingHandlerCanPutTheMessageBack(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue($channel, $queue->name(), 'boom');

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 1]),
            requeueOnFailure: true,
            maxMessages: 1,
            pollIntervalMs: 50,
        );

        $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery): void {
                throw new RuntimeException('handler blew up');
            },
            onError: static function (): void {
            },
        );

        $returned = $this->waitForMessage($queue, timeoutSeconds: 2.0);

        self::assertNotNull($returned);
        self::assertSame('boom', $returned->body);
        self::assertTrue($returned->redelivered);

        $returned->ack();
    }

    /**
     * A handler that settled the delivery itself is left alone: the runtime only answers
     * for one that did not, which is what lets a handler reject selectively.
     */
    public function testAHandlerThatSettlesTheDeliveryItselfIsNotOverruled(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue($channel, $queue->name(), 'mine');

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 1]),
            maxMessages: 1,
            pollIntervalMs: 50,
        );

        $settled = false;

        $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery) use (&$settled): void {
                $delivery->nack(requeue: true);

                $settled = $delivery->isSettled();
            },
        );

        self::assertTrue($settled);

        // Requeued by the handler's own choice, not acknowledged by the runtime.
        $returned = $this->waitForMessage($queue, timeoutSeconds: 2.0);

        self::assertNotNull($returned);
        self::assertSame('mine', $returned->body);

        $returned->ack();
    }

    /**
     * The drain: reaching maxMessages stops the consumers that are working, and the
     * supervisor then ends the ones still waiting for a delivery. A queue left with
     * messages in it must not hold the run open.
     */
    public function testTheRunEndsWhileOtherQueuesStayIdle(): void
    {
        $channel = $this->channel();

        $busy = $this->declareQueue(channel: $channel, durable: true);
        $idle = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue($channel, $busy->name(), 'only-one');

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([
                $busy->name() => 1,
                $idle->name() => 3,
            ]),
            maxMessages: 1,
            drainTimeoutMs: 1000,
            pollIntervalMs: 50,
        );

        $startedAt = microtime(true);

        $count = $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery): void {
            },
        );

        $elapsedSeconds = microtime(true) - $startedAt;

        self::assertSame(1, $count);
        self::assertLessThan(2.0, $elapsedSeconds, 'the idle consumers must not hold the run open');
    }

    /**
     * A message the handler is still on when the drain starts is finished, not dropped:
     * the run does not return before the handler has acknowledged it.
     */
    public function testAMessageInFlightIsFinishedBeforeTheRunEnds(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue($channel, $queue->name(), 'slow');

        $acknowledged = false;

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 1]),
            maxMessages: 1,
            drainTimeoutMs: 2000,
            pollIntervalMs: 50,
        );

        $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery) use (&$acknowledged): void {
                Sleeper::usleep(microseconds: 300_000);

                $acknowledged = true;
            },
        );

        self::assertTrue($acknowledged, 'the in-flight handler must run to completion');

        // Acknowledged means gone: nothing comes back on a fresh get().
        self::assertNull($this->waitForMessage($queue, timeoutSeconds: 0.3));
    }

    /**
     * A consumer whose queue is taken away must not take the worker with it: the other
     * queues keep being pulled, and their handlers are not cut mid-message.
     */
    public function testOneFailingConsumerDoesNotEndTheOthers(): void
    {
        $channel = $this->channel();

        $doomed  = $this->declareQueue(channel: $channel, durable: true);
        $healthy = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue($channel, $healthy->name(), 'slow');

        $handled = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([
                $doomed->name()  => 1,
                $healthy->name() => 1,
            ]),
            maxMessages: 1,
            pollIntervalMs: 50,
        );

        // Deleting the queue ends its consumer with a broker-side failure while the
        // other coroutine is inside a handler.
        $deleted = false;

        $count = $queueConsumer->consume(
            connection: $this->connection(),
            handler: function (Delivery $delivery) use (&$handled, &$deleted, $doomed): void {
                if (!$deleted) {
                    $deleted = true;

                    $doomed->delete();
                }

                Sleeper::usleep(microseconds: 300_000);

                $handled[] = $delivery->body;
            },
        );

        self::assertSame(1, $count);
        self::assertSame(['slow'], $handled, 'the surviving handler must run to completion');
    }

    /**
     * Every channel the worker opened is closed when the run ends, including the ones
     * whose coroutines were still waiting and had to be unwound by the drain.
     */
    public function testTheChannelsAreClosedWhenTheRunEnds(): void
    {
        $channel = $this->channel();

        $busy = $this->declareQueue(channel: $channel, durable: true);
        $idle = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue($channel, $busy->name(), 'one');

        $before = $this->connection()->usedChannels();

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([
                $busy->name() => 1,
                $idle->name() => 3,
            ]),
            maxMessages: 1,
            drainTimeoutMs: 1000,
            pollIntervalMs: 50,
        );

        $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery): void {
            },
        );

        // The three idle coroutines are ended by the drain, which detaches them; their
        // channels are released by the channel objects going out of scope.
        $deadline = microtime(true) + 3.0;

        while (microtime(true) < $deadline && $this->connection()->usedChannels() > $before) {
            usleep(100_000);
        }

        self::assertSame($before, $this->connection()->usedChannels());
    }

    /**
     * @param array<string, int> $weights queue name => coroutine count
     */
    protected function queuesJson(array $weights): string
    {
        $specs = [];

        foreach ($weights as $name => $coroutineCount) {
            $specs[] = ['name' => $name, 'coroutineCount' => $coroutineCount];
        }

        return (string) json_encode($specs);
    }
}
