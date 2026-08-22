<?php

declare(strict_types=1);

// Soak test for the AMQP feature: runs one scenario in a loop and prints, every five
// seconds, what the two runtimes are holding — the PHP heap and its dangling tasks, the Go
// runtime's goroutines and heap.
//
// Everything a cycle creates is released inside that cycle, so any value that only grows
// is a leak.
//
// Run it through `make mem-leak-amqp scenario=<name> seconds=<n>`, which sets the profiler
// address the Go-side columns are read from. By hand:
//
//   SCONCUR_PPROF_ADDR=127.0.0.1:6060 php -d extension=./ext/build/sconcur.so \
//       tests/mem-leak/amqp-soak.php <scenario> <seconds>
//
// Without SCONCUR_PPROF_ADDR the run still works and reports the two Go columns as zero.

use SConcur\Connection\Extension;
use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPConnection;
use SConcur\Features\Amqp\AMQPEnvelope;
use SConcur\Features\Amqp\AMQPExchange;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Impl\TestAmqpResolver;
use SConcur\Tests\Impl\TestApplication;
use SConcur\WaitGroup;

use const SConcur\Features\Amqp\AMQP_AUTOACK;
use const SConcur\Features\Amqp\AMQP_DURABLE;
use const SConcur\Features\Amqp\AMQP_MANDATORY;
use const SConcur\Features\Amqp\AMQP_PASSIVE;

require_once __DIR__ . '/../../vendor/autoload.php';

TestApplication::init();

$scenario       = (string) ($_SERVER['argv'][1] ?? 'publish');
$durationSecond = (int) ($_SERVER['argv'][2] ?? 120);

$profilerAddress = getenv('SCONCUR_PPROF_ADDR') ?: '';

/**
 * The Go runtime's goroutine count and heap, read from the profiler the extension exposes.
 *
 * @return array{goroutines: int, heapBytes: int}
 */
$readRuntime = static function () use ($profilerAddress): array {
    if ($profilerAddress === '') {
        return ['goroutines' => 0, 'heapBytes' => 0];
    }

    $context = stream_context_create(['http' => ['timeout' => 2]]);

    $goroutines = @file_get_contents("http://$profilerAddress/debug/pprof/goroutine?debug=1", false, $context);
    $heap       = @file_get_contents("http://$profilerAddress/debug/pprof/heap?debug=1", false, $context);

    preg_match('/goroutine profile: total (\d+)/', (string) $goroutines, $goroutineMatch);
    preg_match('/# HeapInuse = (\d+)/', (string) $heap, $heapMatch);

    return [
        'goroutines' => (int) ($goroutineMatch[1] ?? 0),
        'heapBytes'  => (int) ($heapMatch[1] ?? 0),
    ];
};

$credentials = TestAmqpResolver::getCredentials();

$queueName    = 'sconcur_soak_' . $scenario;
$exchangeName = 'sconcur_soak_exchange_' . $scenario;

// The long-lived topology every scenario works on. A scenario that needs its own
// connection builds one per cycle instead.
$connection = new AMQPConnection($credentials);
$connection->connect();

$channel = new AMQPChannel($connection);

$exchange = new AMQPExchange($channel);

$exchange->setName($exchangeName);
$exchange->setType('direct');
$exchange->setFlags(AMQP_DURABLE);
$exchange->declareExchange();

$queue = new AMQPQueue($channel);

$queue->setName($queueName);
$queue->setFlags(AMQP_DURABLE);
$queue->declareQueue();
$queue->purge();
$queue->bind(exchangeName: $exchangeName, routingKey: 'soak');

/**
 * One cycle of each scenario. Whatever it opens, it closes.
 */
