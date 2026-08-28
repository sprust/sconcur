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
use SConcur\Exceptions\Amqp\UnroutableMessageException;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Features\Amqp\Delivery;
use SConcur\Features\Amqp\ExchangeTypeEnum;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Impl\TestAmqpResolver;
use SConcur\Tests\Impl\TestApplication;
use SConcur\WaitGroup;

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

$options = TestAmqpResolver::getOptions();

$queueName    = 'sconcur_soak_' . $scenario;
$exchangeName = 'sconcur_soak_exchange_' . $scenario;

// The long-lived topology every scenario works on. A scenario that needs its own
// connection builds one per cycle instead.
$connection = new Connection($options);
$connection->connect();

$channel = $connection->channel();

$exchange = $channel->exchange($exchangeName);

$exchange->declare(
    type: ExchangeTypeEnum::Direct,
    durable: true,
);

$queue = $channel->queue($queueName);

$queue->declare(durable: true);
$queue->purge();
$queue->bind(
    exchange: $exchangeName,
    routingKey: 'soak',
);

/**
 * One cycle of each scenario. Whatever it opens, it closes.
 */
$scenarios = [
    // Publishing and reading back on a channel that lives for the whole run.
    'publish' => static function () use ($exchange, $queue): void {
        $exchange->publish(
            message: 'soak',
            routingKey: 'soak',
        );

        if ($queue->get(autoAck: true) === null) {
            usleep(1000);
        }
    },

    // A connection and a channel per cycle: the pool, the handle registry and the channel
    // registry all have to give the resources back.
    'churn' => static function () use ($options, $queueName): void {
        $connection = new Connection($options);

        $connection->connect();

        $channel = $connection->channel();

        $channel->queue($queueName)->declare(durable: true);

        $channel->close();
        $connection->close();
    },

    // A consumer per cycle, opened, fed one message and cancelled.
    'consume' => static function () use ($connection, $exchange, $queueName): void {
        $exchange->publish(
            message: 'soak',
            routingKey: 'soak',
        );

        $channel = $connection->channel();

        foreach ($channel->queue($queueName)->consume() as $delivery) {
            $delivery->ack();

            break;
        }

        $channel->close();
    },

    // Ten coroutines publishing and reading at the same time, each on its own channel.
    'fanout' => static function () use ($connection, $exchangeName, $queueName): void {
        $waitGroup = WaitGroup::create();

        for ($index = 0; $index < 10; ++$index) {
            $waitGroup->add(static function () use ($connection, $exchangeName, $queueName): int {
                $channel = $connection->channel();

                $channel->exchange($exchangeName)->publish(message: 'soak', routingKey: 'soak');

                $delivery = $channel->queue($queueName)->get(autoAck: true);

                $channel->close();

                return $delivery === null ? 0 : 1;
            });
        }

        $waitGroup->waitAll();
    },

    // The failure path: every cycle kills a channel with a 404 and throws it away. This is
    // where a dead channel would pile up if the registries did not release it.
    'errors' => static function () use ($connection): void {
        $channel = $connection->channel();

        try {
            $channel->queue('sconcur_soak_missing_' . bin2hex(random_bytes(4)))->declarePassive();
        } catch (Throwable) {
            // The point of the cycle: the broker closes the channel over this.
        }

        $channel->close();
    },

    // Publisher confirms and a returned message, the two wait loops.
    'confirms' => static function () use ($connection, $exchangeName): void {
        $channel = $connection->channel();

        $exchange = $channel->exchange($exchangeName);

        $exchange->publishConfirmed(
            message: 'soak',
            routingKey: 'soak',
            timeoutSeconds: 2.0,
        );

        try {
            // Routes nowhere, so the broker sends it back and the publish fails with it —
            // the return and the confirm are drained by the same wait.
            $exchange->publishConfirmed(
            message: 'returned',
            routingKey: 'nowhere',
            timeoutSeconds: 2.0,
        );
        } catch (UnroutableMessageException) {
            // The point of the cycle.
        }

        $channel->close();
    },

    // A coroutine that opens and cancels consumers in a loop, the way a worker switching
    // between queues does. Its flow lives as long as the coroutine, so every stream it
    // leaves behind stays with it.
    'consume-async' => static function () use ($connection, $exchange, $queueName): void {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(static function () use ($connection, $exchange, $queueName): int {
            $channel = $connection->channel();

            $queue = $channel->queue($queueName);

            $taken = 0;

            for ($round = 0; $round < 10; ++$round) {
                $exchange->publish(
                    message: 'soak',
                    routingKey: 'soak',
                );

                foreach ($queue->consume() as $delivery) {
                    ++$taken;

                    $delivery->ack();

                    break;
                }
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
            $channel = $connection->channel();

            foreach ($channel->queue($queueName)->consume() as $delivery) {
                $delivery->ack();
            }

            return 'ended';
        });

        $waitGroup->add(static function () use ($waitGroup): string {
            Sleeper::usleep(microseconds: 20_000);

            $waitGroup->stop();

            return 'stopped';
        });

        $waitGroup->waitAll();
    },

    // The supervised consumer with the channels it lends its handlers. One short run per
    // cycle: a few messages in, a QueueConsumer takes them, and the handlers exercise every
    // way a channel comes back — clean, dirty with an unread return, and cut by a deadline.
    // What must stay flat is the pool: channels, connections and the handles over the
    // channels the deliveries arrive on.
    'consumer' => static function () use ($connection, $channel, $queueName): void {
        // Enough for the consumer to live a while rather than start and stop: the channels
        // are reused across messages, trimmed, given up dirty and reopened, which is the
        // accounting a short run never reaches.
        $messages = 60;

        for ($index = 0; $index < $messages; ++$index) {
            $channel->publish(
                message: "job-$index",
                exchange: '',
                routingKey: $queueName,
            );
        }

        $queueConsumer = new QueueConsumer(
            queues: (string) json_encode([['name' => $queueName, 'coroutineCount' => 1]]),
            prefetchCount: 4,
            handlerTimeoutMs: 200,
            maxMessages: $messages,
        );

        $queueConsumer->consume(
            connection: $connection,
            handler: static function (Delivery $delivery) use ($queueName): void {
                $own = $delivery->channel();

                if ($own === null) {
                    return;
                }

                // A quarter of them leave an answer nobody reads, so the pool has to give
                // the channel up and open another; a quarter run past the deadline and are
                // unwound mid-handler; the rest come back clean and are reused.
                $tail = (int) substr($delivery->body, -1) % 4;

                if ($tail === 0) {
                    $own->publish(
                        message: $delivery->body,
                        exchange: '',
                        routingKey: 'sconcur_soak_nowhere',
                        mandatory: true,
                    );

                    return;
                }

                if ($tail === 1) {
                    Sleeper::usleep(microseconds: 400_000);

                    return;
                }

                $own->publishConfirmed(
                    message: $delivery->body,
                    exchange: '',
                    routingKey: $queueName === '' ? 'sconcur_soak_nowhere' : 'sconcur_soak_sink',
                    timeoutSeconds: 2.0,
                    mandatory: false,
                );
            },
            onError: static function (): void {
                // A handler cut by its deadline is the point of the scenario, not a failure
                // of the run.
            },
        );
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
$connection->close();

printf(
    "done: %d cycles in %.1fs (%.0f/s), %d failures\n",
    $cycleCount,
    microtime(true) - $startTime,
    $cycleCount / max(microtime(true) - $startTime, 0.001),
    $failures,
);
