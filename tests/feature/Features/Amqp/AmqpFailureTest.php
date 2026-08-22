<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPChannelException;
use SConcur\Features\Amqp\AMQPConnection;
use SConcur\Features\Amqp\AMQPConnectionException;
use SConcur\Features\Amqp\AMQPEnvelope;
use SConcur\Features\Amqp\AMQPExchange;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Features\Amqp\AMQPQueueException;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Impl\TestAmqpResolver;
use SConcur\WaitGroup;
use Throwable;
use const SConcur\Features\Amqp\AMQP_DURABLE;
use const SConcur\Features\Amqp\AMQP_MANDATORY;
use const SConcur\Features\Amqp\AMQP_PASSIVE;
use const SConcur\Features\Amqp\AMQP_SASL_METHOD_EXTERNAL;
use const SConcur\Features\Amqp\AMQP_SASL_METHOD_PLAIN;

/**
 * What happens when things go wrong: the reply code an application branches on, the state
 * a channel is left in after the broker closes it, and the waits that must end instead of
 * parking a coroutine forever.
 */
class AmqpFailureTest extends AmqpTestCase
{
    public function testAFailureCarriesTheReplyCodeTheBrokerNamed(): void
    {
        $channel = $this->channel();

        $queue = new AMQPQueue($channel);

        $queue->setName(TestAmqpResolver::uniqueName('missing'));
        $queue->setFlags(AMQP_PASSIVE);

        try {
            $queue->declareQueue();

            self::fail('a passive declare of a missing queue must fail');
        } catch (AMQPQueueException $exception) {
            // The idiom this keeps working: catch, look at the code, declare and retry.
            self::assertSame(404, $exception->getCode());
            self::assertStringContainsString('NOT_FOUND', $exception->getMessage());
        }
    }

    public function testAChannelTheBrokerClosedIsReportedClosed(): void
    {
        $connection = $this->connection();
        $channel    = new AMQPChannel($connection);

        $queue = new AMQPQueue($channel);

        $queue->setName(TestAmqpResolver::uniqueName('missing'));
        $queue->setFlags(AMQP_PASSIVE);

        try {
            $queue->declareQueue();
        } catch (AMQPQueueException) {
            // The 404 above is what closes the channel.
        }

        self::assertFalse($channel->isConnected(), 'a channel the broker closed must report itself closed');

        // Every later call is refused locally, with the message the extension uses.
        try {
            new AMQPQueue($channel);

            self::fail('a queue cannot be built on a closed channel');
        } catch (AMQPChannelException $exception) {
            self::assertStringContainsString('No channel available.', $exception->getMessage());
        }

        // And the channel is gone on the Go side too, instead of waiting for the sweeper.
        self::assertSame(0, $connection->getUsedChannels());
    }

    public function testAConnectionLevelFailureIsReportedAsOne(): void
    {
        // Its own connection, named so the pool gives it one: this test kills the
        // connection, and the pooled one is shared by every other AMQP test.
        $connection = new AMQPConnection(
            TestAmqpResolver::getCredentials() + ['connection_name' => 'connection-failure-probe'],
        );

        $connection->connect();

        $channel = new AMQPChannel($connection);

        try {
            // RabbitMQ answers requeue=false with 540 NOT_IMPLEMENTED, which is a
            // connection-level reply code.
            $channel->basicRecover(requeue: false);

            self::fail('RabbitMQ does not implement recover without requeue');
        } catch (AMQPConnectionException $exception) {
            self::assertSame(540, $exception->getCode());
            self::assertStringContainsString('NOT_IMPLEMENTED', $exception->getMessage());
        }

        // The extension reports the connection as gone, and so does the calque.
        self::assertFalse($connection->isConnected());
        self::assertFalse($channel->isConnected());

        // Opening a channel on it is refused the way the extension refuses it.
        try {
            new AMQPChannel($connection);

            self::fail('a channel cannot be opened on a connection that died');
        } catch (AMQPConnectionException $exception) {
            self::assertStringContainsString('No connection available.', $exception->getMessage());
        }

        // The handle is still handed back, so the pooled connection behind it is released.
        $connection->disconnect();
    }

    public function testChannelNumbersAreNotHandedOutTwice(): void
    {
        $connection = $this->connection();

        $first  = new AMQPChannel($connection);
        $second = new AMQPChannel($connection);

        $first->close();

        $third = new AMQPChannel($connection);

        self::assertNotSame(
            $second->getChannelId(),
            $third->getChannelId(),
            'a closed channel must not hand its number to the next one',
        );

        $second->close();
        $third->close();
    }

    public function testWaitingForConfirmsEndsAfterAPublishThatFailed(): void
    {
        $channel = $this->channel();

        $exchange = new AMQPExchange($channel);

        $exchange->setName(TestAmqpResolver::uniqueName('missing'));

        $channel->confirmSelect();

        try {
            // Publishing to an exchange that does not exist kills the channel, so this
            // message is never confirmed.
            $exchange->publish(message: 'nowhere', routingKey: 'key');
        } catch (Throwable) {
            // The failure itself is not what this test is about.
        }

        // Waiting must end instead of counting forever on a confirmation that cannot come.
        $this->expectException(AMQPChannelException::class);

        $channel->waitForConfirm();
    }

    public function testWaitingForConfirmsWithoutConfirmModeRunsIntoItsTimeout(): void
    {
        $channel = $this->channel();

        try {
            $channel->waitForConfirm(timeout: 0.2);

            self::fail('a channel that is not in confirm mode has nothing to confirm');
        } catch (AMQPQueueException $exception) {
            self::assertStringContainsString('Wait timeout exceed', $exception->getMessage());
        }
    }

