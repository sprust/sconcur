<?php

declare(strict_types=1);

/**
 * Item 4 of .ai/plans/rust-core-hot-path.md — attribution of the scheduler's
 * coordination cycle, the way boundary-profile.php attributed the boundary.
 *
 * The boundary costs 1.70 us per result. One `suspend -> push -> waitAny ->
 * resume` round trip costs several times that, and the difference is PHP: State
 * bookkeeping, two DTO allocations, the fiber context switch pair, the
 * dispatcher, the scheduler turn and the routing check.
 *
 * The instrument is a slope, not an isolated row. boundary-profile.php learned
 * the hard way that a component measured on its own is measured in a state the
 * whole never has — parsing one captured frame over and over costs a third of
 * parsing the frames of a run that is really taking results. So nothing here is
 * timed outside the live cycle. Every number is the marginal cost of adding one
 * more of something to a WaitGroup that is really running:
 *
 *   ladder    K awaits per coroutine, K = 0..16. The slope over K >= 1 is one
 *             whole cycle. K = 0 is deliberately left out of that fit: a member
 *             that never suspends terminates inside WaitGroup::launch and is
 *             filed straight into the group's ready list, so it never reaches
 *             the scheduler's completion path at all (completeCoroutine, forget,
 *             fillSlots, wakeGroupWaiters). The step from 0 to 1 therefore pays
 *             for entering the scheduler as well as for one cycle, and folding
 *             the two together is what makes one number out of two.
 *   switch    the same ladder built out of Scheduler::switch(0) instead of a
 *             feature call: the same park, the same scheduler turn, the same
 *             completion path, with no task pushed and none executed. It is not
 *             the feature ladder minus the extension — a queue of parked
 *             coroutines makes the turn poll with waitAnyTimeoutBatch(0) and
 *             resume exactly one, so it pays an empty crossing per cycle where
 *             the feature turn amortizes one fruitful crossing over up to 64
 *             results. The two are different trips; what the pair is good for is
 *             that they come out the same price.
 *   stages    K extra repetitions of one operation inside a coroutine that
 *             still performs its real await. The slope is that operation's
 *             price where it actually runs.
 *   width     the same single-await cycle at three fan-out widths, because
 *             waitAnyBatch(64) amortizes its crossing over whatever is ready.
 *
 * Every case is measured once per round and the rounds are interleaved, not run
 * case by case. Measured case by case, this profile put the one-await rung above
 * the two-await rung — the host drifts over the minutes a full pass takes, and a
 * block of repeats belonging to one case absorbs that drift as if it were the
 * case's own cost. Interleaved, the drift lands on every case alike and the
 * median across rounds survives it.
 *
 * Read the CPU column. The sleeper's own timer runs on the extension's runtime
 * thread and shows up in wall while burning almost no CPU; the wall column is
 * kept only to make an anomalous run recognizable.
 *
 * Run: make bench-coordination
 */

use SConcur\Dto\PendingPushDto;
use SConcur\Features\Sleeper\Payloads\SleeperPayload;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\State;
use SConcur\Tests\Impl\TestApplication;
use SConcur\WaitGroup;

use function SConcur\Extension\tasksCount;
use function SConcur\Extension\waitAny as rawWaitAny;

error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

require_once __DIR__ . '/../../../vendor/autoload.php';

TestApplication::init();

const COROUTINES    = 500;
const ROUNDS        = 9;             // interleaved rounds, the median is reported
const WARMUP_ROUNDS = 1;             // discarded: the first pass pays for every code path it opens
const AWAIT_RUNGS   = [0, 1, 2, 4, 8, 16];
const SWITCH_RUNGS  = [1, 2, 4, 8, 16];
const STAGE_RUNGS   = [0, 128, 512]; // extra repetitions of one stage per cycle
const WIDTHS        = [50, 200, 800];

/**
 * Takes whatever a case left behind, so the next one starts from an empty
 * buffer: a leftover makes the next wait return instantly, or park for a result
 * that is not coming, and the numbers swing by an order of magnitude.
 */
$drain = static function (): void {
    while (tasksCount() > 0) {
        rawWaitAny();
    }

    gc_collect_cycles();
};

