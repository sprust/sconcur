<?php

declare(strict_types=1);

/**
 * Attribution of what one result taken through waitAny costs, on the PHP side of
 * the boundary.
 *
 * It splits the per-result price into: a bare boundary crossing, the frame the
 * extension builds plus its copies, the PHP frame parsing into a TaskResultDto,
 * and — separately —
 * the scheduler's coroutine coordination (suspend -> waitAny -> resume).
 *
 * Both wall and CPU time are reported: the sleeper's own timer shows up in
 * wall but burns almost no CPU, so the coordination rows must be read on the CPU
 * column (the same choice the 2026-07-02 profile made).
 *
 * Run: php -d extension=./ext/build/sconcur.so tests/benchmarks/runtime/boundary-profile.php
 */

use RuntimeException;
use SConcur\Connection\Extension;
use SConcur\Features\Sleeper\Payloads\SleeperPayload;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Impl\TestApplication;
use SConcur\WaitGroup;

use function SConcur\Extension\tasksCount;
use function SConcur\Extension\waitAny as rawWaitAny;

error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

require_once __DIR__ . '/../../../vendor/autoload.php';

TestApplication::init();

const RESULTS    = 1000;   // must stay under the extension's result buffer (1024)
const COROUTINES = 500;
const REPEATS    = 7;      // measurements per case, the median is reported

$extension = Extension::get();

/**
 * Takes whatever results a case left behind, so the next case starts from an
 * empty buffer. Without it a leftover makes the next waitAny return instantly
 * (or, the other way round, park waiting for a result that is not coming) and
 * the numbers swing by an order of magnitude between runs.
 */
$drain = static function (): void {
    while (tasksCount() > 0) {
        rawWaitAny();
    }

    gc_collect_cycles();
};

/**
 * Seeds RESULTS finished results into the extension's buffer: pushes sleeper
 * tasks with the smallest allowed delay and waits until every one of them has
 * produced its result, so the measured loop only pays for taking them across the
 * boundary.
 *
 * The wait is on the count, not on a clock. A fixed sleep was what made every row
 * here untrustworthy: results still being produced when the case started put the
 * CPU of producing them inside the measured window, and getrusage counts the
 * extension's threads because they are threads of this process. That is how the
 * same work came out as 1.5 us in one run and 9.9 us in the next, and how the
 * isolated rows stopped summing to the composed one.
 */
$seedResults = static function () use ($extension): void {
    $flowKey = 'profile-' . uniqid();
    $payload = new SleeperPayload(microseconds: 1);

    for ($i = 0; $i < RESULTS; $i++) {
        $extension->push($flowKey, $payload);
    }

    $deadline = microtime(true) + 10.0;

    while (tasksCount() < RESULTS) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException(
                'seeding did not settle: ' . tasksCount() . ' of ' . RESULTS . ' results ready',
            );
        }

        usleep(1000);
    }
};

/**
 * @param callable(): void $case
 *
 * @return array{wall: float, cpu: float} microseconds per operation, median of REPEATS
 */
$measure = static function (callable $case, int $operations, ?callable $setUp = null) use ($drain): array {
    $walls = [];
    $cpus  = [];

    for ($repeat = 0; $repeat < REPEATS; $repeat++) {
        if ($setUp !== null) {
            $setUp();
        }

        $usageBefore = getrusage();
        $start       = hrtime(true);

        $case();

        $wall       = (hrtime(true) - $start) / 1000 / $operations;
        $usageAfter = getrusage();

        $cpuMicroseconds = ($usageAfter['ru_utime.tv_sec'] - $usageBefore['ru_utime.tv_sec']) * 1_000_000
            + ($usageAfter['ru_utime.tv_usec'] - $usageBefore['ru_utime.tv_usec'])
            + ($usageAfter['ru_stime.tv_sec'] - $usageBefore['ru_stime.tv_sec']) * 1_000_000
            + ($usageAfter['ru_stime.tv_usec'] - $usageBefore['ru_stime.tv_usec']);

        $walls[] = $wall;
        $cpus[]  = $cpuMicroseconds / $operations;

        $drain();
    }

    sort($walls);
    sort($cpus);

    $middle = intdiv(REPEATS, 2);

    return ['wall' => $walls[$middle], 'cpu' => $cpus[$middle]];
};

$report = [];

// 1. Bare boundary crossing — the floor every call pays.
$report['boundary crossing (tasksCount)'] = $measure(
    case: static function (): void {
        for ($i = 0; $i < RESULTS; $i++) {
            tasksCount();
        }
    },
    operations: RESULTS,
);