    public function testWaitingForReturnsKeepsThePublisherConfirms(): void
    {
        $channel  = $this->channel();
        $exchange = $this->declareExchange($channel);

        $channel->confirmSelect();

        $confirmed = [];

        $channel->setConfirmCallback(
            function (int $deliveryTag, bool $multiple) use (&$confirmed): bool {
                $confirmed[] = $deliveryTag;

                return true;
            },
            null,
        );

        // Nothing is bound, so the message is confirmed and returned at the same time.
        $exchange->publish(message: 'nowhere', routingKey: 'unbound', flags: AMQP_MANDATORY);

        $channel->waitForBasicReturn(timeout: 2.0);

        self::assertSame([], $confirmed, 'the return wait must not run the confirm callbacks');

        // The confirmation is still there for the wait that is counting on it.
        $channel->waitForConfirm(timeout: 2.0);

        self::assertSame([1], $confirmed);
    }

    public function testAPrefetchSetterOnAClosedChannelIsRefused(): void
    {
        $channel = new AMQPChannel($this->connection());

        $channel->close();

        $this->expectException(AMQPConnectionException::class);

        $channel->setPrefetchCount(5);
    }

    public function testAConsumerTheBrokerCancelsEndsTheLoopWithAFailure(): void
    {
        $connection = $this->connection();
        $channel    = $this->channel();
        $queue      = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $queueName = (string) $queue->getName();

        $waitGroup = WaitGroup::create();

        $waitGroup->add(function () use ($connection, $queueName): string {
            $channel = new AMQPChannel($connection);

            $consuming = new AMQPQueue($channel);

            $consuming->setName($queueName);
            $consuming->setFlags(AMQP_DURABLE);
            $consuming->declareQueue();

            try {
                $consuming->consume(callback: fn(AMQPEnvelope $envelope): bool => true);
            } catch (AMQPQueueException $exception) {
                return 'failed: ' . $exception->getMessage();
            }

            return 'returned quietly';
        });

        $waitGroup->add(function () use ($connection, $queueName): string {
            Sleeper::usleep(microseconds: 150_000);

            $channel = new AMQPChannel($connection);

            $deleting = new AMQPQueue($channel);

            $deleting->setName($queueName);
            $deleting->delete();

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
        $queue      = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $queueName = (string) $queue->getName();

        // One channel is open: the one this test works on.
        $baseline = $connection->getUsedChannels();

        for ($round = 0; $round < 5; ++$round) {
            $waitGroup = WaitGroup::create();

            $waitGroup->add(static function () use ($connection, $queueName): string {
                $channel = new AMQPChannel($connection);

                $consuming = new AMQPQueue($channel);

                $consuming->setName($queueName);
                $consuming->setFlags(AMQP_DURABLE);
                $consuming->declareQueue();

                // Nothing is published: the coroutine is still waiting here when the group
                // is stopped, so it never reaches a close of its own.
                $consuming->consume(callback: static fn(AMQPEnvelope $envelope): bool => true);

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

    public function testAQueueTheApplicationDroppedLeavesTheConsumerRegistry(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $consuming = new AMQPQueue($channel);

        $consuming->setName((string) $queue->getName());
        $consuming->setFlags(AMQP_DURABLE);
        $consuming->consume();

        self::assertCount(1, $channel->getConsumers());

        unset($consuming);

        // The registry holds its consumers weakly: a queue the application dropped takes
        // its stream — and the channel it was consuming on — with it.
        self::assertSame([], $channel->getConsumers());
    }

    public function testCredentialsAreValidatedTheWayTheExtensionValidatesThem(): void
    {
        $connection = new AMQPConnection();

        $connection->setSaslMethod(AMQP_SASL_METHOD_EXTERNAL);

        self::assertSame(AMQP_SASL_METHOD_EXTERNAL, $connection->getSaslMethod());

        try {
            $connection->setSaslMethod(7);

            self::fail('only PLAIN and EXTERNAL exist');
        } catch (AMQPConnectionException $exception) {
            self::assertStringContainsString('Invalid SASL method', $exception->getMessage());
        }

        self::assertSame(AMQP_SASL_METHOD_EXTERNAL, $connection->getSaslMethod());
    }

    public function testABlankCredentialLeavesTheDefaultInPlace(): void
    {
        $connection = new AMQPConnection([
            'host'     => '',
            'login'    => '',
            'password' => '',
            'vhost'    => '',
        ]);

        // A blank environment variable must not connect nowhere as nobody.
        self::assertSame('localhost', $connection->getHost());
        self::assertSame('guest', $connection->getLogin());
        self::assertSame('guest', $connection->getPassword());
        self::assertSame('/', $connection->getVhost());
        self::assertSame(AMQP_SASL_METHOD_PLAIN, $connection->getSaslMethod());
    }

    /**
     * After a reconnect the old channels are gone on the broker, and they must say so:
     * the ext-amqp idiom that reopens a channel only when isConnected() is false would
     * otherwise never fire and every later command would fail on a dead channel.
     */
    public function testChannelsOfAReconnectedConnectionReportThemselvesClosed(): void
    {
        $connection = $this->connection();

        $channel = new AMQPChannel($connection);

        self::assertTrue($channel->isConnected());

        $connection->reconnect();

        self::assertFalse($channel->isConnected(), 'a channel of the old handle is not open');
        self::assertTrue($connection->isConnected());
    }

    /**
     * The channel count the broker settles on: a channel released by a destructor is
     * closed without waiting for the broker, so the count catches up a moment later.
     */
    protected function waitForUsedChannels(AMQPConnection $connection, int $expected, float $timeoutSeconds = 2.0): int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $used = $connection->getUsedChannels();

            if ($used === $expected) {
                return $used;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        return $used;
    }
}