/**
 * Runs one group of $coroutines members, each performing $awaits real feature
 * calls and $stageRepeats extra repetitions of one stage before them.
 *
 * The stage arrives as a factory called once per coroutine, so whatever the
 * repeated operation needs to exist (the inner fiber of the context-switch row)
 * is built outside the repetitions and lands in the intercept rather than in
 * the slope.
 *
 * @param ?callable(int): callable(): void $stageFactory
 */
$runGroup = static function (
    int $coroutines,
    int $awaits,
    int $stageRepeats = 0,
    ?callable $stageFactory = null,
): void {
    $waitGroup = WaitGroup::create();

    for ($member = 0; $member < $coroutines; $member++) {
        $waitGroup->add(static function () use ($awaits, $stageRepeats, $stageFactory): int {
            $stage = $stageFactory === null ? null : $stageFactory($stageRepeats);

            for ($await = 0; $await < $awaits; $await++) {
                for ($repeat = 0; $repeat < $stageRepeats; $repeat++) {
                    $stage();
                }

                Sleeper::usleep(microseconds: 1);
            }

            return 1;
        });
    }

    $waitGroup->waitAll();
};

/**
 * The switch ladder's group: every member parks $switches times on
 * Scheduler::switch(0), which forces a park on every call. Same suspend, same
 * scheduler turn, same completion path as an awaiting member — no task pushed,
 * none executed, and the turn's crossing is the empty waitAnyTimeoutBatch(0)
 * poll that a queue of parked coroutines makes the scheduler perform, not a
 * fruitful take.
 */
$runSwitchGroup = static function (int $coroutines, int $switches): void {
    $waitGroup = WaitGroup::create();

    for ($member = 0; $member < $coroutines; $member++) {
        $waitGroup->add(static function () use ($switches): int {
            $scheduler = Scheduler::get();

            for ($switch = 0; $switch < $switches; $switch++) {
                $scheduler->switch(quantumMs: 0);
            }

            return 1;
        });
    }

    $waitGroup->waitAll();
};

/**
 * The operations priced inside the live cycle, each with how many times one real
 * cycle performs it — the unit price alone says nothing about the cycle until it
 * is multiplied by that. Each factory runs once per coroutine and returns the
 * thing to repeat, so per-coroutine setup stays out of the slope.
 *
 * @var array<string, array{perCycle: int, factory: callable(int): (callable(): void)}> $stages
 */
$stages = [
    // The PHP engine's own context switch, with nothing of ours around it: an
    // inner fiber parked on Fiber::suspend and resumed once per repetition. It
    // is the floor of a cycle — whatever the scheduler does, it does on top of
    // this pair.
    'Fiber suspend + resume pair' => [
        'perCycle' => 1,
        'factory'  => static function (int $repeats): callable {
            $inner = new Fiber(static function () use ($repeats): void {
                for ($suspend = 0; $suspend < $repeats; $suspend++) {
                    Fiber::suspend();
                }
            });

            $inner->start();

            return static function () use ($inner): void {
                $inner->resume();
            };
        },
    ],

    // FeatureExecutor::exec calls it once per feature call.
    'State::getCurrentFlow()' => [
        'perCycle' => 1,
        'factory'  => static function (): callable {
            return static function (): void {
                State::getCurrentFlow();
            };
        },
    ],

    // Twice per cycle: FeatureExecutor::suspend and Scheduler::dispatchPendingTask
    // each open and close the suspend window.
    'markSuspending + clearSuspending' => [
        'perCycle' => 2,
        'factory'  => static function (): callable {
            $fiberId = spl_object_id(Fiber::getCurrent());

            return static function () use ($fiberId): void {
                State::markSuspending($fiberId);
                State::clearSuspending();
            };
        },
    ],

    // The two allocations every async feature call makes before it suspends.
    'new SleeperPayload + PendingPushDto' => [
        'perCycle' => 1,
        'factory'  => static function (): callable {
            return static function (): void {
                new PendingPushDto(
                    flowKey: 'coordination-profile',
                    payload: new SleeperPayload(microseconds: 1),
                );
            };
        },
    ],

    // Scheduler::tick asks it once per turn, before anything else.
    'hrtime(true) (deadline check)' => [
        'perCycle' => 1,
        'factory'  => static function (): callable {
            return static function (): void {
                hrtime(true);
            };
        },
    ],

    // Scheduler::resumeByResult: one array lookup plus the two key comparisons
    // that make a stale result droppable. The index stands in for
    // Scheduler::$coroutines and the awaited keys — the same shapes, so the
    // lookup and the comparisons cost what they cost there.
    'resumeByResult routing check' => [
        'perCycle' => 1,
        'factory'  => static function (): callable {
            $coroutineIndex = [
                7 => [
                    'flow-key',
                    'task-key',
                ],
            ];

            return static function () use ($coroutineIndex): void {
                $coroutine = $coroutineIndex[7] ?? null;

                $routed = ($coroutine !== null)
                    && ($coroutine[0] === 'flow-key')
                    && ($coroutine[1] === 'task-key');

                unset($routed);
            };
        },
    ],
];

