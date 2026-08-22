<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Features\Amqp\AMQPEnvelope;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Features\Sleeper\Sleeper;
use RuntimeException;
use Throwable;
use const SConcur\Features\Amqp\AMQP_DURABLE;

/**
 * The consumer worker runtime: several queues pulled at once by one process, weighted
 * per queue, ending on a limit through a drain rather than a cut.
 */
class QueueConsumerTest extends AmqpTestCase
{
    public function testItPullsSeveralQueuesAtOnce(): void
    {
        $channel = $this->channel();

        $orders   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);
        $invoices = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        for ($index = 0; $index < 4; ++$index) {
            $this->publishToQueue($channel, (string) $orders->getName(), "order-$index");
            $this->publishToQueue($channel, (string) $invoices->getName(), "invoice-$index");
        }

        $handled = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([
                (string) $orders->getName()   => 2,
                (string) $invoices->getName() => 2,
            ]),
            maxMessages: 8,
        );

        $count = $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (AMQPEnvelope $envelope, AMQPQueue $queue) use (&$handled): void {
                $handled[] = $envelope->getBody();

                $queue->ack($envelope->getDeliveryTag());
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
            $queue = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

            $names[] = (string) $queue->getName();

            $this->publishToQueue($channel, (string) $queue->getName(), "body-$index");
        }

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson(array_fill_keys($names, 1)),
            maxMessages: 3,
        );

        $startedAt = microtime(true);

        $count = $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (AMQPEnvelope $envelope, AMQPQueue $queue): void {
                Sleeper::usleep(microseconds: 200_000);

                $queue->ack($envelope->getDeliveryTag());
            },
        );

        $elapsedSeconds = microtime(true) - $startedAt;

        self::assertSame(3, $count);
        self::assertLessThan(0.5, $elapsedSeconds, 'three 200ms handlers must overlap, not queue up');
    }

    public function testTheWeightDecidesHowManyCoroutinesPullAQueue(): void
    {
        $channel = $this->channel();

        $hot  = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);
        $cold = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        // Four slow messages on each queue. With four coroutines on the hot one and a
        // single coroutine on the cold one, the hot queue drains in one sleep while the
        // cold one takes four.
        for ($index = 0; $index < 4; ++$index) {
            $this->publishToQueue($channel, (string) $hot->getName(), "hot-$index");
            $this->publishToQueue($channel, (string) $cold->getName(), "cold-$index");
        }

        $finishedAt = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([
                (string) $hot->getName()  => 4,
                (string) $cold->getName() => 1,
            ]),
            maxMessages: 8,
        );

        $startedAt = microtime(true);

        $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (AMQPEnvelope $envelope, AMQPQueue $queue) use (&$finishedAt, $startedAt): void {
                Sleeper::usleep(microseconds: 100_000);

                $queue->ack($envelope->getDeliveryTag());

                $finishedAt[$envelope->getBody()] = microtime(true) - $startedAt;
            },
        );

        $lastHot  = max(array_intersect_key($finishedAt, array_flip(['hot-0', 'hot-1', 'hot-2', 'hot-3'])));
        $lastCold = max(array_intersect_key($finishedAt, array_flip(['cold-0', 'cold-1', 'cold-2', 'cold-3'])));

        self::assertLessThan($lastCold, $lastHot, 'the heavier queue must finish first');
    }

    /**
     * A handler that throws ends its own coroutine and nothing else: the message goes
     * back to the broker unacknowledged, and the run finishes instead of sitting on a
     * consumer the broker will never send another message to.
     */
    public function testAFailingHandlerEndsItsCoroutineAndReleasesTheMessage(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue($channel, (string) $queue->getName(), 'boom');

        $failures = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([(string) $queue->getName() => 1]),
            pollIntervalMs: 50,
        );

        $count = $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (AMQPEnvelope $envelope, AMQPQueue $queue): void {
                throw new RuntimeException('handler blew up on ' . $envelope->getBody());
            },
            onError: static function (Throwable $exception, AMQPEnvelope $envelope) use (&$failures): void {
                $failures[$envelope->getBody()] = $exception->getMessage();
            },
        );

        self::assertSame(1, $count, 'the message was handled, badly');
        self::assertSame(['boom' => 'handler blew up on boom'], $failures);

        // Never acknowledged, so closing the channel handed it straight back.
        self::assertNotNull($this->waitForMessage($queue, timeoutSeconds: 2.0));
    }

    /**
     * One poisoned coroutine costs its own capacity, not the worker's: the other
     * coroutine on the same queue keeps going.
     */
    public function testTheOtherCoroutinesSurviveAFailingHandler(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue($channel, (string) $queue->getName(), 'boom');
        $this->publishToQueue($channel, (string) $queue->getName(), 'fine');

        $handled = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([(string) $queue->getName() => 2]),
            maxMessages: 1,
            pollIntervalMs: 50,
        );

        $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (AMQPEnvelope $envelope, AMQPQueue $queue) use (&$handled): void {
                if ($envelope->getBody() === 'boom') {
                    throw new RuntimeException('handler blew up');
                }

                $handled[] = $envelope->getBody();

                $queue->ack($envelope->getDeliveryTag());
            },
            onError: static function (): void {
            },
        );

        self::assertSame(['fine'], $handled);
    }

    /**
     * The drain: reaching maxMessages stops the consumers that are working, and the
     * supervisor then ends the ones still waiting for a delivery. A queue left with
     * messages in it must not hold the run open.
     */
    public function testTheRunEndsWhileOtherQueuesStayIdle(): void
    {
        $channel = $this->channel();

        $busy = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);
        $idle = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue($channel, (string) $busy->getName(), 'only-one');

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([
                (string) $busy->getName() => 1,
                (string) $idle->getName() => 3,
            ]),
            maxMessages: 1,
            drainTimeoutMs: 1000,
            pollIntervalMs: 50,
        );

        $startedAt = microtime(true);

        $count = $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (AMQPEnvelope $envelope, AMQPQueue $queue): void {
                $queue->ack($envelope->getDeliveryTag());
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

        $queue = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue($channel, (string) $queue->getName(), 'slow');

        $acknowledged = false;

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson([(string) $queue->getName() => 1]),
            maxMessages: 1,
            drainTimeoutMs: 2000,
            pollIntervalMs: 50,
        );

        $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (AMQPEnvelope $envelope, AMQPQueue $queue) use (&$acknowledged): void {
                Sleeper::usleep(microseconds: 300_000);

                $queue->ack($envelope->getDeliveryTag());

                $acknowledged = true;
            },
        );

        self::assertTrue($acknowledged, 'the in-flight handler must run to completion');

        // Acknowledged means gone: nothing comes back on a fresh get().
        self::assertNull($this->waitForMessage($queue, timeoutSeconds: 0.3));
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
