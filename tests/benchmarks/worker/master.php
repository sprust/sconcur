<?php

/**
 * The master of the memory benchmark's forked arms: a WorkerMaster with one group of
 * app-worker.php workers on a loopback port. With --preload it loads the stand-in
 * application before run(), the way an application boots before it builds its master, so
 * every worker is a fork that begins with it loaded; without it each worker loads it after
 * the fork.
 *
 * Usage: php -d extension=sconcur.so tests/benchmarks/worker/master.php <runtimeDir> <port> <workers> [--preload]
 */

declare(strict_types=1);

use SConcur\Worker\AccessLogTarget;
use SConcur\Worker\WorkerContext;
use SConcur\Worker\WorkerGroupConfig;
use SConcur\Worker\WorkerMaster;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $runtimeDir, $port, $workers] = $argv;

if (in_array('--preload', $argv, true)) {
    require_once __DIR__ . '/app.php';
}

$master = new WorkerMaster(
    runtimeDir: $runtimeDir,
    groups: [
        new WorkerGroupConfig(
            name: 'app',
            role: static function (WorkerContext $context) use ($port): void {
                $argv = [
                    __DIR__ . '/app-worker.php',
                    '--address=127.0.0.1:' . $port,
                    '--reusePort=1',
                    '--masterPid=' . $context->masterPid,
                ];

                require __DIR__ . '/app-worker.php';
            },
            workerCount: (int) $workers,
            // A long run sends hundreds of thousands of requests; the journal is not what is
            // measured.
            accessLog: AccessLogTarget::Off,
        ),
    ],
    logDir: $runtimeDir,
    name: 'memory',
);

exit($master->run());
