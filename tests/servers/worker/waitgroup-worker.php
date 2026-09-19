<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use SConcur\Features\Sleeper\Sleeper;
use SConcur\WaitGroup;

/**
 * A supervised worker that runs no server: it drives the scheduler through an ordinary
 * WaitGroup, the way a pool of periodic tasks does. It exists to prove that such a worker
 * marks itself alive for the master's watchdog like a server does, and is killed like one
 * when its PHP thread stops coming back.
 *
 * Arguments (everything else in argv is ignored — the master forwards its `server` block
 * to every worker it supervises, and none of it means anything here):
 *   --hangAfterMs=N  freeze the PHP thread in a native call N ms after start (0 = never)
 */
$hangAfterMs = 0;

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--hangAfterMs=')) {
        $hangAfterMs = (int) substr($argument, strlen('--hangAfterMs='));
    }
}

$stopRequested = false;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stopRequested): void {
        $stopRequested = true;
    });
    pcntl_signal(SIGINT, static function () use (&$stopRequested): void {
        $stopRequested = true;
    });
}

$startedAt = microtime(true);

echo 'waitgroup worker started pid=' . getmypid() . PHP_EOL;

while (!$stopRequested) {
    $waitGroup = WaitGroup::create();

    $waitGroup->add(static function (): int {
        Sleeper::usleep(microseconds: 50_000);

        return 1;
    });

    $waitGroup->waitResults();

    if ($hangAfterMs > 0 && (microtime(true) - $startedAt) * 1000 >= $hangAfterMs) {
        echo 'waitgroup worker freezing' . PHP_EOL;

        // A native call: nothing preempts it, and the scheduler is not driven again until
        // it returns, which is exactly what the watchdog is meant to notice.
        usleep(20_000_000);
    }
}

echo 'waitgroup worker stopped' . PHP_EOL;
