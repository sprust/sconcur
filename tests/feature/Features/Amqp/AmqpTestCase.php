<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Features\Amqp\Channel;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\Delivery;
use SConcur\Features\Amqp\Exchange;
use SConcur\Features\Amqp\ExchangeTypeEnum;
use SConcur\Features\Amqp\Message;
use SConcur\Features\Amqp\Queue;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestAmqpResolver;
use Throwable;

/**
 * Shared setup for the AMQP feature tests: one connection to the test broker, plus the
 * throwaway topology a test works on. Everything created here is deleted in tearDown, so
 * a failing test leaves no queues behind on the broker.
 */
abstract class AmqpTestCase extends BaseTestCase
{
    /**
     * How long a consumer in a test waits for a delivery before its stream ends. The
     * feature's own default is "forever", which is right for a supervised worker and wrong
     * here: a consumer nothing feeds would hang the run instead of failing it.
     */
    protected const float READ_TIMEOUT_SECONDS = 10.0;

    protected ?Connection $connection = null;

    /** @var list<Channel> */
    protected array $openChannels = [];

    /** @var list<string> */
    protected array $declaredQueues = [];

    /** @var list<string> */
    protected array $declaredExchanges = [];

    protected function tearDown(): void
    {
        $this->cleanUpTopology();

        foreach ($this->openChannels as $channel) {
            $channel->close();
        }

        $this->openChannels = [];

        $this->connection?->close();
        $this->connection = null;

        parent::tearDown();
    }

    protected function connection(): Connection
    {
        return $this->connection ??= TestAmqpResolver::getConnection(
            readTimeout: static::READ_TIMEOUT_SECONDS,
        );
    }

    protected function channel(int $prefetchCount = Channel::DEFAULT_PREFETCH_COUNT): Channel
    {
        $channel = $this->connection()->channel(prefetchCount: $prefetchCount);

        $this->openChannels[] = $channel;

        return $channel;
    }

    /**
     * A queue declared under a name no other test uses, remembered for cleanup.
     *
     * @param array<string, mixed> $arguments
     */
    protected function declareQueue(
        Channel $channel,
        bool $durable = false,
        bool $exclusive = false,
        bool $autoDelete = false,
        array $arguments = [],
        ?string $name = null,
    ): Queue {
        $queue = $channel->queue($name ?? TestAmqpResolver::uniqueName('queue'));

        $queue->declare(
            durable: $durable,
            exclusive: $exclusive,
            autoDelete: $autoDelete,
            arguments: $arguments,
        );

        $this->declaredQueues[] = $queue->name();

        return $queue;
    }

    /**
     * An exchange declared under a name no other test uses, remembered for cleanup.
     */
    protected function declareExchange(Channel $channel, ExchangeTypeEnum $type = ExchangeTypeEnum::Direct): Exchange
    {
        $exchange = $channel->exchange(TestAmqpResolver::uniqueName('exchange'));

        $exchange->declare(type: $type);

        $this->declaredExchanges[] = $exchange->name();

        return $exchange;
    }

    /**
     * Publishes one message straight to a queue through the default exchange, which routes
     * by the queue name.
     */
    protected function publishToQueue(Channel $channel, string $queueName, Message|string $message): void
    {
        $channel->publish(message: $message, exchange: '', routingKey: $queueName);
    }

    /**
     * Pulls the queue until a message shows up. basic.publish carries no reply, so a get()
     * issued right after a publish can legitimately come back empty once.
     */
    protected function waitForMessage(Queue $queue, float $timeoutSeconds = 2.0): ?Delivery
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $delivery = $queue->get();

            if ($delivery !== null) {
                return $delivery;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * Asks the broker for the queue's message count until it reports the one the test
     * expects. Publishing carries no reply, and a message left unacknowledged goes back
     * into the queue a moment after the consumer is cancelled, so a count read right away
     * can legitimately be one step behind.
     *
     * Passively, so the poll never has to know how the queue was declared — a plain
     * declare() would have to repeat every setting or be refused with a 406.
     */
    protected function waitForMessageCount(Queue $queue, int $expected, float $timeoutSeconds = 2.0): int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $count = $queue->declarePassive()->messageCount;

            if ($count >= $expected) {
                return $count;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        return $count;
    }

    /**
     * Asserts that nothing arrives in the queue. Publishing carries no reply, so a single
     * get() would pass before a message that is on its way has landed: the queue is pulled
     * for a while, and the first message to show up fails the test.
     */
    protected function assertQueueStaysEmpty(Queue $queue, float $forSeconds = 0.3): void
    {
        $deadline = microtime(true) + $forSeconds;

        do {
            self::assertNull($queue->get(), 'the queue was expected to stay empty');

            usleep(20_000);
        } while (microtime(true) < $deadline);
    }

    /**
     * Deletes everything the test declared. Best-effort: a test may have deleted some of
     * it already, and a broker that dropped the connection has nothing left to clean.
     */
    protected function cleanUpTopology(): void
    {
        if ($this->connection === null || ($this->declaredQueues === [] && $this->declaredExchanges === [])) {
            $this->declaredQueues    = [];
            $this->declaredExchanges = [];

            return;
        }

        try {
            $channel = $this->connection->channel();

            foreach ($this->declaredQueues as $queueName) {
                try {
                    $channel->queue($queueName)->delete();
                } catch (Throwable) {
                    // Already gone, or the channel died with it: nothing left to delete.
                    $channel = $this->connection->channel();
                }
            }

            foreach ($this->declaredExchanges as $exchangeName) {
                try {
                    $channel->exchange($exchangeName)->delete();
                } catch (Throwable) {
                    $channel = $this->connection->channel();
                }
            }

            $channel->close();
        } catch (Throwable) {
            // The connection is gone — the broker cleaned up with it.
        }

        $this->declaredQueues    = [];
        $this->declaredExchanges = [];
    }
}
