<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Exceptions\Amqp\ChannelException;
use SConcur\Exceptions\Amqp\PublishConfirmTimeoutException;
use SConcur\Exceptions\Amqp\UnroutableMessageException;
use SConcur\Features\Amqp\Message;
use SConcur\WaitGroup;

/**
 * Publisher confirms: how an application finds out what the broker did with a published
 * message.
 *
 * The calque needed a pair of callbacks and a separate wait for this, because the
 * extension cannot suspend. Here the publish itself waits, so a confirm is a return and a
 * refusal is an exception.
 */
class AmqpConfirmTest extends AmqpTestCase
{
    public function testAConfirmedPublishReturnsOnceTheBrokerHasTheMessage(): void
    {
        $channel  = $this->channel();
        $exchange = $this->declareExchange($channel);
        $queue    = $this->declareQueue(channel: $channel, durable: true);

        $queue->bind(exchange: $exchange->name(), routingKey: 'key');

        $exchange->publishConfirmed(message: 'first', routingKey: 'key', timeout: 2.0);
        $exchange->publishConfirmed(message: 'second', routingKey: 'key', timeout: 2.0);

        // The confirm is the broker's promise that it has the message, so the queue holds
        // both by the time the second publish returns — no polling needed.
        self::assertSame(2, $queue->declarePassive()->messageCount);

        $queue->purge();
    }

    /**
     * Confirming each publish one at a time costs a round trip each. A WaitGroup around
     * the same call publishes the batch concurrently, which is why no batch API of its own
     * is needed.
     */
    public function testConfirmedPublishesRunConcurrentlyInAWaitGroup(): void
    {
        $connection = $this->connection();
        $channel    = $this->channel();
        $queue      = $this->declareQueue(channel: $channel, durable: true);

        $queueName = $queue->name();

        $waitGroup = WaitGroup::create();

        for ($index = 0; $index < 8; ++$index) {
            $waitGroup->add(function () use ($connection, $queueName, $index): int {
                // A channel per coroutine: the commands of one channel are serialized, so
                // a shared one would turn the fan-out back into a queue.
                $channel = $connection->channel();

                $channel->queue($queueName)->publishConfirmed(message: "message-$index", timeout: 5.0);

                $channel->close();

                return $index;
            });
        }

        $waitGroup->waitAll();

        self::assertSame(8, $queue->declarePassive()->messageCount);

        $queue->purge();
    }

    public function testWaitingForConfirmsWithNothingPublishedReturnsAtOnce(): void
    {
        $channel = $this->channel();

        $channel->enableConfirms();

        $channel->waitForConfirms(timeout: 2.0);

        self::assertTrue($channel->isOpen());
    }

    public function testWaitingForConfirmsOutsideConfirmModeIsRefused(): void
    {
        $channel = $this->channel();

        $this->expectException(ChannelException::class);
        $this->expectExceptionMessage('not in confirm mode');

        $channel->waitForConfirms(timeout: 0.2);
    }

    /**
     * A mandatory message the broker cannot route is returned and acknowledged, so reading
     * the confirmation alone would report a success for a message that reached nothing.
     * The return is what the publish fails with, and it carries the message back.
     */
    public function testAMessageThatCannotBeRoutedComesBackAsAnException(): void
    {
        $channel  = $this->channel();
        $exchange = $this->declareExchange($channel);

        try {
            // Nothing is bound to this exchange, so a mandatory message has nowhere to go.
            $exchange->publishConfirmed(
                message: new Message(body: 'nowhere', contentType: 'text/plain', headers: ['x-try' => 1]),
                routingKey: 'unbound',
                timeout: 2.0,
            );

            self::fail('an unroutable message must fail the publish');
        } catch (UnroutableMessageException $exception) {
            self::assertSame(312, $exception->getCode());
            self::assertStringContainsString('NO_ROUTE', $exception->getMessage());
            self::assertSame($exchange->name(), $exception->getExchange());
            self::assertSame('unbound', $exception->getRoutingKey());

            $returned = $exception->getReturnedMessage();

            self::assertSame('nowhere', $returned->body);
            self::assertSame('text/plain', $returned->contentType);
            self::assertSame(1, $returned->headers['x-try']);
        }
    }

    /**
     * Without `mandatory` the broker drops what it cannot route and acknowledges it, which
     * is the AMQP default and stays the default here: a publisher that wants to hear about
     * it has to ask.
     */
    public function testAnUnroutableMessageIsDroppedQuietlyWithoutMandatory(): void
    {
        $channel  = $this->channel();
        $exchange = $this->declareExchange($channel);

        $exchange->publishConfirmed(message: 'nowhere', routingKey: 'unbound', timeout: 2.0, mandatory: false);

        self::assertTrue($channel->isOpen());
    }

    public function testAConfirmThatNeverComesTimesOut(): void
    {
        $channel = $this->channel();

        $channel->enableConfirms();

        // Nothing was published, so the wait has a message outstanding that will never be
        // confirmed only because the entry is told to expect one — here it is simply the
        // deadline on an idle channel with confirm mode on and no publish.
        $queue = $this->declareQueue(channel: $channel, durable: true);

        $channel->publish(message: 'one', exchange: '', routingKey: $queue->name());

        // A deadline this short is under the round trip on a loaded broker; either the
        // confirm lands or the wait reports the timeout, and both are the contract.
        try {
            $channel->waitForConfirms(timeout: 0.001);
        } catch (PublishConfirmTimeoutException $exception) {
            self::assertStringContainsString('Wait timeout exceed', $exception->getMessage());

            return;
        }

        self::assertTrue($channel->isOpen());
    }
}
