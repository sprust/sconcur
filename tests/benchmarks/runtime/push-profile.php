<?php

declare(strict_types=1);

/**
 * What it costs to push one task across the boundary — the half
 * boundary-profile.php leaves out, and the one the attribution round left open.
 *
 * It matters because the two ladders do not reconcile without it: a
 * scheduler trip that pushes a task, has it executed and takes the result back
 * prices the same as one that does none of that. Either the push is nearly free
 * or something in the accounting is wrong, and only a number tells which.
 *
 * The measurement has a trap the plan names in advance. A loop that pushes and
 * measures is the seeding mistake of 521bb3c: the extension executes what it is
 * given while the loop is still running, and getrusage counts its threads
 * because they are threads of this process — so the CPU of *executing* the tasks
 * lands inside the window that was meant to price *submitting* them. The way
 * around it is to push work that cannot finish inside the window: a sleeper
 * holding for seconds, so the runtime arms a timer and goes quiet.
 *
 * Run: php -d extension=./ext/build/sconcur.so tests/benchmarks/runtime/push-profile.php
 */

use SConcur\Connection\Extension;
use SConcur\Features\Sleeper\Payloads\SleeperPayload;
use SConcur\Tests\Impl\TestApplication;
use SConcur\Transport\MessagePackTransport;

use function SConcur\Extension\push as rawPush;
use function SConcur\Extension\stopFlow as rawStopFlow;
use function SConcur\Extension\tasksCount;
use function SConcur\Extension\waitAny as rawWaitAny;

error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

require_once __DIR__ . '/../../../vendor/autoload.php';

TestApplication::init();

/**
 * Under the extension's result buffer (1024): a cancelled task still produces a
 * result, and the drain has to be able to take every one of them.
 */
const PUSHES = 500;

/**
 * Long enough that nothing pushed inside a window can finish inside it. A window
 * is a few milliseconds; this is three orders of magnitude more.
 */
const HOLD_SECONDS = 5;

const REPEATS = 7;

$extension = Extension::get();
$payload   = new SleeperPayload(microseconds: HOLD_SECONDS * 1_000_000);
$packed    = MessagePackTransport::pack($payload);
$method    = $payload->getMethod()->value;

/**
 * Cancels whatever the case pushed and takes the results the cancellation
 * produces, so the next repeat starts from an empty buffer and an idle runtime.
 */
$drain = static function (string $flowKey): void {
    rawStopFlow($flowKey);

    $deadline = microtime(true) + 10.0;

    while (tasksCount() > 0) {
        rawWaitAny();

        if (microtime(true) > $deadline) {
            throw new RuntimeException('drain did not settle: ' . tasksCount() . ' results left');
        }
    }

    gc_collect_cycles();
};

/**
 * @param callable(string): void $case
 *
 * @return array{wall: float, cpu: float} microseconds per push, median of REPEATS
 */
$measure = static function (callable $case, bool $pushes = true) use ($drain): array {
    $walls = [];
    $cpus  = [];

    for ($repeat = 0; $repeat < REPEATS; $repeat++) {
        $flowKey = 'push-profile-' . uniqid();

        $usageBefore = getrusage();
        $start       = hrtime(true);

        $case($flowKey);

        $wall       = (hrtime(true) - $start) / 1000 / PUSHES;
        $usageAfter = getrusage();

        $cpuMicroseconds = ($usageAfter['ru_utime.tv_sec'] - $usageBefore['ru_utime.tv_sec']) * 1_000_000
            + ($usageAfter['ru_utime.tv_usec'] - $usageBefore['ru_utime.tv_usec'])
            + ($usageAfter['ru_stime.tv_sec'] - $usageBefore['ru_stime.tv_sec']) * 1_000_000
            + ($usageAfter['ru_stime.tv_usec'] - $usageBefore['ru_stime.tv_usec']);

        $walls[] = $wall;
        $cpus[]  = $cpuMicroseconds / PUSHES;

        if ($pushes) {
            $drain($flowKey);
        }
    }

    sort($walls);
    sort($cpus);

    $middle = intdiv(REPEATS, 2);

    return ['wall' => $walls[$middle], 'cpu' => $cpus[$middle]];
};

$report = [];

// 1. The floor every crossing pays, measured here rather than borrowed from
//    boundary-profile.php so both halves are priced in one run on one machine.
$report['boundary crossing (tasksCount)'] = $measure(
    case: static function (): void {
        for ($index = 0; $index < PUSHES; $index++) {
            tasksCount();
        }
    },
    pushes: false,
);

// 2. Packing the payload, which is PHP-side work the crossing never sees.
$report['MessagePackTransport::pack'] = $measure(
    case: static function () use ($payload): void {
        for ($index = 0; $index < PUSHES; $index++) {
            MessagePackTransport::pack($payload);
        }
    },
    pushes: false,
);

// 3. The extension function itself, with the payload packed in advance: the
//    crossing plus the runtime taking the task and arming it.
$report['raw push (crossing + runtime)'] = $measure(
    case: static function (string $flowKey) use ($method, $packed): void {
        for ($index = 0; $index < PUSHES; $index++) {
            rawPush($flowKey, $method, "$flowKey:$index", $packed, 0);
        }
    },
);

// 4. The whole call as the scheduler makes it: the task key, the packing, the
//    crossing, the response check and the DTO.
$report['Extension::push (full path)'] = $measure(
    case: static function (string $flowKey) use ($extension, $payload): void {
        for ($index = 0; $index < PUSHES; $index++) {
            $extension->push($flowKey, $payload);
        }
    },
);

$eol = PHP_EOL;

echo $eol . 'push profile — ' . PUSHES . ' pushes per repeat, median of ' . REPEATS . $eol;
echo '  sleeper held for ' . HOLD_SECONDS . 's, so no task finishes inside a window' . $eol . $eol;

$nameColumn = max(array_map(strlen(...), array_keys($report)));

printf("  %-{$nameColumn}s  %12s  %12s%s", '', 'wall, us', 'cpu, us', $eol);

foreach ($report as $name => $timing) {
    printf("  %-{$nameColumn}s  %12.3f  %12.3f%s", $name, $timing['wall'], $timing['cpu'], $eol);
}

// The derived rows. Subtraction is honest here in a way it was not in
// boundary-profile.php's isolated rows: every row above ran with the extension
// in the same state — idle, holding timers — so none of them is priced in a
// state the whole never has.
$crossing = $report['boundary crossing (tasksCount)']['cpu'];
$packing  = $report['MessagePackTransport::pack']['cpu'];
$raw      = $report['raw push (crossing + runtime)']['cpu'];
$full     = $report['Extension::push (full path)']['cpu'];

echo $eol . '  what that splits into, on cpu' . $eol . $eol;

printf("  %-{$nameColumn}s  %12.3f%s", 'crossing floor', $crossing, $eol);
printf("  %-{$nameColumn}s  %12.3f%s", 'runtime accepting the task', $raw - $crossing, $eol);
printf("  %-{$nameColumn}s  %12.3f%s", 'packing the payload', $packing, $eol);
printf("  %-{$nameColumn}s  %12.3f%s", 'PHP wrapper (key, check, DTO)', $full - $raw - $packing, $eol);
printf("  %-{$nameColumn}s  %12.3f%s", 'one Extension::push', $full, $eol);
