<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use ReflectionProperty;
use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Exceptions\Amqp\ChannelException;
use SConcur\Exceptions\Amqp\ConnectionException;
use SConcur\Exceptions\Amqp\QueueException;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\ConnectionOptions;
use SConcur\Features\Amqp\Support\AmqpResource;
use SConcur\Features\Amqp\TlsOptions;
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

        // And the channel is gone on the extension too, instead of waiting for the sweeper.
        self::assertSame(0, $connection->usedChannels());
    }

    /**
     * A connection-level failure takes the connection, not just the channel that ran into
     * it.
     *
     * The failure is caused by asking the broker to close the connection, which is what an
     * operator pressing the button or a node going down does. It used to be caused with a
     * prefetch size — RabbitMQ answers a non-zero one with 540 NOT_IMPLEMENTED, a
     * connection-level reply code — but the extension's AMQP driver cannot put that field
     * on the wire at all (see docs/amqp.md), so the device changed and the subject did not:
     * what such a failure does to the handles on this side.
     */
    public function testAConnectionLevelFailureIsReportedAsOne(): void
    {
        // Its own connection, named so the pool gives it one and so the broker can be asked
        // to close exactly this one: the pooled connection is shared by every other test.
        $name = TestAmqpResolver::uniqueName('connection-failure-probe');

        $connection = $this->namedConnection($name);

        $connection->connect();

        $channel = $connection->channel();

        $this->closeFromTheBroker($name);

        try {
            $channel->queue(TestAmqpResolver::uniqueName('anything'))->declare();

            self::fail('a command cannot run on a connection the broker closed');
        } catch (ConnectionException) {
            // The class is the assertion: a dead connection is not a channel failure.
        }

        self::assertFalse($connection->isOpen(), 'the connection must report itself closed');
        self::assertFalse($channel->isOpen(), 'its channels go with it');

        try {
            $connection->channel();

            self::fail('a channel cannot be opened on a connection that died');
        } catch (ConnectionException $exception) {
            self::assertStringContainsString('No connection available.', $exception->getMessage());
        }

        // The handle is still handed back, so the pooled connection behind it is released.
        $connection->close();
    }

    /**
     * The same connection-level failure, met while the channel is being opened — the one
     * moment there is no channel object for the resolver to read the connection off. It used
     * to leave the connection reporting itself open, and the reopen guard every consumer is
     * built on (`if (!$connection->isOpen())`) then never fired again.
     */
    public function testAConnectionLostWhileAChannelWasOpeningIsReportedClosed(): void
    {
        $name = TestAmqpResolver::uniqueName('channel-open-failure-probe');

        $connection = $this->namedConnection($name);

        $connection->connect();

        // A channel is opened first: the connection has to be one the broker has listed
        // before it can be asked to close it, and this is also what makes the next open the
        // first thing to meet the dead connection.
        $connection->channel();

        $this->closeFromTheBroker($name);

        try {
            $connection->channel();

            self::fail('a channel cannot be opened on a connection the broker closed');
        } catch (ConnectionException) {
        }

        self::assertFalse(
            $connection->isOpen(),
            'a connection that died under a channel being opened must report itself closed',
        );

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
            $channel->publish(
                message: 'nowhere',
                exchange: TestAmqpResolver::uniqueName('missing'),
            );
        } catch (Throwable) {
            // The failure itself is not what this test is about.
        }

        // Waiting must end instead of counting forever on a confirmation that cannot come.
        $this->expectException(ChannelException::class);

        $channel->waitForConfirms();
    }

    /**
     * basic.publish carries no reply, so a publish to an exchange that is not there is
     * answered by the broker closing the channel — and the 404 that did it was invisible:
     * whatever ran next on that channel could only report that the channel was gone.
     *
     * The reason is recorded when the close arrives, so the next command names it. It stays
     * a ChannelException, because the channel being gone is what happened to this call, and
     * the code is the one that actually closed it.
     *
     * Several attempts, and the 404 is required from one of them rather than from every
     * one, because a single attempt can lose the race inside lapin: the broker's close
     * arrives in two stages — the first carries the 404, the second carries nothing — and
     * both fail the pending confirms. A confirm registered between the stages is failed by
     * the anonymous second one, and there is nothing left to ask. The window is
     * microseconds wide and a per-channel close listener is the only way to shut it, which
     * is not worth a task per channel on a pool that grows to 255 of them. So the
     * guarantee this test holds the code to is that the reason arrives, not that it
     * arrives every single time.
     */
    public function testAChannelReportsWhatClosedIt(): void
    {
        $attempts = 5;
        $refusals = [];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            // A fresh channel per attempt: the previous one is closed by the broker.
            $channel = $this->channel();

            $channel->enableConfirms();

            $missing = TestAmqpResolver::uniqueName('missing');

            try {
                $channel->publish(
                    message: 'nowhere',
                    exchange: $missing,
                );
            } catch (Throwable) {
                // basic.publish expects no reply, so this may or may not fail on its own.
            }

            try {
                // The wait is what reaches the extension: a command refused by the local guard
                // knows only that the channel is closed, while this one asks and is told why.
                $channel->waitForConfirms(timeoutSeconds: 2.0);

                self::fail('a wait on a channel the broker closed must be refused');
            } catch (AmqpException $exception) {
                // Whatever else it says, the call must fail as a closed channel.
                self::assertInstanceOf(ChannelException::class, $exception);

                $refusals[] = $exception->getCode() . ': ' . $exception->getMessage();

                if ($exception->getCode() === 404 && str_contains($exception->getMessage(), $missing)) {
                    return;
                }
            }
        }

        self::fail(
            "none of $attempts publishes to a missing exchange named the 404 that closed the channel:\n"
            . implode("\n", $refusals),
        );
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
        $queue      = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

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
        $queue      = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

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
     * The same, without the close() in between.
     *
     * A connection that died is closed on this side and still holds its handle, so
     * reconnecting is documented as close() then connect(). A caller that skips the close
     * must not be quietly worse off: overwriting the handle would strand the pooled
     * connection behind it — owned by nobody, for the life of the process — and leave the
     * channels of the dead connection reporting themselves open, which is exactly the
     * failure the close() path is tested against above.
     */
    public function testConnectAloneReleasesTheHandleItReplaces(): void
    {
        $connection = $this->connection();

        $channel = $connection->channel();

        $before = $connection->usedChannels();

        self::assertTrue($channel->isOpen());

        $connection->connect();

        self::assertFalse($channel->isOpen(), 'a channel of the replaced handle is not open');
        self::assertTrue($connection->isOpen());

        // The channels of the old handle went with it rather than staying open on a
        // connection nothing holds any more.
        self::assertSame($before - 1, $connection->usedChannels());
    }

    /**
     * TLS is what the caller asked for, not what the certificate paths imply. A dial that
     * fell back to plaintext because no file was named would put the login and the password
     * on the wire in the clear — and say nothing about it.
     *
     * The compose broker has no TLS listener, so the proof is that the handshake is refused
     * where a plaintext connection would have succeeded.
     */
    public function testAnAmqpsUriDoesNotFallBackToPlaintext(): void
    {
        $connection = new Connection(sprintf(
            'amqps://%s:%s@%s:%d/%s',
            rawurlencode((string) $_ENV['RABBITMQ_USER']),
            rawurlencode((string) $_ENV['RABBITMQ_PASSWORD']),
            $_ENV['RABBITMQ_HOST'],
            (int) $_ENV['RABBITMQ_PORT'],
            rawurlencode((string) $_ENV['RABBITMQ_VHOST']),
        ));

        try {
            $connection->connect();

            self::fail('an amqps:// URI must not connect to a plaintext listener');
        } catch (ConnectionException $exception) {
            self::assertStringContainsString('tls', strtolower($exception->getMessage()));
        }
    }

    /**
     * The same rule through the options object: TLS with the system trust store names no
     * file at all, and `verify: false` against a development broker names none either.
     */
    public function testTlsOptionsWithoutAnyFileStillDialTls(): void
    {
        $connection = new Connection(new ConnectionOptions(
            host: (string) $_ENV['RABBITMQ_HOST'],
            port: (int) $_ENV['RABBITMQ_PORT'],
            login: (string) $_ENV['RABBITMQ_USER'],
            password: (string) $_ENV['RABBITMQ_PASSWORD'],
            vhost: (string) $_ENV['RABBITMQ_VHOST'],
            tls: new TlsOptions(verify: false),
        ));

        $this->expectException(ConnectionException::class);

        $connection->connect();
    }

    /**
     * Opening is lazy and connect() suspends, so coroutines that all find the connection
     * closed must not each dial one. Every handle but the last would be unreachable, and
     * the pooled connection behind it held for the life of the process.
     */
    public function testConcurrentLazyOpensShareOneHandle(): void
    {
        $probe = new Connection(TestAmqpResolver::getOptions());

        $probe->connect();

        $handle = new ReflectionProperty(AmqpResource::class, 'internalId');

        // The handles are numbered in the extension's registry, so the next one tells how many
        // connects happened in between.
        $before = (int) filter_var($handle->getValue($probe), FILTER_SANITIZE_NUMBER_INT);

        $probe->close();

        $connection = new Connection(TestAmqpResolver::getOptions());

        $waitGroup = WaitGroup::create();

        for ($index = 0; $index < 4; ++$index) {
            $waitGroup->add(static function () use ($connection): void {
                $connection->channel()->close();
            });
        }

        $waitGroup->waitAll();

        $after = (int) filter_var($handle->getValue($connection), FILTER_SANITIZE_NUMBER_INT);

        self::assertSame(
            $before + 1,
            $after,
            'four coroutines opening one connection lazily must produce one handle, not four',
        );

        self::assertSame(0, $connection->usedChannels());

        $connection->close();
    }

    /**
     * The connection files its channels weakly so it can mark them closed, but the keys are
     * strings: a channel that never left the registry is a slow leak on a connection that
     * opens one per request.
     */
    public function testAClosedChannelLeavesTheConnectionRegistry(): void
    {
        $connection = $this->connection();

        $registry = new ReflectionProperty(AmqpResource::class, 'internalChannels');

        $before = count($registry->getValue($connection));

        for ($index = 0; $index < 20; ++$index) {
            $connection->channel()->close();
        }

        self::assertSame($before, count($registry->getValue($connection)));
    }

    /**
     * A channel the broker closes costs the connection that channel's number for
     * good: the driver hands the number to the next `channel.open`, and that open
     * is answered with the error the number's previous owner died of.
     *
     * Left alone, the 256th such close makes the connection permanently useless —
     * every later `channel()` fails with a 404 about a queue some earlier cycle
     * asked for, while the connection reports itself open and the broker is fine.
     * A passive declare of a missing queue in a loop is enough to reach it, which
     * is how it was found: the AMQP soak's `errors` scenario counted 2.6M of them.
     *
     * The core now counts the numbers it has lost and retires the connection
     * before they run out, so the failure a caller sees is one it can act on —
     * "reconnect" — rather than somebody else's error forever.
     */
    public function testAConnectionSurvivesMoreBrokerClosesThanItHasChannelNumbers(): void
    {
        $connection = TestAmqpResolver::getConnection();

        $completed  = 0;
        $reconnects = 0;

        // Comfortably past the channel-number ceiling: without the fix everything
        // from the 256th on fails, and reconnecting does not help either, because
        // the pool hands back the same exhausted connection.
        for ($cycle = 0; $cycle < 400; $cycle++) {
            try {
                $channel = $connection->channel();
            } catch (ConnectionException) {
                // The connection retired itself; the next one is fresh.
                ++$reconnects;

                $connection = TestAmqpResolver::getConnection();

                continue;
            }

            try {
                $channel->queue(TestAmqpResolver::uniqueName('missing'))->declarePassive();

                self::fail('a passive declare of a missing queue must be refused');
            } catch (QueueException) {
                // The point of the cycle: the broker closes the channel over this.
            }

            $channel->close();

            ++$completed;
        }

        // A handful of cycles are spent on the swap itself; everything else works.
        self::assertGreaterThan(
            380,
            $completed,
            "only $completed of 400 cycles completed ($reconnects reconnects)",
        );

        // And the connection in hand is usable, not a husk that reports itself open.
        $channel = $connection->channel();

        self::assertTrue($channel->isOpen());

        $channel->close();
    }

    /**
     * A connection of this test's own, named so the pool does not share it.
     */
    protected function namedConnection(string $name): Connection
    {
        return new Connection(new ConnectionOptions(
            host: (string) $_ENV['RABBITMQ_HOST'],
            port: (int) $_ENV['RABBITMQ_PORT'],
            login: (string) $_ENV['RABBITMQ_USER'],
            password: (string) $_ENV['RABBITMQ_PASSWORD'],
            vhost: (string) $_ENV['RABBITMQ_VHOST'],
            connectionName: $name,
        ));
    }

    /**
     * Asks the broker to close the named connection, waiting for it to appear first: the
     * management API lists a connection only once its statistics have been collected, which
     * is a few seconds behind the socket.
     */
    protected function closeFromTheBroker(string $name): void
    {
        $closed   = 0;
        $deadline = microtime(true) + 15.0;

        // Polled finely rather than twice a second: the broker lists a fresh
        // connection about a second after the socket, and a 500 ms step rounded
        // that up to two or three. The management call is cheap next to the wait
        // it replaces.
        while ($closed === 0 && microtime(true) < $deadline) {
            $closed = TestAmqpResolver::closeConnectionsNamed($name);

            if ($closed === 0) {
                usleep(100_000);
            }
        }

        self::assertGreaterThan(0, $closed, 'the test must actually close the connection');

        // The close travels to the client as a frame; give the extension the moment it
        // takes to read it, so the assertions below are about the failure and not about a
        // race with it.
        Sleeper::usleep(microseconds: 200_000);
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
