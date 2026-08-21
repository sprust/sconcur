<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Features\Amqp\AMQPBasicProperties;
use SConcur\Features\Amqp\AMQPQueueException;
use const SConcur\Features\Amqp\AMQP_DURABLE;
use const SConcur\Features\Amqp\AMQP_MANDATORY;

/**
 * Publisher confirms, returned messages and transactions — everything an application uses
 * to find out what happened to a published message.
 */
class AmqpConfirmTest extends AmqpTestCase
{
    public function testEveryPublishedMessageIsConfirmed(): void
    {
        $channel  = $this->channel();
        $exchange = $this->declareExchange($channel);
        $queue    = $this->declareQueue($channel, AMQP_DURABLE);

        $queue->bind((string) $exchange->getName(), 'key');

        $channel->confirmSelect();

        $confirmed = [];

        $channel->setConfirmCallback(
            function (int $deliveryTag, bool $multiple) use (&$confirmed): bool {
                $confirmed[] = $deliveryTag;

                return true;
            },
            function (int $deliveryTag, bool $multiple, bool $requeue): bool {
                self::fail("the broker rejected the message with tag $deliveryTag");
            },
        );

        $exchange->publish(message: 'first', routingKey: 'key');
        $exchange->publish(message: 'second', routingKey: 'key');

        $channel->waitForConfirm(2.0);

        self::assertSame([1, 2], $confirmed);
    }

    public function testWaitingForConfirmsWithNothingPublishedReturnsAtOnce(): void
    {
        $channel = $this->channel();

        $channel->confirmSelect();

        $channel->waitForConfirm(2.0);

        self::assertTrue($channel->isConnected());
    }

    public function testAMessageThatCannotBeRoutedComesBack(): void
    {
        $channel  = $this->channel();
        $exchange = $this->declareExchange($channel);

        $returned = [];

        $channel->setReturnCallback(
            function (
                int $replyCode,
                string $replyText,
                string $exchangeName,
                string $routingKey,
                AMQPBasicProperties $properties,
                string $body,
            ) use (&$returned): bool {
                $returned[] = [
                    'replyCode'   => $replyCode,
                    'replyText'   => $replyText,
                    'exchange'    => $exchangeName,
                    'routingKey'  => $routingKey,
                    'contentType' => $properties->getContentType(),
                    'body'        => $body,
                ];

                return false;
            },
        );

        // Nothing is bound to this exchange, so a mandatory message has nowhere to go.
        $exchange->publish(message: 'nowhere', routingKey: 'unbound', flags: AMQP_MANDATORY);

        $channel->waitForBasicReturn(2.0);

        self::assertCount(1, $returned);
        self::assertSame(312, $returned[0]['replyCode']);
        self::assertSame('NO_ROUTE', $returned[0]['replyText']);
        self::assertSame($exchange->getName(), $returned[0]['exchange']);
        self::assertSame('unbound', $returned[0]['routingKey']);
        self::assertSame('text/plain', $returned[0]['contentType']);
        self::assertSame('nowhere', $returned[0]['body']);
    }

    public function testWaitingForAReturnThatNeverComesTimesOut(): void
    {
        $channel = $this->channel();

        $this->expectException(AMQPQueueException::class);

        $channel->waitForBasicReturn(0.2);
    }

    public function testACommittedTransactionDeliversItsMessages(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue($channel, AMQP_DURABLE);

        $channel->startTransaction();

        $this->publishToQueue($channel, (string) $queue->getName(), 'committed');

        $channel->commitTransaction();

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope);
        self::assertSame('committed', $envelope->getBody());

        $queue->ack($envelope->getDeliveryTag());
    }

    public function testARolledBackTransactionDeliversNothing(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue($channel, AMQP_DURABLE);

        $channel->startTransaction();

        $this->publishToQueue($channel, (string) $queue->getName(), 'rolled back');

        $channel->rollbackTransaction();

        self::assertNull($queue->get());
    }
}
