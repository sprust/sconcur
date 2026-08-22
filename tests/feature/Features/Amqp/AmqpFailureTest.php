<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Exceptions\Amqp\ChannelException;
use SConcur\Exceptions\Amqp\ConnectionException;
use SConcur\Exceptions\Amqp\QueueException;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\ConnectionOptions;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Impl\TestAmqpResolver;
use SConcur\WaitGroup;
use Throwable;

/**
 * What happens when things go wrong: the reply code an application branches on, the state
 * a channel is left in after the broker closes it, and the waits that must end instead of
 * parking a coroutine forever.
 */
class AmqpFailureTest extends AmqpTestCase
{
    public function testAFailureCarriesTheReplyCodeTheBrokerNamed(): void
    {
        $queue = $this->channel()->queue(TestAmqpResolver::uniqueName('missing'));

        try {
            $queue->declarePassive();

            self::fail('a passive declare of a missing queue must fail');
        } catch (QueueException $exception) {
            // The idiom this keeps working: catch, look at the code, declare and retry.
            self::assertSame(404, $exception->getCode());
            self::assertStringContainsString('NOT_FOUND', $exception->getMessage());
        }
    }

    public function testAChannelTheBrokerClosedIsReportedClosed(): void
    {
        $connection = $this->connection();
        $channel    = $connection->channel();

        try {
            $channel->queue(TestAmqpResolver::uniqueName('missing'))->declarePassive();
        } catch (QueueException) {
            // The 404 above is what closes the channel.
        }

        self::assertFalse($channel->isOpen(), 'a channel the broker closed must report itself closed');

        // Every later call is refused locally rather than sent.
        try {
            $channel->queue('anything')->declare();

            self::fail('a command cannot run on a closed channel');
        } catch (ChannelException $exception) {
            self::assertStringContainsString('No channel available.', $exception->getMessage());
        }

        // And the channel is gone on the Go side too, instead of waiting for the sweeper.
        self::assertSame(0, $connection->usedChannels());
    }

    public function testAConnectionLevelFailureIsReportedAsOne(): void
    {
        // Its own connection, named so the pool gives it one: this test kills the
        // connection, and the pooled one is shared by every other AMQP test.
        $connection = new Connection(new ConnectionOptions(
            host: (string) $_ENV['RABBITMQ_HOST'],
            port: (int) $_ENV['RABBITMQ_PORT'],
            login: (string) $_ENV['RABBITMQ_USER'],
            password: (string) $_ENV['RABBITMQ_PASSWORD'],
            vhost: (string) $_ENV['RABBITMQ_VHOST'],
            connectionName: 'connection-failure-probe',
        ));

        $connection->connect();

        $channel = $connection->channel();

        try {
            // RabbitMQ has never implemented basic.qos's prefetch_size, and answers one
            // with 540 NOT_IMPLEMENTED — a connection-level reply code.
            $channel->prefetch(count: 1, size: 1024);

            self::fail('RabbitMQ does not implement a prefetch size');
        } catch (ConnectionException $exception) {
            self::assertSame(540, $exception->getCode());
            self::assertStringContainsString('NOT_IMPLEMENTED', $exception->getMessage());
        }

        // A connection-level failure takes the connection, not just the channel that ran
        // into it.
        self::assertFalse($connection->isOpen());
        self::assertFalse($channel->isOpen());

        try {
            $connection->channel();

            self::fail('a channel cannot be opened on a connection that died');
        } catch (ConnectionException $exception) {
            self::assertStringContainsString('No connection available.', $exception->getMessage());
        }

        // The handle is still handed back, so the pooled connection behind it is released.
        $connection->close();
    }

    public function testChannelNumbersAreNotHandedOutTwice(): void
    {
        $connection = $this->connection();

        $first  = $connection->channel();
        $second = $connection->channel();

        $first->close();

        $third = $connection->channel();

        self::assertNotSame(
            $second->id(),
            $third->id(),
            'a closed channel must not hand its number to the next one',
        );

        $second->close();
        $third->close();
    }

