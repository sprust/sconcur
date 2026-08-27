<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Features\Amqp\Delivery;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Impl\TestAmqpResolver;

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

        for ($index = 0; $index < 4; ++$index) {
            $this->publishToQueue($channel, $source->name(), "body-$index");
        }

        $channelIds         = [];
        $ownConnections     = [];
        $deliveryConnection = $this->connection();

        $queueConsumer = new QueueConsumer(
            queues: $this->queuesJson($source->name()),
            prefetchCount: 4,
            maxMessages: 4,
        );

        $queueConsumer->consume(
            connection: $deliveryConnection,
            handler: static function (Delivery $delivery) use (&$channelIds, &$ownConnections): void {
                $own = $delivery->channel();

                self::assertNotNull($own, 'a handler must be lent a channel');

                $channelIds[]     = spl_object_id($own);
                $ownConnections[] = $own->connection();

                // Held until every one of the four has taken one, so the four leases really
                // do overlap instead of reusing the channel of a handler already finished.
                Sleeper::usleep(microseconds: self::OVERLAP_MICROSECONDS);
            },
        );

        self::assertCount(4, $channelIds);
        self::assertSame($channelIds, array_unique($channelIds), 'four handlers at once, four channels');

        foreach ($ownConnections as $connection) {
            self::assertNotSame(
                $deliveryConnection,
                $connection,
                'the lent channel must not be one the deliveries arrive on',
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
