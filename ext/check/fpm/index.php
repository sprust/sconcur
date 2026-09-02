<?php

declare(strict_types=1);

/**
 * The page the fork/FPM check requests. One request = one fan-out of twelve
 * coroutines, each sleeping 100ms: it passes only if they really ran at the
 * same time, so a worker that silently fell back to running them one after
 * another fails instead of looking fine.
 *
 * Served by a php-fpm worker, which is a process forked from the master AFTER
 * the extension was loaded at MINIT — the case the Go build cannot support.
 */

use SConcur\Features\Sleeper\Sleeper;
use SConcur\WaitGroup;

require '/sconcur/vendor/autoload.php';

header('Content-Type: text/plain');

$start     = microtime(true);
$waitGroup = WaitGroup::create();

for ($index = 0; $index < 12; ++$index) {
    $waitGroup->add(static function () use ($index): int {
        Sleeper::usleep(microseconds: 100_000);

        return $index;
    });
}

$collected = [];

foreach ($waitGroup->iterate() as $value) {
    $collected[] = $value;
}

$elapsedMs = (microtime(true) - $start) * 1000;

sort($collected);

$correct    = $collected === range(0, 11);
$concurrent = $elapsedMs < 600;

printf(
    "%s pid=%d elapsed=%.1fms results=%d concurrent=%s%s",
    $correct && $concurrent ? 'OK' : 'FAIL',
    getmypid(),
    $elapsedMs,
    count($collected),
    $concurrent ? 'yes' : 'no',
    PHP_EOL,
);