// ---------------------------------------------------------------------------
// The cases, all of them, before anything is measured — the driver below runs
// this list once per round so no case owns a block of wall clock of its own.
// ---------------------------------------------------------------------------

/** @var array<string, array{case: callable(): void, operations: int}> $cases */
$cases = [];

foreach (AWAIT_RUNGS as $awaits) {
    $cases["ladder:$awaits"] = [
        'case'       => static function () use ($runGroup, $awaits): void {
            $runGroup(COROUTINES, $awaits);
        },
        'operations' => COROUTINES,
    ];
}

foreach (SWITCH_RUNGS as $switches) {
    $cases["switch:$switches"] = [
        'case'       => static function () use ($runSwitchGroup, $switches): void {
            $runSwitchGroup(COROUTINES, $switches);
        },
        'operations' => COROUTINES,
    ];
}

foreach ($stages as $stageName => $stage) {
    foreach (STAGE_RUNGS as $repeats) {
        $stageFactory = $stage['factory'];

        $cases["stage:$stageName:$repeats"] = [
            'case'       => static function () use ($runGroup, $repeats, $stageFactory): void {
                $runGroup(COROUTINES, 1, $repeats, $stageFactory);
            },
            'operations' => COROUTINES,
        ];
    }
}

foreach (WIDTHS as $inFlight) {
    $cases["width:$inFlight"] = [
        'case'       => static function () use ($runGroup, $inFlight): void {
            $runGroup($inFlight, 1);
        },
        'operations' => $inFlight,
    ];
}

// ---------------------------------------------------------------------------
// The driver: one pass over every case per round, warm-up rounds discarded.
// ---------------------------------------------------------------------------

/** @var array<string, array{wall: list<float>, cpu: list<float>}> $samples */
$samples = [];

foreach (array_keys($cases) as $name) {
    $samples[$name] = [
        'wall' => [],
        'cpu'  => [],
    ];
}

for ($round = 0; $round < WARMUP_ROUNDS + ROUNDS; $round++) {
    $isWarmup = $round < WARMUP_ROUNDS;

    fwrite(STDERR, sprintf(
        "  round %d/%d%s\r",
        $round + 1,
        WARMUP_ROUNDS + ROUNDS,
        $isWarmup ? ' (warm-up)' : '',
    ));

    foreach ($cases as $name => $case) {
        $drain();

        $usageBefore = getrusage();
        $start       = hrtime(true);

        ($case['case'])();

        $wall       = (hrtime(true) - $start) / 1000 / $case['operations'];
        $usageAfter = getrusage();

        if ($isWarmup) {
            continue;
        }

        $cpuMicroseconds = ($usageAfter['ru_utime.tv_sec'] - $usageBefore['ru_utime.tv_sec']) * 1_000_000
            + ($usageAfter['ru_utime.tv_usec'] - $usageBefore['ru_utime.tv_usec'])
            + ($usageAfter['ru_stime.tv_sec'] - $usageBefore['ru_stime.tv_sec']) * 1_000_000
            + ($usageAfter['ru_stime.tv_usec'] - $usageBefore['ru_stime.tv_usec']);

        $samples[$name]['wall'][] = $wall;
        $samples[$name]['cpu'][]  = $cpuMicroseconds / $case['operations'];
    }
}

