<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPConnection;
use SConcur\Features\Amqp\AMQPEnvelope;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Features\Amqp\AMQPQueueException;
use SConcur\Tests\Impl\TestAmqpResolver;
use const SConcur\Features\Amqp\AMQP_DURABLE;
use const SConcur\Features\Amqp\AMQP_EXCLUSIVE;
use const SConcur\Features\Amqp\AMQP_IFEMPTY;
use const SConcur\Features\Amqp\AMQP_NOWAIT;
use const SConcur\Features\Amqp\AMQP_PASSIVE;
use const SConcur\Features\Amqp\PHP_AMQP_MAX_CHANNELS;

/**
 * The behaviour docs/amqp.md promises where the calque does not simply repeat ext-amqp,
 * plus the flags the other tests do not reach. Each of these is a claim a reader can act
 * on, so each has a test.
 */
class AmqpDeviationsTest extends AmqpTestCase
{
    public function testTheConnectionIsAlwaysPersistentAndTheSynonymsWork(): void
    {
        $connection = new AMQPConnection(TestAmqpResolver::getCredentials());

        self::assertTrue($connection->isPersistent(), 'a pooled connection is persistent by nature');

        $connection->pconnect();

        self::assertTrue($connection->isConnected());

        $connection->preconnect();

        self::assertTrue($connection->isConnected());

        $connection->pdisconnect();

        self::assertFalse($connection->isConnected());
    }

    public function testReconnectingLeavesAUsableConnection(): void
    {
        $connection = $this->connection();

        $connection->reconnect();

        self::assertTrue($connection->isConnected());

        // The proof that the handle really works again: a channel on it.
        $channel = new AMQPChannel($connection);

        self::assertTrue($channel->isConnected());

        $channel->close();
    }

    public function testTheNegotiatedTuningIsReportedOnceConnected(): void
    {
        $connection = new AMQPConnection(TestAmqpResolver::getCredentials());

        // Before the handshake the requested values stand.
        self::assertSame(PHP_AMQP_MAX_CHANNELS, $connection->getMaxChannels());

        $connection->connect();

        self::assertGreaterThan(0, $connection->getMaxChannels());
        self::assertGreaterThan(0, $connection->getMaxFrameSize());
        self::assertGreaterThanOrEqual(0, $connection->getHeartbeatInterval());

        $connection->disconnect();
    }

    public function testTheOpenChannelsAreCountedOnTheGoSide(): void
    {
        $connection = $this->connection();

        self::assertSame(0, $connection->getUsedChannels());

        $first  = new AMQPChannel($connection);
        $second = new AMQPChannel($connection);

        self::assertSame(2, $connection->getUsedChannels());

        $first->close();

        self::assertSame(1, $connection->getUsedChannels());

        $second->close();

        self::assertSame(0, $connection->getUsedChannels());
    }

    public function testADeliveryReportsNoClusterId(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'body');

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);
        // AMQP 0-9-1 excludes cluster-id from publishing, and the driver does not surface
        // it on a delivery either.
        self::assertNull($envelope->getClusterId());

        $queue->ack($envelope->getDeliveryTag());
    }

    public function testAConsumerGivesUpAfterTheReadTimeout(): void
    {
        $credentials = TestAmqpResolver::getCredentials();

        $credentials['read_timeout'] = 0.3;

        $connection = new AMQPConnection($credentials);

        $connection->connect();

        $channel = new AMQPChannel($connection);

        $queue = new AMQPQueue($channel);

        $queue->setName(TestAmqpResolver::uniqueName('idle'));
        $queue->setFlags(AMQP_DURABLE);
        $queue->declareQueue();

        try {
            // Nothing is ever published: the wait for the first delivery runs out.
            $queue->consume(callback: fn(AMQPEnvelope $envelope): bool => true);

            self::fail('an idle consumer must give up after read_timeout');
        } catch (AMQPQueueException $exception) {
            self::assertStringContainsString('Consumer timeout exceed', $exception->getMessage());
        }

        $queue->delete();

        $channel->close();
        $connection->disconnect();
    }

    public function testTwoConnectionObjectsWithTheSameCredentialsShareOneConnection(): void
    {
        $channel = $this->channel();

        // An exclusive queue belongs to the connection that declared it, which makes it a
        // way to see whether two AMQPConnection objects are really one connection.
        $queue = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE | AMQP_EXCLUSIVE);

        $sharing = new AMQPConnection(TestAmqpResolver::getCredentials());

        $sharing->connect();

        $sharingQueue = new AMQPQueue(new AMQPChannel($sharing));

        $sharingQueue->setName((string) $queue->getName());
        $sharingQueue->setFlags(AMQP_DURABLE | AMQP_EXCLUSIVE);

        // The pool hands both objects the same connection, so the second one is inside the
        // exclusivity rather than outside it.
        self::assertSame(0, $sharingQueue->declareQueue());

        $sharing->disconnect();

        // A connection name is part of the pool key, which is how an application asks for
        // a connection of its own.
        $separate = new AMQPConnection(
            TestAmqpResolver::getCredentials() + ['connection_name' => 'exclusive-probe'],
        );

        $separate->connect();

        $separateQueue = new AMQPQueue(new AMQPChannel($separate));

        $separateQueue->setName((string) $queue->getName());
        $separateQueue->setFlags(AMQP_DURABLE | AMQP_EXCLUSIVE);

        try {
            $separateQueue->declareQueue();

            self::fail('a connection of its own must be refused the exclusive queue');
        } catch (AMQPQueueException $exception) {
            self::assertSame(405, $exception->getCode());
        } finally {
            $separate->disconnect();
        }

        // The queue goes with the connection that owns it.
        $this->declaredQueues = [];
    }

    public function testDeletingOnlyWhenTheQueueIsEmptyOrUnused(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $this->publishToQueue(channel: $channel, queueName: (string) $queue->getName(), body: 'kept');

        $this->waitForMessageCount(queue: $queue, expected: 1);

        try {
            $queue->delete(flags: AMQP_IFEMPTY);

            self::fail('a queue holding a message is not empty');
        } catch (AMQPQueueException $exception) {
            self::assertSame(406, $exception->getCode());
        }

        // The failure closed the channel, so the queue is deleted from a fresh one, this
        // time without the condition.
        $fresh = new AMQPQueue($this->channel());

        $fresh->setName((string) $queue->getName());

        self::assertSame(1, $fresh->delete(), 'the message it held is reported with it');

        $this->declaredQueues = [];
    }

    public function testDeletingWithoutWaitingForTheBrokersReply(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $queueName = (string) $queue->getName();

        // AMQP_NOWAIT asks the broker not to answer, so the count comes back as 0 whatever
        // the queue held — the deletion itself still happens.
        self::assertSame(0, $queue->delete(flags: AMQP_NOWAIT));

        $this->declaredQueues = [];

        $gone = new AMQPQueue($this->channel());

        $gone->setName($queueName);
        $gone->setFlags(AMQP_PASSIVE);

        $this->expectException(AMQPQueueException::class);

        $gone->declareQueue();
    }
}
