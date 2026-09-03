<?php

declare(strict_types=1);

/**
 * What a member of a NESTED fan-out costs: a WaitGroup created inside a
 * coroutine, whose members therefore push from a coroutine's own stack.
 *
 * The only shape whose pushes are issued from a coroutine's own stack. Every
 * other runtime bench here is flat — coordination-profile.php builds its groups
 * from the main stack on purpose, and fan-out.php is one wide group — so that
 * path was measured by nothing at all until this existed. That is what made
 * item 1 of .ai/plans/rust-core-hot-path.md an estimate rather than a number,
 * and it is what the deferred-dispatch queue was removed on the strength of.
 *
 * Interleaved-round discipline is not needed for one case, but the warm-up round
 * and the median are: see coordination-profile.php for why a single timed run of
 * anything here is not worth reading.
 *
 * Run: make bench-nested-fan-out
 */

use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Impl\TestApplication;
use SConcur\WaitGroup;

use function SConcur\Extension\tasksCount;
use function SConcur\Extension\waitAny as rawWaitAny;

error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

require_once __DIR__ . '/../../../vendor/autoload.php';

TestApplication::init();

const OUTER  = 50;
const INNER  = 20;
const ROUNDS = 9;

$members = OUTER * INNER;

$drain = static function (): void {
    while (tasksCount() > 0) {
        rawWaitAny();
    }

    gc_collect_cycles();
};

$nestedFanOut = static function (): void {
    $outer = WaitGroup::create();

    for ($member = 0; $member < OUTER; $member++) {
        $outer->add(static function (): int {
            $inner = WaitGroup::create();

            for ($nested = 0; $nested < INNER; $nested++) {
                $inner->add(static function (): int {
                    Sleeper::usleep(microseconds: 1);

                    return 1;
                });
            }

            $inner->waitAll();

            return 1;
        });
    }

    $outer->waitAll();
};

$cpus  = [];
$walls = [];

for ($round = 0; $round < ROUNDS + 1; $round++) {
    $drain();

    $usageBefore = getrusage();
    $start       = hrtime(true);

    $nestedFanOut();

    $wall       = (hrtime(true) - $start) / 1000 / $members;
    $usageAfter = getrusage();

    if ($round === 0) {
        continue; // warm-up
    }

    $cpuMicroseconds = ($usageAfter['ru_utime.tv_sec'] - $usageBefore['ru_utime.tv_sec']) * 1_000_000
        + ($usageAfter['ru_utime.tv_usec'] - $usageBefore['ru_utime.tv_usec'])
        + ($usageAfter['ru_stime.tv_sec'] - $usageBefore['ru_stime.tv_sec']) * 1_000_000
        + ($usageAfter['ru_stime.tv_usec'] - $usageBefore['ru_stime.tv_usec']);

    $cpus[]  = $cpuMicroseconds / $members;
    $walls[] = $wall;
}

sort($cpus);
sort($walls);

$middle = intdiv(ROUNDS, 2);

/**
 * How far the rounds spread around the median they agree on. Printed because
 * this bench is noisy enough to mislead without it: on a host with unrelated
 * work on it the rounds straddle two clusters ~3 us apart, and a median read
 * alone invites treating a difference of that size as a result. Anything smaller
 * than this column is not a finding.
 *
 * @param list<float> $values
 */
$spread = static function (array $values) use ($middle): float {
    return max(
        abs($values[count($values) - 1] - $values[$middle]),
        abs($values[$middle] - $values[0]),
    );
};

printf(
    "%snested fan-out — %d outer x %d inner, median of %d rounds%s%s",
    PHP_EOL,
    OUTER,
    INNER,
    ROUNDS,
    PHP_EOL,
    PHP_EOL,
);

printf("  cpu per inner member   %8.3f us  +/- %.3f%s", $cpus[$middle], $spread($cpus), PHP_EOL);
printf("  wall per inner member  %8.3f us  +/- %.3f%s", $walls[$middle], $spread($walls), PHP_EOL);

echo PHP_EOL;
