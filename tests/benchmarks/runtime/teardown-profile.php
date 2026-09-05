<?php

declare(strict_types=1);

/**
 * What tearing a coroutine down costs, and whether it costs more when more
 * coroutines are alive.
 *
 * The sampling profile of the HTTP server puts the teardown path — finishPooled
 * -> forget -> State::deleteFlow / unRegisterFiber — at about 17% of PHP-side
 * CPU, second only to the push across the boundary. Two of its steps are
 * shaped like scans: `State::deleteFlow` falls back to walking every registered
 * fiber when the flow has no fiber list of its own, and
 * `Scheduler::purgeSwitchedCoroutine` runs an `array_search` over the switch
 * queue. A scan costs what the process is holding at the time, so the question
 * is not what one teardown costs in an empty process — it is whether the cost
 * grows with the number of live coroutines, which is what a server has.
 *
 * The instrument is therefore a slope, like coordination-profile.php: the same
 * group of short-lived coroutines is run against a growing crowd of parked
 * neighbours, and what matters is whether the per-member cost climbs with the
 * crowd. A flat line means the teardown is O(1) and its 17% is real work; a
 * rising one means part of that 17% is the crowd being walked.
 *
 * The neighbours park on a sleeper long enough to outlive the whole run, so
 * they are live coroutines with live flows rather than a queue of finished
 * ones — that is the state a loaded server is in.
 *
 * Read the CPU column: a parked neighbour's timer runs on the extension's
 * runtime thread and shows in wall while costing almost no CPU here.
 *
 * Run: make bench-teardown
 */

use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Impl\TestApplication;
use SConcur\WaitGroup;

use function SConcur\Extension\tasksCount;
use function SConcur\Extension\waitAny as rawWaitAny;

error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

require_once __DIR__ . '/../../../vendor/autoload.php';

TestApplication::init();

/** Members per measured group: enough that one member's cost is not a rounding error. */
const MEMBERS = 300;

/** How many parked coroutines are alive while the group runs. */
const CROWDS = [0, 32, 128, 512];

const ROUNDS        = 5;
const WARMUP_ROUNDS = 1;

/** Long enough that no neighbour wakes up inside a measured round. */
const PARK_MICROSECONDS = 30_000_000;

/** What a measured member awaits: short enough to be dominated by the coordination around it. */
const AWAIT_MICROSECONDS = 200;

/**
 * One group of short-lived members, each doing one real feature call, so every
 * member goes through the scheduler's completion path rather than finishing
 * inside WaitGroup::launch.
 *
 * @return array{wall: float, cpu: float} microseconds per member
 */
function runGroup(int $members): array
{
    $group = WaitGroup::create();

    $startedWall = hrtime(true);
    $startedCpu  = getrusage();

    for ($i = 0; $i < $members; $i++) {
        $group->add(static function (): void {
            Sleeper::usleep(microseconds: AWAIT_MICROSECONDS);
        });
    }

    foreach ($group->iterate() as $_) {
        // Draining the results is part of the cycle being priced.
    }

    $wall = (hrtime(true) - $startedWall) / 1_000;
    $cpu  = cpuMicroseconds(getrusage()) - cpuMicroseconds($startedCpu);

    return [
        'wall' => $wall / $members,
        'cpu'  => $cpu / $members,
    ];
}

/**
 * @param array<string, int> $usage
 */
function cpuMicroseconds(array $usage): float
{
    return $usage['ru_utime.tv_sec'] * 1_000_000.0
        + $usage['ru_utime.tv_usec']
        + $usage['ru_stime.tv_sec'] * 1_000_000.0
        + $usage['ru_stime.tv_usec'];
}

/**
 * Parks $count coroutines for the rest of the run. Spawned rather than grouped,
 * because a spawned coroutine owns a flow of its own — the shape a server's
 * per-request coroutine has, and the one whose teardown walks a registry.
 */
function park(int $count): void
{
    $scheduler = Scheduler::get();

    for ($i = 0; $i < $count; $i++) {
        $scheduler->spawn(static function (): void {
            Sleeper::usleep(microseconds: PARK_MICROSECONDS);
        });
    }
}

/**
 * @param list<float> $values
 */
function median(array $values): float
{
    sort($values);

    $middle = intdiv(count($values), 2);

    return count($values) % 2 === 1
        ? $values[$middle]
        : ($values[$middle - 1] + $values[$middle]) / 2;
}

$parked = 0;

/** @var array<int, array{wall: list<float>, cpu: list<float>}> $rounds */
$rounds = [];

foreach (CROWDS as $crowd) {
    $rounds[$crowd] = ['wall' => [], 'cpu' => []];
}

for ($round = 0; $round < ROUNDS + WARMUP_ROUNDS; $round++) {
    foreach (CROWDS as $crowd) {
        // The crowd only ever grows within a pass, and the passes are not
        // interleaved across crowds for that reason: a parked coroutine cannot
        // be un-parked, so the rounds repeat the ladder instead.
        if ($crowd > $parked) {
            park($crowd - $parked);

            $parked = $crowd;
        }

        $measured = runGroup(MEMBERS);

        if ($round < WARMUP_ROUNDS) {
            continue;
        }

        $rounds[$crowd]['wall'][] = $measured['wall'];
        $rounds[$crowd]['cpu'][]  = $measured['cpu'];
    }
}

printf(
    "%d members per group, %d rounds (median), neighbours parked for %ds\n\n",
    MEMBERS,
    ROUNDS,
    (int) (PARK_MICROSECONDS / 1_000_000),
);
printf("%-24s %12s %12s\n", 'live neighbours', 'wall, us', 'cpu, us');
printf("%-24s %12s %12s\n", str_repeat('-', 24), str_repeat('-', 12), str_repeat('-', 12));

$baseline = null;

foreach (CROWDS as $crowd) {
    $cpu = median($rounds[$crowd]['cpu']);

    $baseline ??= $cpu;

    printf(
        "%-24d %12.3f %12.3f  %+6.1f%%\n",
        $crowd,
        median($rounds[$crowd]['wall']),
        $cpu,
        $baseline === 0.0 ? 0.0 : ($cpu - $baseline) / $baseline * 100,
    );
}

// The parked neighbours are still holding their sleepers; the process leaves
// them rather than waiting half a minute for timers nothing reads.
printf("\n  tasks still in flight: %d\n", tasksCount());
