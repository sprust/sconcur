<?php

declare(strict_types=1);

// Soak test for the Redis feature: runs one scenario in a loop and prints, every five
// seconds, what is held on both sides — the PHP heap and its dangling tasks, and how many
// clients the server itself still has open.
//
// Everything a cycle creates is released inside that cycle, so any value that only grows
// is a leak. The client count matters as much as the memory: a worker flat on its own
// heap can still leave sockets behind on the other side, and the three things this feature
// opens connections for — a blocking command, a cursor and a subscription — are exactly
// the ones that would.
//
// Run it through `make mem-leak-redis scenario=<name> seconds=<n>`, or by hand:
//
//   php -d extension=./ext/build/sconcur.so \
//       tests/mem-leak/redis-soak.php <scenario> <seconds>

use SConcur\Connection\Extension;
use SConcur\Features\Redis\Connection;
use SConcur\Features\Redis\Pipeline;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Impl\TestApplication;
use SConcur\Tests\Impl\TestRedisResolver;
use SConcur\WaitGroup;

require_once __DIR__ . '/../../vendor/autoload.php';

TestApplication::init();

$scenario        = (string) ($_SERVER['argv'][1] ?? 'command');
$durationSeconds = (int) ($_SERVER['argv'][2] ?? 120);

$connection = TestRedisResolver::getConnection();

$connection->flushDb(confirm: true);

$keyPrefix = 'sconcur:soak:' . $scenario;

/**
 * One cycle of the chosen scenario. Everything it opens, it closes.
 */
$cycle = static function (int $iteration) use ($connection, $scenario, $keyPrefix): void {
    switch ($scenario) {
        // Ordinary commands on the shared pool, run concurrently: the path every
        // request handler takes.
        case 'command':
            $waitGroup = WaitGroup::create();

            for ($index = 0; $index < 20; ++$index) {
                $waitGroup->add(
                    callback: static function () use ($connection, $keyPrefix, $index): void {
                        $connection->set("$keyPrefix:$index", str_repeat('v', 512), ttlSeconds: 60);
                        $connection->get("$keyPrefix:$index");
                        $connection->del("$keyPrefix:$index");
                    },
                );
            }

            $waitGroup->waitAll();

            break;

        // Pipelines and transactions: one crossing, many commands, replies decoded into
        // a list every cycle throws away.
        case 'pipeline':
            $connection->transaction(
                static function (Pipeline $transaction) use ($keyPrefix): void {
                    for ($index = 0; $index < 50; ++$index) {
                        $transaction->command('SET', ["$keyPrefix:tx:$index", (string) $index]);
                    }
                },
            );

            $connection->pipeline()
                ->command('DEL', ["$keyPrefix:tx:0"])
                ->command('GET', ["$keyPrefix:tx:1"])
                ->execute();

            break;

        // Blocking commands, each on a connection of its own: the scenario that leaks
        // sockets if a dedicated connection is not released when the command ends.
        case 'blocking':
            $waitGroup = WaitGroup::create();

            for ($index = 0; $index < 5; ++$index) {
                $waitGroup->add(
                    callback: static function () use ($connection, $keyPrefix, $index): void {
                        // Half of them find a value waiting, half time out. Both endings
                        // have to give the connection back.
                        if ($index % 2 === 0) {
                            $connection->rPush("$keyPrefix:queue:$index", 'value');
                        }

                        $connection->blPop(["$keyPrefix:queue:$index"], timeoutSeconds: 0.2);
                    },
                );
            }

            $waitGroup->waitAll();

            break;

        // Cursors, walked to the end and abandoned half way. The abandoned one is the
        // interesting half: nothing calls next() again, so only the flow ending closes it.
        case 'cursor':
            $pipeline = $connection->pipeline();

            for ($index = 0; $index < 200; ++$index) {
                $pipeline->command('SET', ["$keyPrefix:key:$index", '1']);
            }

            $pipeline->execute();

            // Inside a WaitGroup, like every other scenario here. Run synchronously the
            // abandoned cursor is released by ScanResult's destructor, which is the path
            // that already works; the one worth watching is the coroutine's, where that
            // release is a no-op and only the flow ending closes the cursor and gives its
            // pooled connection back.
            $waitGroup = WaitGroup::create();

            $waitGroup->add(
                callback: static function () use ($connection, $keyPrefix): void {
                    foreach ($connection->scan(match: "$keyPrefix:key:*", count: 20, batchSize: 10) as $ignored) {
                        // Walked to the end.
                    }

                    foreach ($connection->scan(match: "$keyPrefix:key:*", count: 20, batchSize: 10) as $ignored) {
                        break;
                    }
                },
            );

            $waitGroup->waitAll();

            $connection->command('DEL', ["$keyPrefix:key:0"]);

            break;

        // Subscriptions: opened, published to, read, closed — and every fourth one
        // abandoned without a close, so the flow hook is what has to release it.
        case 'subscribe':
            $waitGroup = WaitGroup::create();

            $waitGroup->add(
                callback: static function () use ($connection, $keyPrefix, $iteration): void {
                    $channel = "$keyPrefix:channel";

                    $subscription = $connection->subscribe(channels: [$channel]);

                    Scheduler::get()->spawn(
                        callback: static function () use ($connection, $channel): void {
                            $connection->command('PUBLISH', [$channel, 'payload']);
                        },
                    );

                    $subscription->read();

                    if ($iteration % 4 !== 0) {
                        $subscription->close();
                    }
                },
            );

            $waitGroup->waitAll();

            break;

        default:
            throw new RuntimeException("unknown scenario $scenario");
    }
};

echo "redis soak: scenario=$scenario, seconds=$durationSeconds\n";
echo str_repeat('-', 80) . "\n";

$startTime    = microtime(true);
$lastReport   = $startTime;
$iteration    = 0;
$baselineHeap = 0;

while ((microtime(true) - $startTime) < $durationSeconds) {
    $cycle($iteration);

    ++$iteration;

    // The heap after a hundred cycles is the baseline: everything before that is the
    // pools, the fibers and the interned strings settling.
    if ($iteration === 100) {
        $baselineHeap = memory_get_usage(true);
    }

    if ((microtime(true) - $lastReport) < 5.0) {
        continue;
    }

    $lastReport = microtime(true);

    $heapBytes  = memory_get_usage(true);
    $growth     = $baselineHeap === 0 ? 0 : $heapBytes - $baselineHeap;
    $tasks      = Extension::get()->count();
    $clients    = TestRedisResolver::countServerConnections();
    $elapsed    = (int) (microtime(true) - $startTime);

    printf(
        "%4ds  cycles %-8d heap %6.1f MB  growth %+7.1f MB  tasks %-4d server clients %d\n",
        $elapsed,
        $iteration,
        $heapBytes / 1024 / 1024,
        $growth / 1024 / 1024,
        $tasks,
        $clients,
    );
}

echo str_repeat('-', 80) . "\n";
echo "done: $iteration cycles\n";
