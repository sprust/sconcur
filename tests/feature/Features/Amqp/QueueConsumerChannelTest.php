<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Exceptions\Amqp\ConcurrentDeliveryUseException;
use SConcur\Features\Amqp\Channel;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Features\Amqp\Delivery;
use SConcur\Features\Amqp\Support\DeliveryCodec;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Impl\TestAmqpResolver;
use SConcur\WaitGroup;
use WeakReference;

/**
 * What a handler of a supervised consumer publishes through.
 *
 * A prefetch above one puts several messages of one consumer in flight at once, so the
 * channel they arrived on belongs to no single handler — and publisher confirms are
 * channel-wide. These tests hold the line that makes that harmless: a handler is lent a
 * channel nobody else holds.
 */
class QueueConsumerChannelTest extends AmqpTestCase
{
    /** How long a handler waits so that its neighbours reach their publish as well. */
    protected const int OVERLAP_MICROSECONDS = 150_000;

    public function testHandlersRunningAtOnceGetChannelsOfTheirOwn(): void
    {
        $channel = $this->channel();

        $source = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $expected = 4;

        for ($index = 0; $index < $expected; ++$index) {
            $this->publishToQueue($channel, $source->name(), "body-$index");
        }

        $channelIds         = [];
        $names              = [];
        $arrived            = [];
        $deliveryConnection = $this->connection();

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson($source->name()),
            prefetchCount: $expected,
            maxMessages: $expected,
        );

        $queueConsumer->consume(
            connection: $deliveryConnection,
            handler: static function (Delivery $delivery) use (&$channelIds, &$names, &$arrived, $expected): void {
                $own = $delivery->channel();

                self::assertNotNull($own, 'a handler must be lent a channel');

                $channelIds[] = spl_object_id($own);
                $names[]      = (string) $own->connection()->options->connectionName;

                // A barrier, not a sleep: every handler holds its channel until all of them
                // have one, so the leases overlap however the machine is loaded. A set and
                // not a count — preemption can split a read-modify-write between two of
                // them.
                $arrived[$delivery->body] = true;

                $deadline = microtime(true) + 5.0;

                while (count($arrived) < $expected && microtime(true) < $deadline) {
                    Sleeper::usleep(microseconds: 5_000);
                }
            },
        );

        self::assertCount($expected, $channelIds);
        self::assertSame($channelIds, array_unique($channelIds), 'four handlers at once, four channels');

