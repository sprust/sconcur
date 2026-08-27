<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Features\Amqp\Consumer\PublishChannelPool;
use SConcur\Features\Amqp\ConnectionOptions;
use SConcur\Tests\Impl\TestAmqpResolver;

/**
 * The channels a supervised consumer lends its handlers: one holder at a time, opened only
 * when a handler asks, and on connections that never compete with the consumers for the
 * delivery connection's channel numbers.
 */
class PublishChannelPoolTest extends AmqpTestCase
{
    public function testItOpensNothingUntilAHandlerAsks(): void
    {
        $pool = new PublishChannelPool(options: $this->connection()->options);

        try {
            self::assertSame(0, $pool->connectionCount());
            self::assertSame(0, $pool->channelCount());
        } finally {
            $pool->close();
        }
    }

    public function testTwoHoldersNeverGetTheSameChannel(): void
    {
        $pool = new PublishChannelPool(options: $this->connection()->options);

        try {
            $first  = $pool->lease();
            $second = $pool->lease();

            self::assertNotSame($first, $second);
            self::assertTrue($first->isOpen());
            self::assertTrue($second->isOpen());
            self::assertSame(2, $pool->channelCount());
            self::assertSame(1, $pool->connectionCount());
        } finally {
            $pool->close();
        }
    }

    public function testAChannelHandedBackIsLentAgain(): void
    {
        $pool = new PublishChannelPool(options: $this->connection()->options);

        try {
            $first = $pool->lease();

            $pool->release($first);

            self::assertSame($first, $pool->lease(), 'a returned channel is reused, not reopened');
            self::assertSame(1, $pool->channelCount());
        } finally {
            $pool->close();
        }
    }

    /**
     * The reason the pool has connections of its own: whatever a prefetch lets a worker run
     * at once, it cannot cost the consumers their channel numbers.
     */
    public function testItsChannelsDoNotCountAgainstTheDeliveryConnection(): void
    {
        $before = $this->connection()->usedChannels();

        $pool = new PublishChannelPool(options: $this->connection()->options);

        try {
            $pool->lease();
            $pool->lease();

            self::assertSame($before, $this->connection()->usedChannels());
        } finally {
            $pool->close();
        }
    }

    public function testADeadChannelIsNotLentAgain(): void
    {
        $pool = new PublishChannelPool(options: $this->connection()->options);

        try {
            $channel = $pool->lease();

            // A passive declare of a queue that is not there is how a broker closes a
            // channel under its holder — the state the next handler must not inherit.
            try {
                $channel->queue(TestAmqpResolver::uniqueName('missing'))->declarePassive();

                self::fail('a passive declare of a missing queue must fail');
            } catch (AmqpException) {
                // What the test needed: the channel is gone.
            }

            $pool->release($channel);

            $next = $pool->lease();

            self::assertNotSame($channel, $next);
            self::assertTrue($next->isOpen());
            self::assertSame(1, $pool->channelCount());
        } finally {
            $pool->close();
        }
    }

    /**
     * A worker busy once must not hold that burst's channels — and the sockets under them —
     * for the rest of its life. A real pool waits ten minutes before it gives one up; this
     * one waits not at all, so the trimming is visible in a test.
     */
    public function testChannelsNothingNeedsAnyMoreAreGivenUp(): void
    {
        $pool = new PublishChannelPool(
            options: $this->connection()->options,
            maxIdleSeconds: 0.0,
        );

        try {
            $first  = $pool->lease();
            $second = $pool->lease();

            self::assertSame(2, $pool->channelCount());

            // One release gives up one channel, so a burst is let go over the releases that
            // follow it instead of in a stall on any single one.
            $pool->release($first);

            self::assertSame(1, $pool->channelCount());

            $pool->release($second);

            self::assertSame(0, $pool->channelCount());
            self::assertSame(1, $pool->connectionCount(), 'one socket is kept to open the next channel on');
            self::assertTrue($pool->lease()->isOpen(), 'the pool still opens one when asked');
        } finally {
            $pool->close();
        }
    }

    /**
     * The delayed form of the misattribution the pool exists to prevent: a handler that left
     * a confirm or a return unread hands the channel back, and whoever waits on it next
     * collects that answer as if it were about their own message.
     */
    public function testAChannelTheBrokerStillOwesAnAnswerIsNotLentAgain(): void
    {
        $pool = new PublishChannelPool(options: $this->connection()->options);

        try {
            $channel = $pool->lease();

            // Mandatory and routed nowhere, and nobody waits for the return: exactly what a
            // handler leaves behind when its deadline cuts it mid-publish.
            $channel->publish(
                message: 'orphan',
                exchange: '',
                routingKey: TestAmqpResolver::uniqueName('nowhere'),
                mandatory: true,
            );

            $pool->release($channel);

            $next = $pool->lease();

            self::assertNotSame($channel, $next, 'a channel with an unread answer must not be lent again');
            self::assertTrue($next->isOpen());
        } finally {
            $pool->close();
        }
    }

    /** A channel handed back with nothing outstanding is the ordinary case, and is reused. */
    public function testAChannelWithNothingOutstandingIsStillReused(): void
    {
        $pool = new PublishChannelPool(options: $this->connection()->options);

        $target = $this->declareQueue(
            channel: $this->channel(),
            durable: true,
        );

        try {
            $channel = $pool->lease();

            $channel->publishConfirmed(
                message: 'stored',
                exchange: '',
                routingKey: $target->name(),
                timeoutSeconds: 3.0,
            );

            $pool->release($channel);

            self::assertSame($channel, $pool->lease(), 'a settled publish leaves the channel reusable');
        } finally {
            $pool->close();
        }
    }

    /**
     * A burst spreads over several connections, and the sockets go back with the channels —
     * all but the one the next channel will be opened on.
     */
    public function testTheConnectionsOfAPastBurstAreGivenUp(): void
    {
        $options = $this->connection()->options;

        $pool = new PublishChannelPool(
            options: new ConnectionOptions(
                host: $options->host,
                port: $options->port,
                login: $options->login,
                password: $options->password,
                vhost: $options->vhost,
                // Two, so exactly one is usable: the growth a real pool reaches at 255
                // fits in a test.
                channelMax: 2,
            ),
            maxIdleSeconds: 0.0,
        );

        try {
            $leased = [$pool->lease(), $pool->lease(), $pool->lease()];

            self::assertSame(3, $pool->channelCount());
            self::assertSame(3, $pool->connectionCount(), 'one channel each means one connection each');

            foreach ($leased as $channel) {
                $pool->release($channel);
            }

            self::assertSame(0, $pool->channelCount());
            self::assertSame(1, $pool->connectionCount(), 'the burst gives its sockets back');
        } finally {
            $pool->close();
        }
    }

    public function testClosingGivesEverythingBack(): void
    {
        $pool = new PublishChannelPool(options: $this->connection()->options);

        $channel = $pool->lease();

        $pool->close();

        self::assertSame(0, $pool->channelCount());
        self::assertSame(0, $pool->connectionCount());
        self::assertFalse($channel->isOpen(), 'releasing the connection closes its channels');
    }
}
