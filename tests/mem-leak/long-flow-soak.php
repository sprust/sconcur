<?php

declare(strict_types=1);

// Soak test for streams opened by a coroutine that never ends: one coroutine in a
// WaitGroup loops for the whole run, and every cycle opens a stream, reads it to the end
// and lets it go. That is the shape of a task-pool task or a WebSocket connection handler,
// and it differs from a request handler in one way that matters: its flow is not stopped
// between cycles, so whatever the extension ties to the flow's end instead of the stream's
// end piles up for as long as the process lives.
//
// Such a leak is native. The PHP heap stays flat, so the resident set size is printed
// beside it, and RSS is the column to watch: flat after warm-up means nothing is kept
// per stream.
//
// Run it through `make mem-leak-long-flow scenario=<name> seconds=<n>`, or by hand:
//
//   php -d extension=./ext/build/sconcur.so \
//       tests/mem-leak/long-flow-soak.php <scenario> <seconds>

use SConcur\Connection\Extension;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Impl\TestApplication;
use SConcur\Tests\Impl\TestMongodbResolver;
use SConcur\Tests\Impl\TestPgsqlResolver;
use SConcur\Tests\Impl\TestRedisResolver;
use SConcur\WaitGroup;

require_once __DIR__ . '/../../vendor/autoload.php';

TestApplication::init();

$scenario        = (string) ($_SERVER['argv'][1] ?? 'mongodb');
$durationSeconds = (int) ($_SERVER['argv'][2] ?? 120);

/**
 * How long the subscribe scenario waits between cycles: a subscription owns a socket, and
 * an unpaced loop runs out of ephemeral ports long before it shows a leak. The Redis soak
 * explains the number.
 */
const SUBSCRIBE_PACE_MICROSECONDS = 10_000;

/**
 * Cycles left out of the baseline: the pools, the fibers and the allocator's arenas
 * settling.
 */
const WARM_UP_CYCLES = 2_000;

const REPORT_INTERVAL_SECONDS = 5.0;

/**
 * The resident set size of this process, in bytes, or 0 where /proc is not there.
 */
$readRssBytes = static function (): int {
    $status = @file_get_contents('/proc/self/status');

    if ($status === false || preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $matches) !== 1) {
        return 0;
    }

    return (int) $matches[1] * 1024;
};

$cycle = match ($scenario) {
    // A MongoDB cursor walked to the end over several batches: the case a task that
    // queries every tick hits.
    'mongodb' => (static function (): Closure {
        $collection = TestMongodbResolver::getSconcurTestCollection('long_flow_soak');

        $collection->drop();

        $documents = [];

        for ($index = 0; $index < 20; ++$index) {
            $documents[] = [
                'index' => $index,
            ];
        }

        $collection->insertMany($documents);

        return static function () use ($collection): void {
            foreach ($collection->find(filter: [], batchSize: 5) as $ignored) {
                // Walked to the end.
            }
        };
    })(),

    // SQL rows walked to the end over several batches.
    'sql-query' => (static function (): Closure {
        $connection = TestPgsqlResolver::getConnection();

        return static function () use ($connection): void {
            $rows = $connection->query(
                sql: 'SELECT generate_series(1, 20) AS value',
                batchSize: 5,
            );

            foreach ($rows as $ignored) {
                // Walked to the end.
            }
        };
    })(),

    // A transaction begun and committed: the begin keeps a state alive for the whole
    // transaction, and the commit releases it.
    'sql-transaction' => (static function (): Closure {
        $connection = TestPgsqlResolver::getConnection();

        return static function () use ($connection): void {
            $transaction = $connection->begin();

            $transaction->fetchAll('SELECT 1');

            $transaction->commit();
        };
    })(),

    // A SCAN cursor walked to the end.
    'redis-scan' => (static function (): Closure {
        $connection = TestRedisResolver::getConnection();
        $keyPrefix  = 'sconcur:long-flow-soak:scan';
        $pipeline   = $connection->pipeline();

        for ($index = 0; $index < 50; ++$index) {
            $pipeline->command('SET', ["$keyPrefix:$index", '1']);
        }

        $pipeline->execute();

        return static function () use ($connection, $keyPrefix): void {
            foreach ($connection->scan(match: "$keyPrefix:*", count: 20, batchSize: 10) as $ignored) {
                // Walked to the end.
            }
        };
    })(),

    // A subscription opened, read once and closed.
    'redis-subscribe' => (static function (): Closure {
        $connection = TestRedisResolver::getConnection();
        $channel    = 'sconcur:long-flow-soak:channel';

        return static function () use ($connection, $channel): void {
            $subscription = $connection->subscribe(channels: [$channel]);

            Scheduler::get()->spawn(
                callback: static function () use ($connection, $channel): void {
                    $connection->command('PUBLISH', [$channel, 'payload']);
                },
            );

            $subscription->read();
            $subscription->close();

            usleep(SUBSCRIBE_PACE_MICROSECONDS);
        };
    })(),

    default => throw new RuntimeException("unknown scenario $scenario"),
};

echo "long-flow soak: scenario=$scenario, seconds=$durationSeconds\n";
echo str_repeat('-', 90) . "\n";

$waitGroup = WaitGroup::create();

$waitGroup->add(
    callback: static function () use ($cycle, $durationSeconds, $readRssBytes): void {
        $startTime    = microtime(true);
        $lastReport   = $startTime;
        $iteration    = 0;
        $baselineRss  = 0;
        $baselineHeap = 0;

        while ((microtime(true) - $startTime) < $durationSeconds) {
            $cycle();

            ++$iteration;

            if ($iteration === WARM_UP_CYCLES) {
                $baselineRss  = $readRssBytes();
                $baselineHeap = memory_get_usage(true);
            }

            if ((microtime(true) - $lastReport) < REPORT_INTERVAL_SECONDS) {
                continue;
            }

            $lastReport = microtime(true);

            $rssBytes  = $readRssBytes();
            $heapBytes = memory_get_usage(true);

            printf(
                "%4ds  cycles %-8d rss %6.1f MB  growth %+6.1f MB  heap %5.1f MB  growth %+5.1f MB  tasks %d\n",
                (int) (microtime(true) - $startTime),
                $iteration,
                $rssBytes / 1024 / 1024,
                $baselineRss === 0 ? 0 : ($rssBytes - $baselineRss) / 1024 / 1024,
                $heapBytes / 1024 / 1024,
                $baselineHeap === 0 ? 0 : ($heapBytes - $baselineHeap) / 1024 / 1024,
                Extension::get()->count(),
            );
        }

        echo str_repeat('-', 90) . "\n";
        echo "done: $iteration cycles\n";
    },
);

$waitGroup->waitAll();
