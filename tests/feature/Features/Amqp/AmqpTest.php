<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPConnection;
use SConcur\Features\Amqp\AMQPEnvelope;
use SConcur\Features\Amqp\AMQPExchange;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Tests\Feature\BaseAsyncTestCase;
use SConcur\Tests\Impl\TestAmqpResolver;
use Throwable;
use const SConcur\Features\Amqp\AMQP_DURABLE;
use const SConcur\Features\Amqp\AMQP_PASSIVE;

/**
 * The feature's async test: two coroutines each publish to their own queue and read the
 * message back, so the event order recorded by the parent proves the two are running at
 * the same time rather than one after the other.
 */
class AmqpTest extends BaseAsyncTestCase
{
    private ?AMQPConnection $connection = null;

    private ?AMQPChannel $channel = null;

    /** @var array<int, string> */
    private array $queueNames = [];

    /** @var array<int, string> */
    private array $received = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = TestAmqpResolver::getConnection();
        $this->channel    = new AMQPChannel($this->connection);

        foreach ([1, 2] as $index) {
            $queue = new AMQPQueue($this->channel);

            $queue->setName(TestAmqpResolver::uniqueName("async_$index"));
            $queue->setFlags(AMQP_DURABLE);
            $queue->declareQueue();

            $this->queueNames[$index] = (string) $queue->getName();
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->queueNames as $queueName) {
                $queue = new AMQPQueue($this->channel);

                $queue->setName($queueName);
                $queue->delete();
            }

            $this->channel?->close();
            $this->connection?->disconnect();
        } catch (Throwable) {
            // The broker is gone, and with it everything this test declared.
        }

        $this->queueNames = [];
        $this->channel    = null;
        $this->connection = null;

        parent::tearDown();
    }

    protected function on_1_start(): void
    {
        $this->publish(1, 'first');
    }

    protected function on_1_middle(): void
    {
        $this->received[1] = $this->consumeOne(1);
    }

    protected function on_2_start(): void
    {
        $this->publish(2, 'second');
    }

    protected function on_2_middle(): void
    {
        $this->received[2] = $this->consumeOne(2);
    }

    protected function on_iterate(): void
    {
        //
    }

    protected function on_exception(): void
    {
        $queue = new AMQPQueue(new AMQPChannel($this->connection));

        // Nothing declared this queue, and a passive declaration refuses to create it.
        $queue->setName(TestAmqpResolver::uniqueName('missing'));
        $queue->setFlags(AMQP_PASSIVE);

        $queue->declareQueue();
    }

    protected function assertException(Throwable $exception): void
    {
        self::assertTrue(
            str_contains($exception->getMessage(), 'NOT_FOUND'),
            'expected the broker to report a missing queue, got: ' . $exception->getMessage(),
        );
    }

    protected function assertResult(array $results): void
    {
        // Both coroutines went through publish and consume; the parent already checked
        // that their events interleaved, which is what running at the same time means
        // here — neither waited for the other to finish.
        $received = $this->received;

        // Which coroutine finished first is up to the scheduler; only the pairing matters.
        ksort($received);

        self::assertSame(
            [
                1 => 'first',
                2 => 'second',
            ],
            $received,
        );
    }

    /**
     * Publishes one message straight to the coroutine's own queue, on its own channel.
     */
    private function publish(int $index, string $body): void
    {
        $channel = new AMQPChannel($this->connection);

        $exchange = new AMQPExchange($channel);

        $exchange->setName('');
        $exchange->publish(message: $body, routingKey: $this->queueNames[$index]);

        $channel->close();
    }

    /**
     * Consumes exactly one message from the coroutine's queue, suspending it until the
     * message arrives.
     */
    private function consumeOne(int $index): string
    {
        $channel = new AMQPChannel($this->connection);

        $queue = new AMQPQueue($channel);

        $queue->setName($this->queueNames[$index]);
        $queue->setFlags(AMQP_DURABLE);
        $queue->declareQueue();

        $body = '';

        $queue->consume(function (AMQPEnvelope $envelope, AMQPQueue $queue) use (&$body): bool {
            $body = $envelope->getBody();

            $queue->ack($envelope->getDeliveryTag());

            return false;
        });

        $queue->cancel();
        $channel->close();

        return $body;
    }
}