// 2. Raw waitAny: the crossing, the frame the extension builds and the copies it
//    takes, without any PHP-side parsing.
$report['raw waitAny (crossing + frame + copies)'] = $measure(
    case: static function (): void {
        for ($i = 0; $i < RESULTS; $i++) {
            rawWaitAny();
        }
    },
    operations: RESULTS,
    setUp: $seedResults,
);

// 3. Full single-result path: the same plus parseWaitResponse + TaskResultDto.
$report['waitAny (full path -> TaskResultDto)'] = $measure(
    case: static function () use ($extension): void {
        for ($i = 0; $i < RESULTS; $i++) {
            $extension->waitAny();
        }
    },
    operations: RESULTS,
    setUp: $seedResults,
);

// 4. Batched path: one crossing carries up to 64 results.
$report['waitAnyBatch(64) (per result)'] = $measure(
    case: static function () use ($extension): void {
        $taken = 0;

        while ($taken < RESULTS) {
            $taken += count($extension->waitAnyBatch(64));
        }
    },
    operations: RESULTS,
    setUp: $seedResults,
);

// 5. The two halves in one loop, on fresh frames, which is the only row that
//    describes the real path. The isolated rows below do NOT sum to it, and the
//    difference is not a missing component: parsing one frame over and over with
//    the extension idle costs ~2 us, while parsing the frames of a run that is
//    actually taking results costs ~9 us. The extra is allocator and refcount
//    work on the objects each result becomes, which only exists when results are
//    really flowing.
//
//    Reading the isolated rows as an attribution is what produced the "~6 us
//    unattributed" of an earlier round. There was nothing unattributed; the
//    parts were measured in a state the whole never has.
$parseFrame = Closure::bind(
    static fn (string $response): object => Extension::parseWaitResponse($response, 'profile', microtime(true)),
    null,
    Extension::class,
);

$report['raw waitAny + parse (composed, fresh frames)'] = $measure(
    case: static function () use ($parseFrame): void {
        for ($i = 0; $i < RESULTS; $i++) {
            $parseFrame(rawWaitAny());
        }
    },
    operations: RESULTS,
    setUp: $seedResults,
);

// 6. PHP-side frame parsing in isolation, on one captured frame.
$seedResults();
$frame = rawWaitAny();

while (tasksCount() > 0) {
    rawWaitAny();
}

$report['parseWaitResponse (PHP only)'] = $measure(
    case: static function () use ($parseFrame, $frame): void {
        for ($i = 0; $i < RESULTS; $i++) {
            $parseFrame($frame);
        }
    },
    operations: RESULTS,
);

// 7. Scheduler coordination: a fan-out of coroutines that never suspend versus
//    one where every coroutine suspends on the cheapest possible feature call.
//    Read the CPU column — the sleeper's timer inflates wall, not CPU.
//
//    The first row is NOT the fiber machinery, which is what it used to be
//    called here. coordination-profile.php prices a bare Fiber::suspend/resume
//    pair at 0.08 us; what this row measures is everything a group member costs
//    around it — the fiber's construction, the State and Scheduler registration,
//    the Coroutine object, and the teardown of all three.
$report['coroutine, no suspend (scheduler bookkeeping)'] = $measure(
    case: static function (): void {
        $waitGroup = WaitGroup::create();

        for ($i = 0; $i < COROUTINES; $i++) {
            $waitGroup->add(static fn (): int => 1);
        }

        $waitGroup->waitAll();
    },
    operations: COROUTINES,
);

$report['coroutine, suspends on usleep(1)'] = $measure(
    case: static function (): void {
        $waitGroup = WaitGroup::create();

        for ($i = 0; $i < COROUTINES; $i++) {
            $waitGroup->add(static function (): int {
                Sleeper::usleep(microseconds: 1);

                return 1;
            });
        }

        $waitGroup->waitAll();
    },
    operations: COROUTINES,
);

// 7. Synchronous path: push + wait + stopFlow per call (phase 2's target).
$report['sync feature call (push+wait+stopFlow)'] = $measure(
    case: static function (): void {
        for ($i = 0; $i < RESULTS; $i++) {
            Sleeper::usleep(microseconds: 1);
        }
    },
    operations: RESULTS,
);

echo PHP_EOL . 'boundary profile — microseconds per operation (median of ' . REPEATS . ')' . PHP_EOL . PHP_EOL;

$width = max(array_map(strlen(...), array_keys($report)));

printf("  %-{$width}s  %10s  %10s\n", '', 'wall, us', 'cpu, us');

foreach ($report as $name => $timing) {
    printf("  %-{$width}s  %10.3f  %10.3f\n", $name, $timing['wall'], $timing['cpu']);
}

echo PHP_EOL;
