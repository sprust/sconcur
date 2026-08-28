<?php

declare(strict_types=1);

use AMQPQueue as NativeQueue;
use SConcur\Features\Amqp\Queue;

require_once __DIR__ . '/lib/amqp-bench.php';

$benchmarker = new Benchmarker(
    name: 'amqp-get',
);

$bench = new AmqpBench(name: 'get');

// Every call takes one message off its mode's queue, so each mode is seeded with more
// messages than it will read (the harness runs a warm-up before the measured calls).
$messagesPerMode = $benchmarker->getTotal() * 2 + 40;

foreach (['native', 'sync', 'async'] as $mode) {
    $bench->seed(mode: $mode, messages: $messagesPerMode);
}

$syncQueue = $bench->queue($bench->channel, 'sync');

/** @var array<int, Queue> $asyncQueues */
$asyncQueues = [];

$nativeQueue = null;

if ($bench->nativeChannel !== null) {
    $nativeQueue = new NativeQueue($bench->nativeChannel);

    $nativeQueue->setName($bench->queueName('native'));
    $nativeQueue->setFlags(AMQP_DURABLE);
}

$benchmarker->run(
    nativeCallback: $nativeQueue === null
        ? null
        : static function (int $callIndex) use ($nativeQueue): void {
            $nativeQueue->get(flags: AMQP_AUTOACK);
        },
    syncCallback: static function (int $callIndex) use ($syncQueue): void {
        $syncQueue->get(autoAck: true);
    },
    asyncCallback: static function (int $callIndex) use ($bench, &$asyncQueues): void {
        $channelIndex = $callIndex % count($bench->asyncChannels);

        $asyncQueues[$channelIndex] ??= $bench->queue($bench->asyncChannels[$channelIndex], 'async');

        $asyncQueues[$channelIndex]->get(autoAck: true);
    },
);
