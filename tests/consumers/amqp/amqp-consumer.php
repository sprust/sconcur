<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use SConcur\Features\Amqp\AMQPEnvelope;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
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
 *   "ack:<text>"      -> acknowledge, and print what was handled
 *   "sleep:<ms>"      -> async sleep, then acknowledge (concurrency demo)
 *   "reject"          -> reject without requeue
 *   anything else     -> acknowledge
 */
TestApplication::init();

$queueConsumer = QueueConsumer::fromArgs($_SERVER['argv'] ?? []);

$connection = TestAmqpResolver::getConnection();

$handled = $queueConsumer->consume(
    connection: $connection,
    handler: static function (AMQPEnvelope $envelope, AMQPQueue $queue): void {
        $body = $envelope->getBody();

        if (str_starts_with($body, 'sleep:')) {
            Sleeper::usleep(
                microseconds: (int) substr($body, strlen('sleep:')) * 1000,
            );
        }

        if ($body === 'reject') {
            $queue->reject($envelope->getDeliveryTag());

            return;
        }

        $queue->ack($envelope->getDeliveryTag());

        fwrite(STDOUT, 'handled ' . $body . PHP_EOL);
        fflush(STDOUT);
    },
);

fwrite(STDOUT, "consumer finished handled=$handled" . PHP_EOL);

$connection->disconnect();
