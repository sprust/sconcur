<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPEnvelope;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Features\Amqp\AMQPQueueException;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\WaitGroup;
use const SConcur\Features\Amqp\AMQP_DURABLE;
use const SConcur\Features\Amqp\AMQP_JUST_CONSUME;

/**
 * consume() — the reason the feature exists: the wait for the next delivery suspends the
 * coroutine, not the worker, so several queues are pulled at the same time in one process.
 */
class AmqpConsumeTest extends AmqpTestCase
{
    public function testTheCallbackReceivesTheDeliveriesUntilItReturnsFalse(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $queueName = (string) $queue->getName();

        foreach (['one', 'two', 'three'] as $body) {
            $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: $body);
        }

        $received = [];

        $queue->consume(callback: function (AMQPEnvelope $envelope, AMQPQueue $queue) use (&$received): bool {
            $received[] = $envelope->getBody();

            $queue->ack($envelope->getDeliveryTag());

            return count($received) < 2;
        });

        self::assertSame(['one', 'two'], $received);
        self::assertNotNull($queue->getConsumerTag());

        $queue->cancel();

        self::assertNull($queue->getConsumerTag());

        // The third message was delivered but never acknowledged. AMQP puts such a message
        // back when the channel goes away, not when the consumer is cancelled, so it
        // reappears only after the channel is closed.
        $channel->close();

        $another = new AMQPQueue($this->channel());

        $another->setName($queueName);
        $another->setFlags(AMQP_DURABLE);

        self::assertSame(1, $this->waitForMessageCount(queue: $another, expected: 1));
    }

    public function testTheCallbackAlsoReceivesTheQueueItConsumes(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'one');

        $seen = null;

        $queue->consume(callback: function (AMQPEnvelope $envelope, AMQPQueue $consuming) use (&$seen): bool {
            $seen = $consuming;

            $consuming->ack($envelope->getDeliveryTag());

            return false;
        });

        self::assertSame($queue, $seen);

        $queue->cancel();
    }

    public function testJustConsumeReadsOnWithoutOpeningAnotherConsumer(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'first');

        $queue->consume(callback: function (AMQPEnvelope $envelope, AMQPQueue $queue): bool {
            $queue->ack($envelope->getDeliveryTag());

            return false;
        });

        $consumerTag = $queue->getConsumerTag();

        self::assertNotNull($consumerTag);

        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'second');

        $received = null;

        $queue->consume(callback: function (AMQPEnvelope $envelope, AMQPQueue $queue) use (&$received): bool {
            $received = $envelope->getBody();

            $queue->ack($envelope->getDeliveryTag());

            return false;
        }, flags: AMQP_JUST_CONSUME);

        self::assertSame('second', $received);
        // No second basic.consume was sent: the tag is the one from the first call.
        self::assertSame($consumerTag, $queue->getConsumerTag());

        $queue->cancel();
    }

    public function testJustConsumeNeedsAConsumerThatIsAlreadyOpen(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->expectException(AMQPQueueException::class);

        $queue->consume(callback: fn(): bool => false, flags: AMQP_JUST_CONSUME);
    }

    public function testSeveralQueuesAreConsumedAtTheSameTime(): void
    {
        $channel = $this->channel();

        $queues = [];

        for ($index = 0; $index < 3; ++$index) {
            $queues[] = (string) $this->declareQueue(channel: $channel, flags: AMQP_DURABLE)->getName();
        }

        $connection = $this->connection();

        $waitGroup = WaitGroup::create();

        // Every consumer waits for a message that is only published later, from another
        // coroutine. Sequential consumers would each wait out the delay in turn; running
        // at the same time they all finish within one delay.
        foreach ($queues as $queueName) {
            $waitGroup->add(function () use ($connection, $queueName): string {
                $channel = new AMQPChannel($connection);

                $queue = new AMQPQueue($channel);

                $queue->setName($queueName);
                $queue->setFlags(AMQP_DURABLE);
                $queue->declareQueue();

                $body = '';

                $queue->consume(callback: function (AMQPEnvelope $envelope, AMQPQueue $queue) use (&$body): bool {
                    $body = $envelope->getBody();

                    $queue->ack($envelope->getDeliveryTag());

                    return false;
                });

                $queue->cancel();
                $channel->close();

                // The queue it consumed from travels with the body: a consumer that was
                // fed another queue's message would be invisible otherwise.
                return "$queueName=$body";
            });
        }

        $waitGroup->add(function () use ($connection, $queues): string {
            Sleeper::usleep(microseconds: 200_000);

            $channel = new AMQPChannel($connection);

            foreach ($queues as $queueName) {
                $this->publishToQueue(channel: $channel, queueName: $queueName, body: "for $queueName");
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

    public function testStoppingTheFlowEndsTheConsumer(): void
    {
        $connection = $this->connection();
        $channel    = $this->channel();
        $queue      = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $queueName = (string) $queue->getName();

        $waitGroup = WaitGroup::create();

        $waitGroup->add(function () use ($connection, $queueName): string {
            $channel = new AMQPChannel($connection);

            $queue = new AMQPQueue($channel);

            $queue->setName($queueName);
            $queue->setFlags(AMQP_DURABLE);
            $queue->declareQueue();

            // Nothing is ever published here: the consumer waits until the group is
            // stopped, which cancels it on the Go side.
            $queue->consume(callback: fn(): bool => true);

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
        self::assertSame(0, $queue->declareQueue());
    }
}
