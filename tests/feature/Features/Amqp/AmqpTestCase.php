<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPConnection;
use SConcur\Features\Amqp\AMQPEnvelope;
use SConcur\Features\Amqp\AMQPExchange;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestAmqpResolver;
use Throwable;
use const SConcur\Features\Amqp\AMQP_EX_TYPE_DIRECT;
use const SConcur\Features\Amqp\AMQP_NOPARAM;

/**
 * Shared setup for the AMQP feature tests: one connection to the test broker, plus the
 * throwaway topology a test works on. Everything created here is deleted in tearDown, so
 * a failing test leaves no queues behind on the broker.
 */
abstract class AmqpTestCase extends BaseTestCase
{
    protected ?AMQPConnection $connection = null;

    /** @var list<AMQPChannel> */
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

        $this->connection?->disconnect();
        $this->connection = null;

        parent::tearDown();
    }

    protected function connection(): AMQPConnection
    {
        return $this->connection ??= TestAmqpResolver::getConnection();
    }

    protected function channel(): AMQPChannel
    {
        $channel = new AMQPChannel($this->connection());

        $this->openChannels[] = $channel;

        return $channel;
    }

    /**
     * A queue declared under a name no other test uses, remembered for cleanup.
     */
    protected function declareQueue(AMQPChannel $channel, ?int $flags = null, ?string $name = null): AMQPQueue
    {
        $queue = new AMQPQueue($channel);

        $queue->setName($name ?? TestAmqpResolver::uniqueName('queue'));
        $queue->setFlags($flags);
        $queue->declareQueue();

        $this->declaredQueues[] = (string) $queue->getName();

        return $queue;
    }

    /**
     * A direct exchange declared under a name no other test uses, remembered for cleanup.
     */
    protected function declareExchange(AMQPChannel $channel, string $type = AMQP_EX_TYPE_DIRECT): AMQPExchange
    {
        $exchange = new AMQPExchange($channel);

        $exchange->setName(TestAmqpResolver::uniqueName('exchange'));
        $exchange->setType($type);
        $exchange->setFlags(AMQP_NOPARAM);
        $exchange->declareExchange();

        $this->declaredExchanges[] = (string) $exchange->getName();

        return $exchange;
    }

    /**
     * Publishes one message straight to a queue through the default exchange, which routes
     * by the queue name.
     */
    /**
     * @param array<string, mixed> $attributes publish attributes, e.g. headers
     */
    protected function publishToQueue(
        AMQPChannel $channel,
        string $queueName,
        string $body,
        array $attributes = [],
    ): void {
        $exchange = new AMQPExchange($channel);

        $exchange->setName('');
        $exchange->publish(message: $body, routingKey: $queueName, headers: $attributes);
    }

    /**
     * Pulls the queue until a message shows up. basic.publish carries no reply, so a get()
     * issued right after a publish can legitimately come back empty once.
     */
    protected function waitForMessage(AMQPQueue $queue, float $timeoutSeconds = 2.0): ?AMQPEnvelope
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $envelope = $queue->get();

            if ($envelope !== null) {
                return $envelope;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * Re-declares the queue until the broker reports the message count the test expects.
     * Publishing carries no reply, and a message left unacknowledged goes back into the
     * queue a moment after the consumer is cancelled, so a count read right away can
     * legitimately be one step behind.
     */
    protected function waitForMessageCount(AMQPQueue $queue, int $expected, float $timeoutSeconds = 2.0): int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $count = $queue->declareQueue();

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
    protected function assertQueueStaysEmpty(AMQPQueue $queue, float $forSeconds = 0.3): void
    {
        $deadline = microtime(true) + $forSeconds;

        do {
            $envelope = $queue->get();

            self::assertNull($envelope, 'the queue was expected to stay empty');

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
            $channel = new AMQPChannel($this->connection);

            foreach ($this->declaredQueues as $queueName) {
                $queue = new AMQPQueue($channel);

                $queue->setName($queueName);

                try {
                    $queue->delete();
                } catch (Throwable) {
                    // Already gone, or the channel died with it: nothing left to delete.
                    $channel = new AMQPChannel($this->connection);
                }
            }

            foreach ($this->declaredExchanges as $exchangeName) {
                $exchange = new AMQPExchange($channel);

                $exchange->setName($exchangeName);

                try {
                    $exchange->delete();
                } catch (Throwable) {
                    $channel = new AMQPChannel($this->connection);
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
