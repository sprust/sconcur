<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Redis;

use SConcur\Features\Redis\Connection;
use SConcur\Features\Redis\Dto\Message;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestRedisResolver;
use SConcur\WaitGroup;

class RedisSubscribeTest extends BaseTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        TestRedisResolver::flush();

        $this->connection = TestRedisResolver::getConnection();
    }

    public function testMessagesArriveOnASubscribedChannel(): void
    {
        $received = [];

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function () use (&$received): void {
                $subscription = $this->connection->subscribe(channels: ['news']);

                // Publishing from another coroutine, so the two run at the same time: a
                // subscriber that had to publish to itself would prove nothing.
                Scheduler::get()->spawn(
                    callback: function (): void {
                        $this->connection->command('PUBLISH', ['news', 'first']);
                        $this->connection->command('PUBLISH', ['news', 'second']);
                    },
                );

                foreach ($subscription as $message) {
                    $received[] = $message->payload;

                    if (count($received) === 2) {
                        break;
                    }
                }

                $subscription->close();
            },
        );

        $waitGroup->waitAll();

        self::assertSame(['first', 'second'], $received);
    }

    public function testAPatternSubscriptionReportsThePatternAndTheChannel(): void
    {
        $message = null;

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function () use (&$message): void {
                $subscription = $this->connection->subscribe(patterns: ['user:*']);

                Scheduler::get()->spawn(
                    callback: function (): void {
                        $this->connection->command('PUBLISH', ['user:7', 'hello']);
                    },
                );

                $message = $subscription->read();

                $subscription->close();
            },
        );

        $waitGroup->waitAll();

        self::assertInstanceOf(Message::class, $message);
        self::assertSame('user:7', $message->channel);
        self::assertSame('user:*', $message->pattern);
        self::assertSame('hello', $message->payload);
        self::assertTrue($message->fromPattern());
    }

    public function testChannelsCanBeAddedToALiveSubscription(): void
    {
        $received = [];

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function () use (&$received): void {
                $subscription = $this->connection->subscribe(channels: ['first']);

                $subscription->add(channels: ['second']);

                Scheduler::get()->spawn(
                    callback: function (): void {
                        $this->connection->command('PUBLISH', ['second', 'from-second']);
                    },
                );

                $message = $subscription->read();

                $received[] = $message?->channel;

                $subscription->close();
            },
        );

        $waitGroup->waitAll();

        self::assertSame(['second'], $received);
    }

    public function testAnAbandonedSubscriptionIsClosedWithItsFlow(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function (): void {
                $subscription = $this->connection->subscribe(channels: ['abandoned']);

                self::assertNotSame('', $subscription->subscriptionId);

                // Walk away without closing. The flow ending must release the
                // subscription and its connection; the dangling-task check in tearDown is
                // what proves it did.
            },
        );

        $waitGroup->waitAll();

        // The connection still serves commands, so the abandoned subscription took only
        // its own socket with it.
        self::assertTrue($this->connection->ping());
    }

    public function testASubscriptionCanBeClosedTwice(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function (): void {
                $subscription = $this->connection->subscribe(channels: ['twice']);

                $subscription->close();
                $subscription->close();

                self::assertTrue($subscription->isClosed());
            },
        );

        $waitGroup->waitAll();
    }

    public function testClosingASubscriptionReleasesEverythingItHeld(): void
    {
        // The path the documentation recommends, and the one that leaked: close()
        // told the core to let go and then skipped releasing the flow this side
        // owned, while the core's own cleanup waited on a flow that close() never
        // ended. Only the abandoned path was correct.
        $this->connection->ping();

        $baseline = TestRedisResolver::countServerConnections();

        for ($round = 0; $round < 8; ++$round) {
            $subscription = $this->connection->subscribe(channels: ["closed:$round"]);

            $subscription->close();
        }

        self::assertLessThanOrEqual(
            $baseline + 2,
            TestRedisResolver::countServerConnections(),
            'closing subscriptions did not release their connections',
        );
    }

    public function testReadFollowedByIterationDoesNotDeliverTheSameMessageTwice(): void
    {
        $received = [];

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function () use (&$received): void {
                $subscription = $this->connection->subscribe(channels: ['once']);

                $published = false;

                Scheduler::get()->spawn(
                    callback: function () use (&$published): void {
                        $this->connection->command('PUBLISH', ['once', 'first']);
                        $this->connection->command('PUBLISH', ['once', 'second']);

                        $published = true;
                    },
                );

                $received[] = $subscription->read()?->payload;

                foreach ($subscription as $message) {
                    $received[] = $message->payload;

                    break;
                }

                $subscription->close();

                // The publisher is a spawned coroutine, so the group does not wait
                // for it; a message read is not the same moment as the publish that
                // sent it having finished.
                while (!$published) {
                    Sleeper::usleep(microseconds: 1000);
                }
            },
        );

        $waitGroup->waitAll();

        // A subscriber doing the work twice is the whole cost of a duplicate.
        self::assertSame(['first', 'second'], $received);
    }

    public function testIterationResumedAfterABreakDoesNotDeliverTheSameMessageTwice(): void
    {
        $received = [];

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function () use (&$received): void {
                $subscription = $this->connection->subscribe(channels: ['resumed']);

                $published = false;

                Scheduler::get()->spawn(
                    callback: function () use (&$published): void {
                        $this->connection->command('PUBLISH', ['resumed', 'first']);
                        $this->connection->command('PUBLISH', ['resumed', 'second']);

                        $published = true;
                    },
                );

                // The other direction of the read()-then-loop rule: a loop broken out of
                // holds a message its body has already handled, and a loop entered after
                // it has to go on to the next one rather than serve that message again.
                foreach ($subscription as $message) {
                    $received[] = $message->payload;

                    break;
                }

                foreach ($subscription as $message) {
                    $received[] = $message->payload;

                    break;
                }

                $subscription->close();

                while (!$published) {
                    Sleeper::usleep(microseconds: 1000);
                }
            },
        );

        $waitGroup->waitAll();

        self::assertSame(['first', 'second'], $received);
    }

    public function testAPatternSubscriptionIsToldApartFromAPlainOne(): void
    {
        // The core says which it is; inferring it from the pattern being empty is
        // wrong for a subscription to the empty pattern, which is legal.
        $messages = [];

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function () use (&$messages): void {
                $subscription = $this->connection->subscribe(channels: ['plain']);

                Scheduler::get()->spawn(
                    callback: function (): void {
                        $this->connection->command('PUBLISH', ['plain', 'body']);
                    },
                );

                $messages[] = $subscription->read();

                $subscription->close();
            },
        );

        $waitGroup->waitAll();

        self::assertInstanceOf(Message::class, $messages[0]);
        self::assertFalse($messages[0]->fromPattern());
        self::assertSame('', $messages[0]->pattern);
    }
}
