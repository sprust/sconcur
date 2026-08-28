<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/amqp-bench.php';

$benchmarker = new Benchmarker(
    name: 'amqp-publish',
);

$bench = new AmqpBench(name: 'publish');

/** The queue handles are built on first use: a handle costs nothing and talks to nobody. */
$syncQueue = null;

/** @var array<int, SConcur\Features\Amqp\Queue> $asyncQueues */
$asyncQueues = [];

// One publish per call: the message goes through the default exchange straight into the
// mode's own queue. Nothing is consumed here, so the queues are purged on the next run.
$benchmarker->run(
    nativeCallback: $bench->nativeExchange === null
        ? null
        : static function (int $callIndex) use ($bench): void {
            $bench->nativeExchange->publish('benchmark', $bench->queueName('native'));
        },
    syncCallback: static function (int $callIndex) use ($bench, &$syncQueue): void {
        $syncQueue ??= $bench->queue($bench->channel, 'sync');

        $syncQueue->publish('benchmark');
    },
    asyncCallback: static function (int $callIndex) use ($bench, &$asyncQueues): void {
        $channelIndex = $callIndex % count($bench->asyncChannels);

        $asyncQueues[$channelIndex] ??= $bench->queue($bench->asyncChannels[$channelIndex], 'async');

        $asyncQueues[$channelIndex]->publish('benchmark');
    },
);
