<?php

declare(strict_types=1);

// Consume benchmark: several queues drained at the same time, which is the case the
// feature exists for. ext-amqp holds the PHP thread inside consume(), so it works the
// queues one after another; SConcur suspends only the coroutine, so one process pulls all
// of them at once.
//
// Usage: make bench-amqp-consume c="<consumers> <messages per consumer>"

use AMQPChannel as NativeChannel;
use AMQPConnection as NativeConnection;
use AMQPEnvelope as NativeEnvelope;
use AMQPExchange as NativeExchange;
use AMQPQueue as NativeQueue;
use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPEnvelope;
use SConcur\Features\Amqp\AMQPExchange;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Tests\Impl\TestAmqpResolver;
use SConcur\Tests\Impl\TestApplication;
use SConcur\WaitGroup;

use const SConcur\Features\Amqp\AMQP_AUTOACK;
use const SConcur\Features\Amqp\AMQP_DURABLE;

error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

require_once __DIR__ . '/../../../vendor/autoload.php';

TestApplication::init();

$consumers          = (int) ($_SERVER['argv'][1] ?? 10);
$messagesPerConsume = (int) ($_SERVER['argv'][2] ?? 200);

$connection = TestAmqpResolver::getConnection();
$channel    = new AMQPChannel($connection);

$seeder = new AMQPExchange($channel);

$seeder->setName('');

/**
 * Declares the queues of one mode and fills each with the messages its consumer will
 * read.
 *
 * @return list<string>
 */
$prepare = static function (string $mode) use ($channel, $seeder, $consumers, $messagesPerConsume): array {
    $names = [];

    for ($index = 0; $index < $consumers; ++$index) {
        $name = "sconcur_bench_consume_{$mode}_$index";

        $queue = new AMQPQueue($channel);

        $queue->setName($name);
        $queue->setFlags(AMQP_DURABLE);
        $queue->declareQueue();
        $queue->purge();

        for ($message = 0; $message < $messagesPerConsume; ++$message) {
            $seeder->publish(message: 'benchmark', routingKey: $name);
        }

        $names[] = $name;
    }

    return $names;
};

/**
 * Drains one queue through one SConcur consumer and reports how many messages it took.
 */
$drain = static function (string $queueName, int $messages) use ($connection): int {
    $channel = new AMQPChannel($connection);

    $queue = new AMQPQueue($channel);

    $queue->setName($queueName);
    $queue->setFlags(AMQP_DURABLE);

    $taken = 0;

    $queue->consume(
        static function (AMQPEnvelope $envelope, AMQPQueue $queue) use (&$taken, $messages): bool {
            ++$taken;

            return $taken < $messages;
        },
        AMQP_AUTOACK,
    );

    $queue->cancel();
    $channel->close();

    return $taken;
};

$results = [];

// Native: ext-amqp holds the thread, so the queues are drained one after another.
if (extension_loaded('amqp')) {
    $nativeQueues = $prepare('native');

    $nativeConnection = new NativeConnection(TestAmqpResolver::getCredentials());

    $nativeConnection->connect();

    $startTime = microtime(true);

    $taken = 0;

    foreach ($nativeQueues as $queueName) {
        $nativeChannel = new NativeChannel($nativeConnection);

        $queue = new NativeQueue($nativeChannel);

        $queue->setName($queueName);
        $queue->setFlags(AMQP_DURABLE);

        $queueTaken = 0;

        $queue->consume(
            static function (NativeEnvelope $envelope, NativeQueue $queue) use (
                &$queueTaken,
                $messagesPerConsume,
            ): bool {
                ++$queueTaken;

                return $queueTaken < $messagesPerConsume;
            },
            AMQP_AUTOACK,
        );

        $queue->cancel();
        $nativeChannel->close();

        $taken += $queueTaken;
    }

    $results['native (ext-amqp)'] = [microtime(true) - $startTime, $taken];

    $nativeConnection->disconnect();
}

// SConcur outside a coroutine: the same sequential shape, through the extension.
$syncQueues = $prepare('sync');

$startTime = microtime(true);

$taken = 0;

foreach ($syncQueues as $queueName) {
    $taken += $drain($queueName, $messagesPerConsume);
}

$results['sconcur sync'] = [microtime(true) - $startTime, $taken];

// SConcur in coroutines: every queue is pulled at the same time by one process.
$asyncQueues = $prepare('async');

$startTime = microtime(true);

$waitGroup = WaitGroup::create();

foreach ($asyncQueues as $queueName) {
    $waitGroup->add(static fn(): int => $drain($queueName, $messagesPerConsume));
}

$taken = 0;

foreach ($waitGroup->iterate() as $result) {
    $taken += (int) $result;
}

$results['sconcur async'] = [microtime(true) - $startTime, $taken];

$channel->close();
$connection->disconnect();

printf(
    "Consume: %d queues x %d messages (%d total)\n",
    $consumers,
    $messagesPerConsume,
    $consumers * $messagesPerConsume,
);

foreach ($results as $mode => [$elapsedSeconds, $taken]) {
    printf(
        "  %-18s %8.3f s  %10.0f msg/s  (%d messages)\n",
        $mode,
        $elapsedSeconds,
        $elapsedSeconds > 0 ? $taken / $elapsedSeconds : 0,
        $taken,
    );
}

echo str_repeat('-', 80) . "\n";