$scenarios = [
    // Publishing and reading back on a channel that lives for the whole run.
    'publish' => static function () use ($exchange, $queue): void {
        $exchange->publish(message: 'soak', routingKey: 'soak');

        $envelope = $queue->get(flags: AMQP_AUTOACK);

        if ($envelope === null) {
            usleep(1000);
        }
    },

    // A connection and a channel per cycle: the pool, the handle registry and the channel
    // registry all have to give the resources back.
    'churn' => static function () use ($credentials, $queueName): void {
        $connection = new AMQPConnection($credentials);

        $connection->connect();

        $channel = new AMQPChannel($connection);

        $queue = new AMQPQueue($channel);

        $queue->setName($queueName);
        $queue->setFlags(AMQP_DURABLE);
        $queue->declareQueue();

        $channel->close();
        $connection->disconnect();
    },

    // A consumer per cycle, opened, fed one message and cancelled.
    'consume' => static function () use ($connection, $exchange, $queueName): void {
        $exchange->publish(message: 'soak', routingKey: 'soak');

        $channel = new AMQPChannel($connection);

        $queue = new AMQPQueue($channel);

        $queue->setName($queueName);
        $queue->setFlags(AMQP_DURABLE);
        $queue->declareQueue();

        $queue->consume(
            callback: static function (AMQPEnvelope $envelope, AMQPQueue $queue): bool {
                $queue->ack($envelope->getDeliveryTag());

                return false;
            },
        );

        $queue->cancel();
        $channel->close();
    },

    // Ten coroutines publishing and reading at the same time, each on its own channel.
    'fanout' => static function () use ($connection, $exchangeName, $queueName): void {
        $waitGroup = WaitGroup::create();

        for ($index = 0; $index < 10; ++$index) {
            $waitGroup->add(static function () use ($connection, $exchangeName, $queueName): int {
                $channel = new AMQPChannel($connection);

                $exchange = new AMQPExchange($channel);

                $exchange->setName($exchangeName);
                $exchange->publish(message: 'soak', routingKey: 'soak');

                $queue = new AMQPQueue($channel);

                $queue->setName($queueName);
                $queue->setFlags(AMQP_DURABLE);

                $envelope = $queue->get(flags: AMQP_AUTOACK);

                $channel->close();

                return $envelope === null ? 0 : 1;
            });
        }

        $waitGroup->waitAll();
    },

    // The failure path: every cycle kills a channel with a 404 and throws it away. This is
    // where a dead channel would pile up if the registries did not release it.
    'errors' => static function () use ($connection): void {
        $channel = new AMQPChannel($connection);

        $queue = new AMQPQueue($channel);

        $queue->setName('sconcur_soak_missing_' . bin2hex(random_bytes(4)));
        $queue->setFlags(AMQP_PASSIVE);

        try {
            $queue->declareQueue();
        } catch (Throwable) {
            // The point of the cycle: the broker closes the channel over this.
        }

        $channel->close();
    },

    // Publisher confirms and a returned message, the two wait loops.
    'confirms' => static function () use ($connection, $exchangeName): void {
        $channel = new AMQPChannel($connection);

        $channel->confirmSelect();

        $channel->setConfirmCallback(
            static fn(int $deliveryTag, bool $multiple): bool => true,
            null,
        );

        $channel->setReturnCallback(
            static fn(int $code, string $text, string $exchange, string $key, $properties, string $body): bool => false,
        );

        $exchange = new AMQPExchange($channel);

        $exchange->setName($exchangeName);
        $exchange->publish(message: 'soak', routingKey: 'soak');
        $exchange->publish(message: 'returned', routingKey: 'nowhere', flags: AMQP_MANDATORY);

        // The returns first: waitForConfirm() collects them too, and a wait for something
        // already collected has nothing left to find.
        $channel->waitForBasicReturn(timeout: 2.0);
        $channel->waitForConfirm(timeout: 2.0);

        $channel->close();
    },

    // A coroutine that opens and cancels consumers in a loop, the way a worker switching
    // between queues does. Its flow lives as long as the coroutine, so every stream it
    // leaves behind stays with it.
    'consume-async' => static function () use ($connection, $exchange, $queueName): void {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(static function () use ($connection, $exchange, $queueName): int {
            $channel = new AMQPChannel($connection);

            $queue = new AMQPQueue($channel);

            $queue->setName($queueName);
            $queue->setFlags(AMQP_DURABLE);
            $queue->declareQueue();

            $taken = 0;

            for ($round = 0; $round < 10; ++$round) {
                $exchange->publish(message: 'soak', routingKey: 'soak');

                $queue->consume(
                    callback: static function (AMQPEnvelope $envelope, AMQPQueue $queue) use (&$taken): bool {
                        ++$taken;

                        $queue->ack($envelope->getDeliveryTag());

                        return false;
                    },
                );

                $queue->cancel();
            }

            $channel->close();

            return $taken;
        });

        $waitGroup->waitAll();
    },

    // A consumer stopped from the outside: the flow is cancelled while the coroutine waits
    // for a delivery that never comes.
    'stop' => static function () use ($connection, $queueName): void {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(static function () use ($connection, $queueName): string {
            $channel = new AMQPChannel($connection);

            $queue = new AMQPQueue($channel);

            $queue->setName($queueName);
            $queue->setFlags(AMQP_DURABLE);
            $queue->declareQueue();

            $queue->consume(callback: static fn(AMQPEnvelope $envelope): bool => true);

            return 'ended';
        });

        $waitGroup->add(static function () use ($waitGroup): string {
            Sleeper::usleep(microseconds: 20_000);

            $waitGroup->stop();

            return 'stopped';
        });

        $waitGroup->waitAll();
    },
];

if (!isset($scenarios[$scenario])) {
    echo 'unknown scenario ', $scenario, '; known: ', implode(', ', array_keys($scenarios)), PHP_EOL;

    exit(1);
}

$cycle     = $scenarios[$scenario];
$extension = Extension::get();

$startTime  = microtime(true);
$deadline   = $startTime + $durationSecond;
$cycleCount = 0;
$failures   = 0;
$lastReport = 0.0;

echo "scenario=$scenario duration={$durationSecond}s\n";
echo "elapsed cycles  php_mb  php_peak_mb  tasks  goroutines  go_heap_mb  failures\n";

while (microtime(true) < $deadline) {
    try {
        $cycle();
    } catch (Throwable $exception) {
        ++$failures;

        if ($failures <= 3) {
            echo '  failure: ', get_class($exception), ': ', $exception->getMessage(), PHP_EOL;
        }
    }

    ++$cycleCount;

    $elapsed = microtime(true) - $startTime;

    if ($elapsed - $lastReport < 5) {
        continue;
    }

    $lastReport = $elapsed;

    gc_collect_cycles();

    $runtime = $readRuntime();

    printf(
        "%7.1f %6d %7.2f %12.2f %6d %11d %11.2f %9d\n",
        $elapsed,
        $cycleCount,
        memory_get_usage() / 1024 / 1024,
        memory_get_peak_usage() / 1024 / 1024,
        $extension->count(),
        $runtime['goroutines'],
        $runtime['heapBytes'] / 1024 / 1024,
        $failures,
    );
}

$queue->delete();
$exchange->delete();

$channel->close();
$connection->disconnect();

printf(
    "done: %d cycles in %.1fs (%.0f/s), %d failures\n",
    $cycleCount,
    microtime(true) - $startTime,
    $cycleCount / max(microtime(true) - $startTime, 0.001),
    $failures,
);
