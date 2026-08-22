<?php

declare(strict_types=1);

use AMQPChannel as NativeChannel;
use AMQPConnection as NativeConnection;
use AMQPExchange as NativeExchange;
use SConcur\Features\Amqp\Channel;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\Queue;
use SConcur\Tests\Impl\TestAmqpResolver;

require_once __DIR__ . '/../../lib/benchmarker.php';

/**
 * The topology and the connections the three AMQP benchmarks share: one queue per mode
 * (native ext-amqp, SConcur sync, SConcur async), so the modes never compete for the same
 * messages, plus a small pool of channels for the async fan — a channel is serialized on
 * the broker, so coroutines that share one would queue up behind each other and the fan
 * would measure nothing.
 */
readonly class AmqpBench
{
    /** How many channels the async mode spreads its coroutines over. */
    private const int ASYNC_CHANNELS = 50;

    public Connection $connection;

    public Channel $channel;

    /** @var list<Channel> */
    public array $asyncChannels;

    /** @var array<string, string> */
    public array $queueNames;

    public ?NativeConnection $nativeConnection;

    public ?NativeChannel $nativeChannel;

    public ?NativeExchange $nativeExchange;

    public function __construct(string $name, int $asyncChannels = self::ASYNC_CHANNELS)
    {
        $this->connection = TestAmqpResolver::getConnection();
        $this->channel    = $this->connection->channel();

        $queueNames = [];

        foreach (['native', 'sync', 'async'] as $mode) {
            $queueName = "sconcur_bench_{$name}_$mode";

            $queue = $this->channel->queue($queueName);

            $queue->declare(durable: true);
            $queue->purge();

            $queueNames[$mode] = $queueName;
        }

        $this->queueNames = $queueNames;

        $channels = [];

        for ($index = 0; $index < $asyncChannels; ++$index) {
            $channels[] = $this->connection->channel();
        }

        $this->asyncChannels = $channels;

        if (!extension_loaded('amqp')) {
            $this->nativeConnection = null;
            $this->nativeChannel    = null;
            $this->nativeExchange   = null;

            return;
        }

        $this->nativeConnection = new NativeConnection(TestAmqpResolver::getCredentials());

        $this->nativeConnection->connect();

        $this->nativeChannel = new NativeChannel($this->nativeConnection);

        $this->nativeExchange = new NativeExchange($this->nativeChannel);

        $this->nativeExchange->setName('');
    }

    /**
     * The queue a mode works on.
     */
    public function queueName(string $mode): string
    {
        return $this->queueNames[$mode];
    }

    /**
     * The channel a coroutine works on: one of the pooled ones, picked by the call index.
     */
    public function asyncChannel(int $callIndex): Channel
    {
        return $this->asyncChannels[$callIndex % count($this->asyncChannels)];
    }

    /**
     * Fills a mode's queue with the messages its calls will read.
     */
    public function seed(string $mode, int $messages, string $body = 'benchmark'): void
    {
        $queue = $this->channel->queue($this->queueName($mode));

        for ($index = 0; $index < $messages; ++$index) {
            $queue->publish($body);
        }
    }

    /**
     * A queue handle for a mode's queue, on the given channel.
     */
    public function queue(Channel $channel, string $mode): Queue
    {
        return $channel->queue($this->queueName($mode));
    }
}