    public function testWaitingForConfirmsEndsAfterAPublishThatFailed(): void
    {
        $channel = $this->channel();

        $channel->enableConfirms();

        try {
            // Publishing to an exchange that does not exist kills the channel, so this
            // message is never confirmed.
            $channel->publish(message: 'nowhere', exchange: TestAmqpResolver::uniqueName('missing'));
        } catch (Throwable) {
            // The failure itself is not what this test is about.
        }

        // Waiting must end instead of counting forever on a confirmation that cannot come.
        $this->expectException(ChannelException::class);

        $channel->waitForConfirms();
    }

    public function testSettingThePrefetchOnAClosedChannelIsRefused(): void
    {
        $channel = $this->connection()->channel();

        $channel->close();

        $this->expectException(ChannelException::class);

        $channel->prefetch(count: 5);
    }

    public function testAConsumerTheBrokerCancelsEndsTheLoopWithAFailure(): void
    {
        $connection = $this->connection();
        $channel    = $this->channel();
        $queue      = $this->declareQueue(channel: $channel, durable: true);

        $queueName = $queue->name();

        $waitGroup = WaitGroup::create();

        $waitGroup->add(function () use ($connection, $queueName): string {
            $consuming = $connection->channel()->queue($queueName);

            try {
                foreach ($consuming->consume() as $delivery) {
                    $delivery->ack();
                }
            } catch (QueueException $exception) {
                return 'failed: ' . $exception->getMessage();
            }

            return 'returned quietly';
        });

        $waitGroup->add(function () use ($connection, $queueName): string {
            Sleeper::usleep(microseconds: 150_000);

            $channel = $connection->channel();

            $channel->queue($queueName)->delete();

            $channel->close();

            return 'deleted';
        });

        $results = [];

        foreach ($waitGroup->iterate() as $result) {
            $results[] = $result;
        }

        $this->declaredQueues = [];

        $consumerResult = array_values(array_filter(
            $results,
            static fn(string $result): bool => $result !== 'deleted',
        ));

        // A worker looping over consume() must learn that its consumer is gone; returning
        // quietly would spin the loop at full speed.
        self::assertStringStartsWith('failed:', $consumerResult[0]);
        self::assertStringContainsString('cancelled by the broker', $consumerResult[0]);
    }

    public function testAStoppedCoroutineDoesNotLeaveItsChannelOpen(): void
    {
        $connection = $this->connection();
        $channel    = $this->channel();
        $queue      = $this->declareQueue(channel: $channel, durable: true);

        $queueName = $queue->name();

        // One channel is open: the one this test works on.
        $baseline = $connection->usedChannels();

        for ($round = 0; $round < 5; ++$round) {
            $waitGroup = WaitGroup::create();

            $waitGroup->add(static function () use ($connection, $queueName): string {
                $channel = $connection->channel();

                // Nothing is published: the coroutine is still waiting inside the
                // generator when the group is stopped, so it never reaches a close of its
                // own.
                foreach ($channel->queue($queueName)->consume() as $delivery) {
                    $delivery->ack();
                }

                return 'ended';
            });

            $waitGroup->add(static function () use ($waitGroup): string {
                Sleeper::usleep(microseconds: 20_000);

                $waitGroup->stop();

                return 'stopped';
            });

            $waitGroup->waitAll();
        }

        // A channel per stopped coroutine would exhaust the connection's channel ids in a
        // few thousand rounds, and nothing would ever close them.
        self::assertSame(
            $baseline,
            $this->waitForUsedChannels($connection, $baseline),
            'a stopped coroutine must not leave its channel open',
        );
    }

    /**
     * After reconnecting the old channels are gone on the broker, and they must say so: a
     * guard that reopens a channel only when isOpen() is false would otherwise never fire
     * and every later command would fail on a dead channel.
     */
    public function testChannelsOfAReconnectedConnectionReportThemselvesClosed(): void
    {
        $connection = $this->connection();

        $channel = $connection->channel();

        self::assertTrue($channel->isOpen());

        $connection->close();
        $connection->connect();

        self::assertFalse($channel->isOpen(), 'a channel of the old handle is not open');
        self::assertTrue($connection->isOpen());
    }

    /**
     * The channel count the broker settles on: a channel released by a destructor is
     * closed without waiting for the broker, so the count catches up a moment later.
     */
    protected function waitForUsedChannels(Connection $connection, int $expected, float $timeoutSeconds = 2.0): int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $used = $connection->usedChannels();

            if ($used === $expected) {
                return $used;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        return $used;
    }
}
