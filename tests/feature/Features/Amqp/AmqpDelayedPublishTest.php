<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Exceptions\Amqp\UnroutableMessageException;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\ConnectionOptions;
use SConcur\Features\Amqp\Message;
use SConcur\Features\Amqp\RetryTopology;
use SConcur\Features\Amqp\Queue;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\WaitGroup;

/**
 * `publish(delayMs: …)` and the retries of a publish the broker refused, against the real
 * broker: the delay is a round trip through a wait queue, and a retry is another publish.
 */
class AmqpDelayedPublishTest extends AmqpTestCase
{
    public function testADelayedMessageArrivesOnlyAfterItsDelay(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, durable: true);

        $this->declareWaitQueues(queue: $queue, delaysMs: [400]);

        $publishedAt = microtime(true);

        $queue->publish('later', delayMs: 400);

        // It is in the wait queue, not here — the whole point of the delay.
        $this->assertQueueStaysEmpty(queue: $queue, forSeconds: 0.2);

        $delivery = $this->waitForMessage($queue);

        self::assertNotNull($delivery, 'the delayed message must come back');
        self::assertSame('later', $delivery->body);

        $waitedMs = (microtime(true) - $publishedAt) * 1000;

        self::assertGreaterThanOrEqual(400, $waitedMs, "it came back after {$waitedMs}ms");

        $delivery->ack();
    }

    /**
     * The whole point of doing the waiting on the broker: a message in a wait queue is the
     * broker's, and the worker that published it can go away entirely — restart, deploy,
     * OOM — without the message noticing.
     */
    public function testADelayedMessageWaitsOnTheBrokerNotInTheWorker(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, durable: true);

        $this->declareWaitQueues(queue: $queue, delaysMs: [600]);

        $publisher = $this->ownConnection('delayed-publish-worker');

        $publisher->channel()->queue($queue->name())->publish(
            new Message('outlives its publisher', persistent: true),
            delayMs: 600,
        );

        // The worker is gone before the delay is up: channel, connection, socket.
        $publisher->close();

        self::assertFalse($publisher->isOpen());

        $delivery = $this->waitForMessage($queue, timeoutSeconds: 3.0);

        self::assertNotNull($delivery, 'the message must have waited on the broker');
        self::assertSame('outlives its publisher', $delivery->body);

        $delivery->ack();
    }

    /**
     * The delay is counted by the broker from the moment the message entered the wait
     * queue, and nothing a client does restarts it. A worker that dies three seconds into a
     * ten-second wait leaves seven seconds, not ten.
     */
    public function testTheDelayIsNotRestartedByAReconnect(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, durable: true);

        $this->declareWaitQueues(queue: $queue, delaysMs: [1_000]);

        $publisher = $this->ownConnection('delay-reconnect-worker');

        $publishedAt = microtime(true);

        $publisher->channel()->queue($queue->name())->publish('halfway', delayMs: 1_000);

        // Most of the way through the wait, then the worker is gone.
        usleep(600_000);

        $publisher->close();

        $delivery = $this->waitForMessage($queue, timeoutSeconds: 3.0);

        $elapsedMs = (microtime(true) - $publishedAt) * 1000;

        self::assertNotNull($delivery, 'the message must still come back');

        // ~1000 ms if the broker kept counting, ~1600 if the reconnect had restarted it.
        self::assertGreaterThanOrEqual(1_000, $elapsedMs, "it came back after {$elapsedMs}ms");
        self::assertLessThan(1_500, $elapsedMs, "the delay was restarted, not continued ({$elapsedMs}ms)");

        $delivery->ack();
    }

    /**
     * A delay the topology does not serve is a usage bug, and the mandatory flag of a
     * confirmed publish is what turns it into one the caller hears about rather than a
     * message that quietly goes nowhere.
     */
    public function testADelayNoWaitQueueServesIsReported(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, durable: true);

        $this->declareWaitQueues(queue: $queue, delaysMs: [400]);

        $this->expectException(UnroutableMessageException::class);

        $queue->publishConfirmed('nowhere', delayMs: 900, timeoutSeconds: 2.0);
    }

    /**
     * The schedule is read by attempt number, so the waits between three attempts are the
     * first two entries — the last one belongs to a retry that never happens.
     */
    public function testARefusedPublishIsRetriedOnItsSchedule(): void
    {
        $channel = $this->channel();

        // Nothing is bound to it, so every attempt comes back unroutable.
        $missing = $channel->queue('sconcur-no-such-queue-' . uniqid());

        $startedAt = microtime(true);

        try {
            $missing->publishConfirmed(
                message: 'never lands',
                timeoutSeconds: 2.0,
                retries: 2,
                retryDelaysSeconds: [0.15, 0.35, 5.0],
            );

            self::fail('a message that routes nowhere must be reported');
        } catch (UnroutableMessageException) {
            // Three attempts, two waits.
        }

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        self::assertGreaterThanOrEqual(500, $elapsedMs, "the schedule was not waited out ({$elapsedMs}ms)");
        self::assertLessThan(3_000, $elapsedMs, "the unused last entry was waited too ({$elapsedMs}ms)");
    }

    /**
     * The retry is a real second publish, not a second look at the first one: a queue that
     * appears between two attempts takes the message.
     */
    public function testARetriedPublishLandsOnceTheQueueAppears(): void
    {
        $channel = $this->channel();
        $name    = 'sconcur-late-queue-' . uniqid();

        $this->declaredQueues[] = $name;

        $waitGroup = WaitGroup::create();

        $waitGroup->add(function () use ($channel, $name): string {
            $channel->queue($name)->publishConfirmed(
                message: 'landed',
                timeoutSeconds: 2.0,
                retries: 10,
                retryDelaysSeconds: [0.1],
            );

            return 'published';
        });

        $waitGroup->add(function () use ($name): string {
            Sleeper::usleep(microseconds: 300_000);

            $this->channel()->queue($name)->declare(durable: true);

            return 'declared';
        });

        $results = array_values($waitGroup->waitResults());

        sort($results);

        // Results come back in completion order, and the publish is the one that waits.
        self::assertSame(['declared', 'published'], $results);

        $delivery = $this->waitForMessage($channel->queue($name));

        self::assertNotNull($delivery, 'the retried publish must have landed');
        self::assertSame('landed', $delivery->body);

        $delivery->ack();
    }

    /** A connection this test owns, so closing it takes nothing else down. */
    protected function ownConnection(string $name): Connection
    {
        $connection = new Connection(new ConnectionOptions(
            host: (string) $_ENV['RABBITMQ_HOST'],
            port: (int) $_ENV['RABBITMQ_PORT'],
            login: (string) $_ENV['RABBITMQ_USER'],
            password: (string) $_ENV['RABBITMQ_PASSWORD'],
            vhost: (string) $_ENV['RABBITMQ_VHOST'],
            connectionName: $name,
        ));

        $connection->connect();

        return $connection;
    }

    /**
     * @param list<int> $delaysMs
     */
    protected function declareWaitQueues(Queue $queue, array $delaysMs): void
    {
        RetryTopology::declare(
            channel: $queue->channel(),
            queue: $queue->name(),
            delaysMs: $delaysMs,
        );

        foreach ($delaysMs as $delayMs) {
            $this->declaredQueues[] = RetryTopology::waitQueueName(
                queue: $queue->name(),
                delayMs: $delayMs,
            );
        }
    }
}
