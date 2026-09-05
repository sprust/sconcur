<?php

declare(strict_types=1);

/**
 * What a fan-out costs per member as it crosses the shared results buffer.
 *
 * A finished task publishes into a buffer of 1024 (handler::RESULTS_BUFFER_SIZE)
 * and waits for room past it. Everything below that width never waits, so the
 * two sides of the boundary are different code paths, and only one of them is
 * exercised by the ladder or by any server benchmark — a server serves tens of
 * requests at once, not thousands.
 *
 * That blind spot has already cost something once: the port's first backpressure
 * woke every blocked publisher on a 100 microsecond timer, so a wide fan-out
 * burned CPU proportional to how many members were waiting, and nothing in the
 * repository could see it. This is what sees it.
 *
 * Read the CPU column. The members sleep, so wall time is dominated by the sleep
 * and moves very little; what backpressure costs is CPU, and getrusage covers
 * the extension's own threads because they are threads of this process.
 *
 * Run: php -d extension=./ext/build/sconcur.so tests/benchmarks/runtime/fan-out.php
 */

use SConcur\Features\Sleeper\Sleeper;
use SConcur\WaitGroup;

error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

require_once __DIR__ . '/../../../vendor/autoload.php';

/** How long one member occupies a slot. Long enough that the buffer fills. */
const MEMBER_SLEEP_MICROSECONDS = 2000;

/** Median of this many rounds per width. */
const ROUNDS = 5;

/**
 * The widths: under the buffer, at it, and past it by several multiples. The
 * interesting number is how the per-member cost moves between the first row and
 * the last — a backpressure that scales with the number of waiters shows up as a
 * rising column, a flat one means the waiting is free.
 */
const WIDTHS = [500, 1024, 2000, 5000];

/**
 * Runs one round and answers the CPU seconds it took, measured over the whole
 * process so the extension's threads are included.
 *
 * @return array{cpu: float, wall: float, returned: int}
 */
function round_of(int $members): array
{
    $before = getrusage();
    $start  = microtime(true);

    $group = WaitGroup::create();

    for ($member = 0; $member < $members; $member++) {
        $group->add(static function (): int {
            Sleeper::usleep(microseconds: MEMBER_SLEEP_MICROSECONDS);

            return 1;
        });
    }

    $returned = count($group->waitResults());

    $wall  = microtime(true) - $start;
    $after = getrusage();

    $cpu = ($after['ru_utime.tv_sec'] - $before['ru_utime.tv_sec'])
        + ($after['ru_utime.tv_usec'] - $before['ru_utime.tv_usec']) / 1e6
        + ($after['ru_stime.tv_sec'] - $before['ru_stime.tv_sec'])
        + ($after['ru_stime.tv_usec'] - $before['ru_stime.tv_usec']) / 1e6;

    return ['cpu' => $cpu, 'wall' => $wall, 'returned' => $returned];
}

/**
 * @param list<float> $values
 */
function median(array $values): float
{
    sort($values);

    return $values[intdiv(count($values), 2)];
}

echo PHP_EOL . 'fan-out — per member, median of ' . ROUNDS . ' rounds' . PHP_EOL . PHP_EOL;
printf("  %10s  %10s  %10s  %12s\n", 'members', 'cpu, us', 'wall, ms', 'returned');

foreach (WIDTHS as $members) {
    $cpus     = [];
    $walls    = [];
    $returned = 0;

    for ($round = 0; $round < ROUNDS; $round++) {
        $result = round_of($members);

        $cpus[]   = $result['cpu'] * 1e6 / $members;
        $walls[]  = $result['wall'] * 1e3;
        $returned = $result['returned'];
    }

    // A width that loses members is a defect, not a slow path: the buffer
    // accounting dropped a result or a publisher was never woken.
    $lost = $returned === $members ? '' : ' <- LOST MEMBERS';

    printf("  %10d  %10.1f  %10.1f  %12d%s\n", $members, median($cpus), median($walls), $returned, $lost);
}

echo PHP_EOL;