        // A different socket, not merely a different object: the Go side pools a connection
        // by its options, the name among them, and the deliveries arrive on an unnamed one.
        foreach ($names as $name) {
            self::assertNotSame('', $name, 'the lent channel must be on a connection of the pool');
            self::assertNotSame(
                (string) $deliveryConnection->options->connectionName,
                $name,
                'the lent channel must not share the socket the deliveries arrive on',
            );
        }
    }

    /**
     * The regression this whole arrangement exists for. Publisher confirms are counted per
     * channel, so handlers sharing one read each other's answers: a refusal would surface on
     * whichever handler asked first, and the one whose message the broker actually dropped
     * would be told it was stored.
     */
    public function testARefusedPublishReachesOnlyTheHandlerThatCausedIt(): void
    {
        $channel = $this->channel();

        $source = $this->declareQueue(
            channel: $channel,
            durable: true,
        );
        $target = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $bodies = ['ok-1', 'ok-2', 'bad', 'ok-3'];

        foreach ($bodies as $body) {
            $this->publishToQueue($channel, $source->name(), $body);
        }

        // Named after nothing: a mandatory publish that routes nowhere is what the broker
        // sends back, and it is the failure one handler must keep to itself.
        $nowhere = TestAmqpResolver::uniqueName('nowhere');

        $outcomes = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson($source->name()),
            prefetchCount: 4,
            maxMessages: 4,
        );

        $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery) use ($target, $nowhere, &$outcomes): void {
                // Every handler reaches its publish before any of them waits, so the
                // confirmations really are in flight together.
                Sleeper::usleep(microseconds: self::OVERLAP_MICROSECONDS);

                $own = $delivery->channel();

                self::assertNotNull($own);

                try {
                    $own->publishConfirmed(
                        message: $delivery->body,
                        exchange: '',
                        routingKey: $delivery->body === 'bad' ? $nowhere : $target->name(),
                        timeoutSeconds: 3.0,
                    );

                    $outcomes[$delivery->body] = 'stored';
                } catch (AmqpException) {
                    $outcomes[$delivery->body] = 'refused';
                }
            },
        );

        ksort($outcomes);

        self::assertSame(
            [
                'bad'  => 'refused',
                'ok-1' => 'stored',
                'ok-2' => 'stored',
                'ok-3' => 'stored',
            ],
            $outcomes,
        );

        self::assertSame(3, $this->waitForMessageCount($target, expected: 3));
    }

    /**
     * The loan ends with the handler, and a delivery kept past it answers with nothing —
     * never with the channel the message arrived on, which is the one the runtime keeps.
     *
     * Asked while the consumer is still running, on purpose: once the run ends it lets go of
     * the arriving channels anyway, and a delivery would answer null for that reason instead
     * of this one.
     */
    public function testADeliveryHandsOutNothingAfterItsHandler(): void
    {
        $channel = $this->channel();

        $source = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue($channel, $source->name(), 'first');
        $this->publishToQueue($channel, $source->name(), 'second');

        $first   = null;
        $answers = [];

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson($source->name()),
            prefetchCount: 1,
            maxMessages: 2,
        );

        $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery) use (&$first, &$answers): void {
                $own = $delivery->channel();

                self::assertNotNull($own, 'the handler is lent one while it runs');

                if ($first === null) {
                    $first = $delivery;

                    return;
                }

                // The first handler has ended; its delivery must have nothing left to give.
                $answers['kept'] = $first->channel();
                $answers['own']  = $own;
            },
        );

        self::assertArrayHasKey('kept', $answers);
        self::assertNull($answers['kept'], 'a delivery kept past its handler is lent nothing');
    }

    /**
     * Taking the loan waits for the broker, so a delivery used from two coroutines at once
     * would be lent two channels and could give back only one. Refused instead of leaked.
     */
    public function testOneDeliveryCannotLendToTwoCoroutinesAtOnce(): void
    {
        $channel = $this->channel();

        $delivery = DeliveryCodec::delivery(
            delivery: ['bd' => 'body'],
            channel: WeakReference::create($channel),
            autoAck: true,
            // Stands in for a real lease, which opens a channel on the broker and suspends
            // the coroutine while it does.
            lend: static function () use ($channel): Channel {
                Sleeper::usleep(microseconds: 100_000);

                return $channel;
            },
        );

        $outcome = null;

        $waitGroup = WaitGroup::create();

        $waitGroup->add(static function () use ($delivery): void {
            $delivery->channel();
        });

        $waitGroup->add(static function () use ($delivery, &$outcome): void {
            try {
                $delivery->channel();

                $outcome = 'lent';
            } catch (ConcurrentDeliveryUseException) {
                $outcome = 'refused';
            }
        });

        $waitGroup->waitAll();

        self::assertSame('refused', $outcome);
    }

    /**
     * What one handler left behind must not reach the next. A mandatory message routed
     * nowhere leaves a return sitting on the channel, and whoever waits for confirms on that
     * channel next would collect it as if it were about their own message.
     */
    public function testWhatOneHandlerLeftUnreadDoesNotReachTheNext(): void
    {
        $channel = $this->channel();

        $source = $this->declareQueue(
            channel: $channel,
            durable: true,
        );
        $target = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue($channel, $source->name(), 'poison');
        $this->publishToQueue($channel, $source->name(), 'clean');

        $nowhere = TestAmqpResolver::uniqueName('nowhere');

        $outcomes = [];

        // One at a time on purpose: the second handler gets the channel the first gave back,
        // unless the pool notices what was left on it.
        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson($source->name()),
            prefetchCount: 1,
            maxMessages: 2,
        );

        $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery) use ($target, $nowhere, &$outcomes): void {
                $own = $delivery->channel();

                self::assertNotNull($own);

                if ($delivery->body === 'poison') {
                    // Published and never waited for — what a handler cut by its deadline,
                    // or unwound by a drain, leaves behind.
                    $own->publish(
                        message: 'orphan',
                        exchange: '',
                        routingKey: $nowhere,
                        mandatory: true,
                    );

                    $outcomes['poison'] = 'left';

                    return;
                }

                try {
                    $own->publishConfirmed(
                        message: $delivery->body,
                        exchange: '',
                        routingKey: $target->name(),
                        timeoutSeconds: 3.0,
                    );

                    $outcomes['clean'] = 'stored';
                } catch (AmqpException) {
                    $outcomes['clean'] = 'refused';
                }
            },
        );

        ksort($outcomes);

        self::assertSame(
            [
                'clean'  => 'stored',
                'poison' => 'left',
            ],
            $outcomes,
        );

        self::assertSame(1, $this->waitForMessageCount($target, expected: 1));
    }

    /**
     * A handle over a channel the Go side owns closes nothing.
     *
     * The consumer's channels carry every handler running on them, so a `ChannelClose` sent
     * from one of these handles would take the channel away from the neighbours still
     * answering the broker on it — and giving the handle up locally would leave the runtime
     * unable to settle a delivery still in a handler. Both exits keep the rule; this covers
     * the public one.
     */
    public function testClosingABorrowedHandleTakesNothingAway(): void
    {
        $channel = $this->channel();

        $source = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue($channel, $source->name(), 'first');
        $this->publishToQueue($channel, $source->name(), 'second');

        $queueConsumer = new InspectableQueueConsumer(
            queues: $this->queuesJson($source->name()),
            prefetchCount: 1,
            maxMessages: 2,
        );

        $handled = [];
        $closed  = 0;

        $count = $queueConsumer->consume(
            connection: $this->connection(),
            handler: static function (Delivery $delivery) use ($queueConsumer, &$handled, &$closed): void {
                $handled[] = $delivery->body;

                if ($closed > 0) {
                    return;
                }

                foreach ($queueConsumer->borrowedChannels() as $borrowed) {
                    self::assertTrue($borrowed->isOpen());

                    $borrowed->close();

                    // Still open, because a view over someone else's channel closes nothing.
                    self::assertTrue($borrowed->isOpen(), 'closing a borrowed handle must change nothing');

                    ++$closed;
                }
            },
        );

        self::assertGreaterThan(0, $closed, 'the test must reach a borrowed handle');

        // The proof: the first delivery was acknowledged on that very channel and the second
        // arrived on it too. A ChannelClose would have ended both.
        self::assertSame(2, $count);
        self::assertSame(['first', 'second'], $handled);
        $this->assertQueueStaysEmpty($source);
    }

    /**
     * The generator is untouched: there the channel belongs to the coroutine reading it, and
     * that is the one handed out.
     */
    public function testTheGeneratorStillHandsOutTheArrivingChannel(): void
    {
        $channel = $this->channel(prefetchCount: 4);

        $queue = $this->declareQueue(
            channel: $channel,
            durable: true,
        );

        $this->publishToQueue($channel, $queue->name(), 'first');
        $this->publishToQueue($channel, $queue->name(), 'second');

        $seen = [];

        foreach ($queue->consume() as $delivery) {
            $seen[] = $delivery->body;

            self::assertSame($channel, $delivery->channel());

            $delivery->ack();

            if (count($seen) === 2) {
                break;
            }
        }

        self::assertSame(['first', 'second'], $seen);
        $this->assertQueueStaysEmpty($queue);
    }

    protected function queuesJson(string $name): string
    {
        return (string) json_encode([['name' => $name, 'coroutineCount' => 1]]);
    }
}

/**
 * A consumer that shows the handles it holds over the channels its deliveries arrive on —
 * the ones a handler never sees, which is what makes them worth a test of their own.
 */
class InspectableQueueConsumer extends QueueConsumer
{
    /**
     * @return array<string, Channel>
     */
    public function borrowedChannels(): array
    {
        return $this->channels;
    }
}
