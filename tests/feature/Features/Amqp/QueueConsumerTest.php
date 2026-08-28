<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use RuntimeException;
use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Exceptions\CoroutineTimeoutException;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Features\Amqp\Delivery;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Impl\InspectableQueueConsumer;
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

        $orders = $this->declareQueue(
            channel: $channel,
            durable: true,
        );
        $invoices = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

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
            $queue = $this->declareQueue(
                channel: $channel,
                durable: true,
            );

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

    public function testTheWeightDecidesHowManyConsumersPullAQueue(): void
    {
        $channel = $this->channel();

        $hot = $this->declareQueue(
            channel: $channel,
            durable: true,
        );
        $cold = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        // Four slow messages on each queue. With four consumers on the hot one and a
        // single consumer on the cold one, the hot queue drains in one sleep while the
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

        $queue = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue($channel, $queue->name(), 'boom');
        $this->publishToQueue($channel, $queue->name(), 'fine');

        $handled  = [];
        $failures = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 1]),
            maxMessages: 2,
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

        self::assertSame(2, $count, 'the failed message cost one message, not the worker');
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

        $queue = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue($channel, $queue->name(), 'boom');

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 1]),
            requeueOnFailure: true,
            maxMessages: 1,
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

        $queue = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue($channel, $queue->name(), 'mine');

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 1]),
            maxMessages: 1,
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
     * Reaching maxMessages ends the run even though other queues are still being pulled: a
     * consumer waiting for a delivery has nothing in hand, so cancelling it is all a stop
     * has to do. A queue left with messages in it must not hold the run open.
     */
    public function testTheRunEndsWhileOtherQueuesStayIdle(): void
    {
        $channel = $this->channel();

        $busy = $this->declareQueue(
            channel: $channel,
            durable: true,
        );
        $idle = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue($channel, $busy->name(), 'only-one');

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([
                $busy->name() => 1,
                $idle->name() => 3,
            ]),
            maxMessages: 1,
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

        $queue = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue($channel, $queue->name(), 'slow');

        $acknowledged = false;

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 1]),
            maxMessages: 1,
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
     * A life limit reached while a handler is mid-message does not cut it. The stop cancels
     * the consumers first and the loop leaves only once the last handler has returned, so a
     * job that outlives the limit still finishes and still answers for its message.
     *
     * That is what removed the two-phase drain the runtime used to need: nothing is unwound,
     * so nothing has to tell a deliberate unwind from a handler that failed.
     */
    public function testAMessageInFlightOutlivesTheLifeLimit(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue($channel, $queue->name(), 'slow');

        $failures = [];
        $finished = false;

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 1]),
            maxRuntimeSeconds: 1,
        );

        $count = $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery) use (&$finished): void {
                Sleeper::usleep(microseconds: 2_000_000);

                $finished = true;
            },
            onError: static function (Throwable $exception, Delivery $delivery) use (&$failures): void {
                $failures[] = $exception::class;
            },
        );

        self::assertTrue($finished, 'the handler must run to completion past the life limit');
        self::assertSame([], $failures, 'a stop must not be reported as a handler failure');
        self::assertSame(1, $count, 'the message was finished, so it counts as handled');

        // Acknowledged means gone.
        self::assertNull($this->waitForMessage($queue, timeoutSeconds: 0.3));
    }

    /**
     * A job that runs too long costs its own message and nothing else: the handler is
     * unwound where it stands, the delivery is refused like any other failure, and the same
     * coroutine takes the next message.
     */
    public function testAHandlerThatOutrunsItsDeadlineIsCutAndTheConsumerCarriesOn(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue($channel, $queue->name(), 'slow');
        $this->publishToQueue($channel, $queue->name(), 'quick');

        $handled  = [];
        $failures = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 1]),
            handlerTimeoutMs: 200,
            maxMessages: 2,
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

        self::assertSame(2, $count, 'the worker survived the deadline and took the next message');
        self::assertSame(['quick'], $handled);
        self::assertSame(['slow' => CoroutineTimeoutException::class], $failures);

        // Refused without requeue, like any other failed message.
        self::assertNull($this->waitForMessage($queue, timeoutSeconds: 0.3));
    }

    /** With no deadline configured a slow handler is left alone. */
    public function testWithoutADeadlineASlowHandlerRunsToTheEnd(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue($channel, $queue->name(), 'slow');

        $handled = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 1]),
            maxMessages: 1,
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

        $doomed = $this->declareQueue(
            channel: $channel,
            durable: true,
        );
        $healthy = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue($channel, $healthy->name(), 'slow');

        $handled = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([
                $doomed->name()  => 1,
                $healthy->name() => 1,
            ]),
            maxMessages: 1,
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
     * A stop cancels the consumers and leaves their channels open, so the acknowledgements
     * of the handlers that are still running land. Closing them with the cancel would hand
     * finished messages back for another worker to do again.
     */
    public function testTheAcknowledgementsInFlightSurviveTheStop(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        foreach (range(1, 8) as $index) {
            $this->publishToQueue($channel, $queue->name(), "message-$index");
        }

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 4]),
            maxMessages: 8,
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

        $queue = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $connection = $this->connection();

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$queue->name() => 2]),
            maxRuntimeSeconds: 30,
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

        $queue = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $name = $queue->name();

        $handled = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([$name => 1]),
            maxMessages: 2,
            maxRuntimeSeconds: 30,
        );

        $this->publishToQueue(
            channel: $channel,
            queueName: $name,
            message: 'first',
        );

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

    /**
     * A reopened consumer gets a channel of its own, with an id of its own, so the handle
     * over the one it left behind is never named again. Those handles used to be kept for
     * the life of the worker: a process running beside a broker that restarts nightly grew
     * one per loss and let go of none.
     */
    public function testTheHandleOverALostConsumersChannelIsNotKept(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $name = $queue->name();

        $handled = [];
        $held    = [];

        $queueConsumer = new InspectableQueueConsumer(
            queues: $this->queuesJson([$name => 1]),
            maxMessages: 2,
            maxRuntimeSeconds: 30,
        );

        $this->publishToQueue(
            channel: $channel,
            queueName: $name,
            message: 'first',
        );

        $queueConsumer->consume(
            connection: $this->connection(),
            handler: function (Delivery $delivery) use (&$handled, &$held, $queueConsumer, $channel, $name): void {
                $handled[] = $delivery->body;
                $held[]    = $queueConsumer->heldChannels();

                if ($handled !== ['first']) {
                    return;
                }

                // Deleting the queue is how the broker takes a consumer away; the stream
                // reopens it on a fresh channel a moment later.
                $channel->queue($name)->delete();

                $recreated = $channel->queue($name);

                $recreated->declare(durable: true);
                $recreated->publish('second');
            },
        );

        self::assertSame(['first', 'second'], $handled);
        self::assertSame(
            [1, 1],
            $held,
            'the handle over the channel the lost consumer used must go when its successor arrives',
        );
    }

    /**
     * Every channel the run opened is closed when it ends, including the ones whose
     * consumers never received anything.
     */
    public function testTheChannelsAreClosedWhenTheRunEnds(): void
    {
        $channel = $this->channel();

        $busy = $this->declareQueue(
            channel: $channel,
            durable: true,
        );
        $idle = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue($channel, $busy->name(), 'one');

        $before = $this->connection()->usedChannels();

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([
                $busy->name() => 1,
                $idle->name() => 3,
            ]),
            maxMessages: 1,
        );

        $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery): void {
            },
        );

        // The channels belong to the delivery stream, and the flow ending is what closes
        // them — including the ones whose consumers never had a message.
        $deadline = microtime(true) + 3.0;

        while (microtime(true) < $deadline && $this->connection()->usedChannels() > $before) {
            usleep(100_000);
        }

        self::assertSame($before, $this->connection()->usedChannels());
    }

    /**
     * @param array<string, int> $weights queue name => consumer count
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