fwrite(STDERR, str_repeat(' ', 40) . "\r");

/**
 * @param list<float> $values
 */
$median = static function (array $values): float {
    sort($values);

    return $values[intdiv(count($values), 2)];
};

/**
 * The half-width of the sample: how far the rounds spread around the median
 * they agree on. Printed beside a headline number so a run whose rounds
 * disagree cannot be read as one whose rounds agree.
 *
 * @param list<float> $values
 */
$spread = static function (array $values) use ($median): float {
    $middle = $median($values);

    return max(
        abs(max($values) - $middle),
        abs($middle - min($values)),
    );
};

/** @var array<string, array{wall: float, cpu: float, spread: float}> $report */
$report = [];

foreach ($samples as $name => $sample) {
    $report[$name] = [
        'wall'   => $median($sample['wall']),
        'cpu'    => $median($sample['cpu']),
        'spread' => $spread($sample['cpu']),
    ];
}

/**
 * Least-squares slope of cost against rung, in microseconds per unit. The rungs
 * are a straight line when the thing being added is really additive; the caller
 * reports the residual beside the slope so a curved case is not read as one.
 *
 * @param array<int, float> $costByRung
 *
 * @return array{slope: float, intercept: float, maxResidual: float}
 */
$fit = static function (array $costByRung): array {
    $count = count($costByRung);
    $sumX  = 0.0;
    $sumY  = 0.0;
    $sumXy = 0.0;
    $sumXx = 0.0;

    foreach ($costByRung as $rung => $cost) {
        $sumX  += $rung;
        $sumY  += $cost;
        $sumXy += $rung * $cost;
        $sumXx += $rung * $rung;
    }

    $slope     = (($count * $sumXy) - ($sumX * $sumY)) / (($count * $sumXx) - ($sumX * $sumX));
    $intercept = ($sumY - ($slope * $sumX)) / $count;

    $maxResidual = 0.0;

    foreach ($costByRung as $rung => $cost) {
        $maxResidual = max($maxResidual, abs($cost - ($intercept + ($slope * $rung))));
    }

    return [
        'slope'       => $slope,
        'intercept'   => $intercept,
        'maxResidual' => $maxResidual,
    ];
};

$ladderCpu = [];

foreach (AWAIT_RUNGS as $awaits) {
    $ladderCpu[$awaits] = $report["ladder:$awaits"]['cpu'];
}

// K = 0 is excluded: that rung never touches the extension at all, so the step
// up to K = 1 pays for the flow's whole lifetime as well as for one cycle. Fit
// the cycle where only cycles are being added, then read the step as what is
// left over.
$ladderFit = $fit(array_filter(
    $ladderCpu,
    static fn (int $awaits): bool => $awaits >= 1,
    ARRAY_FILTER_USE_KEY,
));

$firstAwaitSurcharge = $ladderCpu[1] - $ladderCpu[0] - $ladderFit['slope'];

$switchCpu = [];

foreach (SWITCH_RUNGS as $switches) {
    $switchCpu[$switches] = $report["switch:$switches"]['cpu'];
}

$switchFit = $fit($switchCpu);

// The switch ladder shares the feature ladder's K = 0 rung: a member that parks
// zero times is the same member that awaits zero times.
$firstSwitchSurcharge = $switchCpu[1] - $ladderCpu[0] - $switchFit['slope'];

$stageCosts    = [];
$stagesInCycle = 0.0;

