<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Features\Amqp\Delivery;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Impl\TestAmqpResolver;
use SConcur\Tests\Impl\TestApplication;

/**
 * Demo / test AMQP consumer worker: the shape a supervised consumer takes.
 *
 * Everything about the queues comes from argv, which is how WorkerMaster configures a
 * group's workers:
 *
 *   --queues=[{"name":"orders","coroutineCount":4}]  the queues and their weights
 *   --prefetchCount=1                                 default prefetch per coroutine
 *   --maxMessages=0                                   stop after N messages (0 = never)
 *   --masterPid=<pid>                                 injected by the master
 *
 * The credentials are the worker's own business and never travel in argv — a password
 * would be visible in `ps` to every user of the machine.
 *
 * What each message does is decided by its body, so a test can drive the worker:
 *   "sleep:<ms>"      -> async sleep, then acknowledge (concurrency demo)
 *   "reject"          -> reject without requeue
 *   anything else     -> acknowledge, and print what was handled
 */
TestApplication::init();

$queueConsumer = QueueConsumer::fromArgs($_SERVER['argv'] ?? []);

$connection = TestAmqpResolver::getConnection();

// The worker declares its own topology before consuming. QueueConsumer never does —
// a runtime that redeclared a queue with the wrong flags would take the channel down
// with a 406 — but this script owns these queues, and without the declaration a pool
// started before its first publisher would crash-loop on a 404.
$topologyChannel = $connection->channel();

foreach ($queueConsumer->queueSpecs() as $spec) {
    $topologyChannel->queue($spec->name)->declare(durable: true);
}

$topologyChannel->close();

$handled = $queueConsumer->consume(
    connection: $connection,
    handler: static function (Delivery $delivery): void {
        $body = $delivery->body;

        if (str_starts_with($body, 'sleep:')) {
            Sleeper::usleep(
                microseconds: (int) substr($body, strlen('sleep:')) * 1000,
            );
        }

        if ($body === 'reject') {
            $delivery->reject();

            return;
        }

        // Returning acknowledges it: the runtime settles what the handler left open.
        fwrite(STDOUT, 'handled ' . $body . PHP_EOL);
        fflush(STDOUT);
    },
);

fwrite(STDOUT, "consumer finished handled=$handled" . PHP_EOL);

$connection->close();
