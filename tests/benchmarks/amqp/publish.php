<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/amqp-bench.php';

$benchmarker = new Benchmarker(
    name: 'amqp-publish',
);

$bench = new AmqpBench(name: 'publish');

// One publish per call: the message goes through the default exchange straight into the
// mode's own queue. Nothing is consumed here, so the queues are purged on the next run.
$benchmarker->run(
    nativeCallback: $bench->nativeExchange === null
        ? null
        : static function (int $callIndex) use ($bench): void {
            $bench->nativeExchange->publish('benchmark', $bench->queueName('native'));
        },
    syncCallback: static function (int $callIndex) use ($bench): void {
        $bench->exchange->publish(message: 'benchmark', routingKey: $bench->queueName('sync'));
    },
    asyncCallback: static function (int $callIndex) use ($bench): void {
        $bench->asyncExchange($callIndex)->publish(
            message: 'benchmark',
            routingKey: $bench->queueName('async'),
        );
    },
);
