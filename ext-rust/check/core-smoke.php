<?php

declare(strict_types=1);

/**
 * Smoke check for the Rust core spike: the parts of the core the ladder leans
 * on, exercised through the unmodified PHP package.
 *
 * Run inside the `php` container against the spike build:
 *   php -d extension=/sconcur/ext-rust/build/sconcur.so ext-rust/check/core-smoke.php
 *
 * Covers the shared results channel (a fan-out resolves in parallel, not in
 * sum), the per-coroutine error path, flow teardown (tasksCount returns to
 * zero) and the sync wait path.
 */

use SConcur\Features\Sleeper\Sleeper;
use SConcur\WaitGroup;

require __DIR__ . '/../../vendor/autoload.php';

$failures = 0;

function check(string $name, bool $passed, string $detail = ''): void
{
    global $failures;

    if (!$passed) {
        ++$failures;
    }

    printf("%-42s %s%s%s", $name, $passed ? 'OK' : 'FAIL', $detail === '' ? '' : "  ($detail)", PHP_EOL);
}

// 1. The sync path: one call, outside any coroutine.
$start = microtime(true);

Sleeper::usleep(microseconds: 50_000);

$syncMs = (microtime(true) - $start) * 1000;

check('sync sleep ~50ms', $syncMs >= 45 && $syncMs < 200, sprintf('%.1f ms', $syncMs));

// 2. The shared channel: 20 coroutines each sleeping 100ms finish in about one
//    sleep, not in twenty.
$start     = microtime(true);
$waitGroup = WaitGroup::create();

for ($index = 0; $index < 20; ++$index) {
    $waitGroup->add(static function () use ($index): int {
        Sleeper::usleep(microseconds: 100_000);

        return $index;
    });
}

$collected = [];

foreach ($waitGroup->iterate() as $value) {
    $collected[] = $value;
}

$fanOutMs = (microtime(true) - $start) * 1000;

sort($collected);

check('fan-out of 20 returns every result', $collected === range(0, 19));
check('fan-out runs concurrently', $fanOutMs < 600, sprintf('%.1f ms', $fanOutMs));

// 3. A throwing coroutine surfaces on the iterating side as that exception.
$waitGroup = WaitGroup::create();

$waitGroup->add(static function (): int {
    Sleeper::usleep(microseconds: 10_000);

    throw new RuntimeException('boom');
});

$caught = null;

try {
    foreach ($waitGroup->iterate() as $value) {
        // The group has one member and it throws; nothing is ever yielded.
    }
} catch (Throwable $exception) {
    $caught = $exception;
}

check(
    'a throwing coroutine surfaces its error',
    $caught !== null && str_contains($caught->getMessage() . ($caught->getPrevious()?->getMessage() ?? ''), 'boom'),
    $caught === null ? 'nothing thrown' : $caught::class,
);

// 4. Flow teardown: nothing is left registered on the core side.
check('tasksCount back to zero', SConcur\Extension\tasksCount() === 0, (string) SConcur\Extension\tasksCount());

printf('%s%s%s', PHP_EOL, $failures === 0 ? 'all checks passed' : $failures . ' check(s) failed', PHP_EOL);

exit($failures === 0 ? 0 : 1);