foreach ($stages as $stageName => $stage) {
    $byRung = [];

    foreach (STAGE_RUNGS as $repeats) {
        $byRung[$repeats] = $report["stage:$stageName:$repeats"]['cpu'];
    }

    $stageFit             = $fit($byRung);
    $stageFit['perCycle'] = $stage['perCycle'];
    $stageFit['inCycle']  = $stageFit['slope'] * $stage['perCycle'];

    $stagesInCycle += $stageFit['inCycle'];

    $stageCosts[$stageName] = $stageFit;
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

$eol = PHP_EOL;

echo $eol . 'coordination profile — ' . COROUTINES . ' coroutines, median of ' . ROUNDS
    . ' interleaved rounds' . $eol . $eol;

echo '  ladder: cost per coroutine by number of awaits' . $eol . $eol;
printf("  %-24s  %10s  %10s  %10s\n", 'awaits per coroutine', 'wall, us', 'cpu, us', '+/- us');

foreach (AWAIT_RUNGS as $awaits) {
    $timing = $report["ladder:$awaits"];

    printf("  %-24d  %10.3f  %10.3f  %10.3f\n", $awaits, $timing['wall'], $timing['cpu'], $timing['spread']);
}

printf("%s  a coroutine, never suspends     %10.3f us cpu%s", $eol, $ladderCpu[0], $eol);
printf("  one cycle (slope over K >= 1)   %10.3f us cpu%s", $ladderFit['slope'], $eol);
printf("  first await, over one cycle     %10.3f us cpu%s", $firstAwaitSurcharge, $eol);
printf("  worst deviation from that line  %10.3f us%s", $ladderFit['maxResidual'], $eol);

echo $eol . '  switch ladder: the same trip through the scheduler, no task' . $eol . $eol;
printf("  %-24s  %10s  %10s  %10s\n", 'switches per coroutine', 'wall, us', 'cpu, us', '+/- us');

foreach (SWITCH_RUNGS as $switches) {
    $timing = $report["switch:$switches"];

    printf("  %-24d  %10.3f  %10.3f  %10.3f\n", $switches, $timing['wall'], $timing['cpu'], $timing['spread']);
}

printf("%s  one park + resume (slope)       %10.3f us cpu%s", $eol, $switchFit['slope'], $eol);
printf("  first park, over one of those   %10.3f us cpu%s", $firstSwitchSurcharge, $eol);
printf("  worst deviation from that line  %10.3f us%s", $switchFit['maxResidual'], $eol);
// Not "what the task costs": the two trips differ in their crossing pattern as
// well as in the task (see the switch entry in the file docblock). What the
// difference is good for is its size — a cycle that pushes a task, has it
// executed and takes the result back across the boundary prices the same as one
// that does none of that, so neither the task nor the crossing is what sets the
// price of a trip through the scheduler.
printf(
    "  feature cycle minus this one    %10.3f us cpu%s",
    $ladderFit['slope'] - $switchFit['slope'],
    $eol,
);

echo $eol . '  stages of one cycle, priced inside a live cycle' . $eol . $eol;

$nameColumn = max(array_map(strlen(...), array_keys($stageCosts)));

printf("  %-{$nameColumn}s  %10s  %10s  %10s  %10s\n", '', 'each, us', 'deviation', 'per cycle', 'in cycle');

foreach ($stageCosts as $stageName => $stageFit) {
    printf(
        "  %-{$nameColumn}s  %10.4f  %10.4f  %10d  %10.4f\n",
        $stageName,
        $stageFit['slope'],
        $stageFit['maxResidual'],
        $stageFit['perCycle'],
        $stageFit['inCycle'],
    );
}

// What the named stages do NOT explain. Everything left is the push half
// (packing the payload, the crossing, the extension starting the task), the take
// half that boundary-profile.php prices on its own, and the extension executing
// the task at all — its threads are this process's threads, so its CPU is in
// every number here.
printf(
    "%s  named stages account for %.3f of the %.3f us cycle; %.3f is elsewhere%s",
    $eol,
    $stagesInCycle,
    $ladderFit['slope'],
    $ladderFit['slope'] - $stagesInCycle,
    $eol,
);

echo $eol . '  one cycle by fan-out width' . $eol . $eol;
printf("  %-24s  %10s  %10s  %10s\n", 'coroutines in flight', 'wall, us', 'cpu, us', '+/- us');

foreach (WIDTHS as $inFlight) {
    $timing = $report["width:$inFlight"];

    printf("  %-24d  %10.3f  %10.3f  %10.3f\n", $inFlight, $timing['wall'], $timing['cpu'], $timing['spread']);
}

echo $eol;
