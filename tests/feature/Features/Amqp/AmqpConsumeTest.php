<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Exceptions\Amqp\QueueException;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Impl\TestAmqpResolver;
use SConcur\WaitGroup;

/**
 * consume() — the reason the feature exists: the wait for the next delivery suspends the
 * coroutine, not the worker, so several queues are pulled at the same time in one process.
 */
class AmqpConsumeTest extends AmqpTestCase
{
    public function testTheLoopReceivesTheDeliveriesUntilItBreaks(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, durable: true);

        $queueName = $queue->name();

        foreach (['one', 'two', 'three'] as $body) {
            $this->publishToQueue(channel: $channel, queueName: $queueName, message: $body);
        }

        $received = [];

        foreach ($queue->consume() as $delivery) {
            $received[] = $delivery->body;

            $delivery->ack();

            if (count($received) === 2) {
                break;
            }
        }

        self::assertSame(['one', 'two'], $received);

        // The third message was delivered but never acknowledged. AMQP puts such a message
        // back when the channel goes away, not when the consumer is cancelled, so it
        // reappears only after the channel is closed.
        $channel->close();

        $another = $this->channel()->queue($queueName);

        self::assertSame(1, $this->waitForMessageCount(queue: $another, expected: 1));
    }

    /**
     * The generator owns the consumer: leaving the loop cancels it and gives the delivery
     * stream back, with no cancel() call of the caller's own. The calque could not do this
     * — a callback has no scope to end — and needed a registry on the channel instead.
     */
    public function testLeavingTheLoopCancelsTheConsumer(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue(channel: $channel, queueName: $queue->name(), message: 'one');

        foreach ($queue->consume() as $delivery) {
            $delivery->ack();

            break;
        }

        $deadline = microtime(true) + 2.0;

        do {
            $consumers = $queue->declarePassive()->consumerCount;

            if ($consumers === 0) {
                break;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::assertSame(0, $consumers, 'the consumer must be gone with the loop that owned it');
    }

    /**
     * Two consumes in a row on one channel, which is what a worker switching between
     * queues does. The first has to be cancelled before the second opens: a cancel still
     * in flight leaves the old consumer registered, the broker may hand the message to the
     * stream nobody reads any more, and the second consume waits for a delivery that was
     * already given away. Found by the `consume-async` soak, which hung on it.
     */
    public function testASecondConsumeOnTheSameChannelGetsItsMessage(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, durable: true);

        $received = [];

        for ($round = 0; $round < 5; ++$round) {
            $this->publishToQueue(channel: $channel, queueName: $queue->name(), message: "round-$round");

            foreach ($queue->consume() as $delivery) {
                $received[] = $delivery->body;

                $delivery->ack();

                break;
            }
        }

        self::assertSame(['round-0', 'round-1', 'round-2', 'round-3', 'round-4'], $received);
    }

    public function testADeliveryKnowsWhichConsumerBroughtIt(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue(channel: $channel, queueName: $queue->name(), message: 'one');

        foreach ($queue->consume(consumerTag: 'tag-of-mine') as $delivery) {
            self::assertSame('tag-of-mine', $delivery->consumerTag);

            $delivery->ack();

            break;
        }
    }

    public function testSeveralQueuesAreConsumedAtTheSameTime(): void
    {
        $channel = $this->channel();

        $queues = [];

        for ($index = 0; $index < 3; ++$index) {
            $queues[] = $this->declareQueue(channel: $channel, durable: true)->name();
        }

        $connection = $this->connection();

        $waitGroup = WaitGroup::create();

        // Every consumer waits for a message that is only published later, from another
        // coroutine. Sequential consumers would each wait out the delay in turn; running
        // at the same time they all finish within one delay.
        foreach ($queues as $queueName) {
            $waitGroup->add(function () use ($connection, $queueName): string {
                $channel = $connection->channel();

                $body = '';

                foreach ($channel->queue($queueName)->consume() as $delivery) {
                    $body = $delivery->body;

                    $delivery->ack();

                    break;
                }

                $channel->close();

                // The queue it consumed from travels with the body: a consumer that was
                // fed another queue's message would be invisible otherwise.
                return "$queueName=$body";
            });
        }

        $waitGroup->add(function () use ($connection, $queues): string {
            Sleeper::usleep(microseconds: 200_000);

            $channel = $connection->channel();

            foreach ($queues as $queueName) {
                $this->publishToQueue(channel: $channel, queueName: $queueName, message: "for $queueName");
            }

            $channel->close();

            return 'published';
        });

        $startTime = microtime(true);

        $results = [];

        foreach ($waitGroup->iterate() as $result) {
            $results[] = $result;
        }

        $elapsedMs = (microtime(true) - $startTime) * 1000;

        sort($results);

        $expected = array_map(
            fn(string $queueName): string => "$queueName=for $queueName",
            $queues,
        );

        $expected[] = 'published';

        sort($expected);

        // Sorted because the order the coroutines finish in is the scheduler's business;
        // the pairing of queue and body is what this checks.
        self::assertSame($expected, $results);

        // Three consumers waiting 200 ms in turn would take 600 ms; concurrently they
        // take one delay plus the round trips.
        self::assertLessThan(500, $elapsedMs, "three consumers took {$elapsedMs}ms, which looks sequential");
    }

    /**
     * A stop unwinds the coroutine at its suspension point, which is inside the generator.
     * Its teardown has to run there without awaiting anything: the scheduler has already
     * detached the fiber, so a cancel that waited for the broker would suspend with
     * nothing left to resume it.
     */
    public function testStoppingTheFlowEndsTheConsumer(): void
    {
        $connection = $this->connection();
        $channel    = $this->channel();
        $queue      = $this->declareQueue(channel: $channel, durable: true);

        $queueName = $queue->name();

        $waitGroup = WaitGroup::create();

        $waitGroup->add(function () use ($connection, $queueName): string {
            $channel = $connection->channel();

            // Nothing is ever published here: the consumer waits until the group is
            // stopped, which unwinds it inside the generator.
            foreach ($channel->queue($queueName)->consume() as $delivery) {
                $delivery->ack();
            }

            return 'ended';
        });

        $waitGroup->add(function () use ($waitGroup): string {
            Sleeper::usleep(microseconds: 100_000);

            $waitGroup->stop();

            return 'stopped';
        });

        $waitGroup->waitAll();

        // The consumer is gone with the flow, so the queue is free to be deleted (an open
        // consumer would keep it in use) and tearDown's task check finds nothing dangling.
        self::assertSame(0, $queue->declarePassive()->messageCount);
    }

    /**
     * A consumer that waits longer than the connection's read timeout is a failure, not a
     * quiet end: the broker is still holding the consumer, and a worker that treated the
     * silence as "the queue is closed" would stop reading a queue that is merely idle.
     */
    public function testTheReadTimeoutFailsTheConsumer(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, durable: true);

        $idle = new Connection(TestAmqpResolver::getOptions(readTimeout: 0.3));

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Consumer timeout exceed');

        try {
            foreach ($idle->channel()->queue($queue->name())->consume() as $delivery) {
                $delivery->ack();
            }
        } finally {
            $idle->close();
        }
    }
}
