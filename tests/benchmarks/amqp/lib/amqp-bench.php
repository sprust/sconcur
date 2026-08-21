<?php

declare(strict_types=1);

use AMQPChannel as NativeChannel;
use AMQPConnection as NativeConnection;
use AMQPExchange as NativeExchange;
use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPConnection;
use SConcur\Features\Amqp\AMQPExchange;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Tests\Impl\TestAmqpResolver;

use const SConcur\Features\Amqp\AMQP_DURABLE;

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

    public AMQPConnection $connection;

    public AMQPChannel $channel;

    public AMQPExchange $exchange;

    /** @var list<AMQPChannel> */
    public array $asyncChannels;

    /** @var list<AMQPExchange> */
    public array $asyncExchanges;

    /** @var array<string, string> */
    public array $queueNames;

    public ?NativeConnection $nativeConnection;

    public ?NativeChannel $nativeChannel;

    public ?NativeExchange $nativeExchange;

    public function __construct(string $name, int $asyncChannels = self::ASYNC_CHANNELS)
    {
        $this->connection = TestAmqpResolver::getConnection();
        $this->channel    = new AMQPChannel($this->connection);

        $this->exchange = new AMQPExchange($this->channel);

        $this->exchange->setName('');

        $queueNames = [];

        foreach (['native', 'sync', 'async'] as $mode) {
            $queueName = "sconcur_bench_{$name}_$mode";

            $queue = new AMQPQueue($this->channel);

            $queue->setName($queueName);
            $queue->setFlags(AMQP_DURABLE);
            $queue->declareQueue();
            $queue->purge();

            $queueNames[$mode] = $queueName;
        }

        $this->queueNames = $queueNames;

        $channels  = [];
        $exchanges = [];

        for ($index = 0; $index < $asyncChannels; ++$index) {
            $channel = new AMQPChannel($this->connection);

            $exchange = new AMQPExchange($channel);

            $exchange->setName('');

            $channels[]  = $channel;
            $exchanges[] = $exchange;
        }

        $this->asyncChannels  = $channels;
        $this->asyncExchanges = $exchanges;

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
     * The exchange a coroutine publishes through: one of the pooled channels, picked by
     * the call index.
     */
    public function asyncExchange(int $callIndex): AMQPExchange
    {
        return $this->asyncExchanges[$callIndex % count($this->asyncExchanges)];
    }

    public function asyncChannel(int $callIndex): AMQPChannel
    {
        return $this->asyncChannels[$callIndex % count($this->asyncChannels)];
    }

    /**
     * Fills a mode's queue with the messages its calls will read.
     */
    public function seed(string $mode, int $messages, string $body = 'benchmark'): void
    {
        $queueName = $this->queueName($mode);

        for ($index = 0; $index < $messages; ++$index) {
            $this->exchange->publish(message: $body, routingKey: $queueName);
        }
    }

    /**
     * A SConcur queue object bound to a mode's queue, on the given channel.
     */
    public function queue(AMQPChannel $channel, string $mode): AMQPQueue
    {
        $queue = new AMQPQueue($channel);

        $queue->setName($this->queueName($mode));
        $queue->setFlags(AMQP_DURABLE);

        return $queue;
    }
}
