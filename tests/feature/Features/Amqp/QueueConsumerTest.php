<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use RuntimeException;
use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Exceptions\CoroutineTimeoutException;
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
     * A handler still working when the drain deadline passes is cut by the group being
     * stopped. That is not a handler failure — the application never got to decide — so the
     * message must go back to the broker rather than be refused on its behalf. Leaving it
     * unsettled is what returns it: the channel closing behind the coroutine hands it back.
     */
    public function testAMessageCutByTheDrainDeadlineGoesBackToTheBroker(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue($channel, $queue->name(), 'slow');

        $failures = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 1]),
            // The run is over while the handler is still inside its sleep, and the drain
            // gives up on it long before it would have finished.
            maxRuntimeSeconds: 1,
            drainTimeoutMs: 100,
            pollIntervalMs: 20,
        );

        $count = $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery): void {
                Sleeper::usleep(microseconds: 3_000_000);
            },
            onError: static function (Throwable $exception, Delivery $delivery) use (&$failures): void {
                $failures[] = $exception::class;
            },
        );

        // A stop is not a failure, so the application is not told its handler failed.
        self::assertSame([], $failures, 'a deliberate stop must not be reported as a handler failure');

        // Nor is it a message handled: nobody answered for it, and the broker hands it out
        // again. A worker that counted it would report more work done than it did, and a
        // maxMessages budget would be spent on messages nothing was done with.
        self::assertSame(0, $count, 'a message the drain cut short must not count as handled');

        // And the message was not refused on the handler's behalf: it comes back.
        //
        // The collection is what makes the assertion prompt rather than eventual. An
        // unwound coroutine leaves its channel in a reference cycle, and the message stays
        // owed to the broker until that channel is released, and releasing it
        // deterministically is a question for the runtime rather than for this feature —
        // it is what a coroutine's lifetime would settle. What this test pins down is that
        // the message survives at all; when exactly it comes back is that work's business.
        gc_collect_cycles();

        self::assertSame(1, $this->waitForMessageCount(queue: $queue, expected: 1, timeoutSeconds: 5.0));
    }

    /**
     * A job that runs too long costs its own message and nothing else: the handler is
     * unwound where it stands, the delivery is refused like any other failure, and the same
     * coroutine takes the next message.
     */
    public function testAHandlerThatOutrunsItsDeadlineIsCutAndTheConsumerCarriesOn(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue($channel, $queue->name(), 'slow');
        $this->publishToQueue($channel, $queue->name(), 'quick');

        $handled  = [];
        $failures = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 1]),
            handlerTimeoutMs: 200,
            maxMessages: 2,
            pollIntervalMs: 50,
        );

        $count = $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery) use (&$handled): void {
                if ($delivery->body === 'slow') {
                    Sleeper::usleep(microseconds: 3_000_000);
                }

                $handled[] = $delivery->body;
            },
            onError: static function (Throwable $exception, Delivery $delivery) use (&$failures): void {
                $failures[$delivery->body] = $exception::class;
            },
        );

        self::assertSame(2, $count, 'the coroutine survived the deadline and took the next message');
        self::assertSame(['quick'], $handled);
        self::assertSame(['slow' => CoroutineTimeoutException::class], $failures);

        // Refused without requeue, like any other failed message.
        self::assertNull($this->waitForMessage($queue, timeoutSeconds: 0.3));
    }

    /** With no deadline configured a slow handler is left alone. */
    public function testWithoutADeadlineASlowHandlerRunsToTheEnd(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue($channel, $queue->name(), 'slow');

        $handled = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 1]),
            maxMessages: 1,
            pollIntervalMs: 50,
        );

        $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery) use (&$handled): void {
                Sleeper::usleep(microseconds: 400_000);

                $handled[] = $delivery->body;
            },
        );

        self::assertSame(['slow'], $handled);
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
    /**
     * The drain watches how many coroutines are mid-message, and settling is part of one:
     * a consumer counted out of that set before its acknowledgement is on the wire can be
     * cut between the two, and the message its handler finished goes back to the queue.
     *
     * The window is small, so the test widens it by hand — the drain gives up the instant
     * the last handler returns, and every message must still be gone from the queue.
     */
    public function testAMessageIsAcknowledgedBeforeItsConsumerIsCountedIdle(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, durable: true);

        foreach (range(1, 8) as $index) {
            $this->publishToQueue($channel, $queue->name(), "message-$index");
        }

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 4]),
            maxMessages: 8,
            // As short as it goes: the stop lands as soon as nothing is mid-message, so an
            // acknowledgement still in flight at that moment would be cut.
            drainTimeoutMs: 1,
            pollIntervalMs: 20,
        );

        $count = $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery): void {
            },
        );

        self::assertSame(8, $count);
        self::assertSame(0, $this->waitForMessageCount(queue: $queue, expected: 0, timeoutSeconds: 5.0));
    }

    /**
     * The connection going away is what ends a consumer for good — the queue it was pulling
     * can be reopened, a connection shared by every coroutine cannot. Reporting that as a
     * finished shift would exit 0, and a pool on `restartPolicy: on-failure` would stay
     * empty for good after a broker outage.
     */
    public function testAPoolThatLostItsConnectionReportsAFailure(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, durable: true);

        $connection = $this->connection();

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 2]),
            maxRuntimeSeconds: 30,
            pollIntervalMs: 20,
        );

        $this->publishToQueue($channel, $queue->name(), 'first');

        $this->expectException(AmqpException::class);

        $queueConsumer->consume(
            connection: $connection,
            handler: static function (Delivery $delivery) use ($connection): void {
                // Handing the connection back takes every channel of this worker with it,
                // which is the failure no consumer can reopen its way out of.
                $connection->close();
            },
        );
    }

    /**
     * A consumer taken away by the broker used to end its coroutine, leaving that queue
     * unread for the life of the worker while its neighbours carried on — the pool quietly
     * lost capacity and said so once. It reopens instead.
     */
    public function testAConsumerTakenAwayByTheBrokerReopensItsQueue(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, durable: true);

        $name = $queue->name();

        $handled = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$name => 1]),
            maxMessages: 2,
            maxRuntimeSeconds: 30,
            pollIntervalMs: 50,
        );

        $this->publishToQueue($channel, $name, 'first');

        $count = $queueConsumer->consume(
            connection: $this->connection(),
            handler: function (Delivery $delivery) use (&$handled, $channel, $name): void {
                $handled[] = $delivery->body;

                if ($handled !== ['first']) {
                    return;
                }

                // Deleting the queue is how the broker takes a consumer away. Declaring it
                // again and publishing gives the reopened consumer something to find.
                $channel->queue($name)->delete();

                $recreated = $channel->queue($name);

                $recreated->declare(durable: true);
                $recreated->publish('second');
            },
        );

        self::assertSame(2, $count, 'the consumer must come back and take the next message');
        self::assertSame(['first', 'second'], $handled);
    }

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
