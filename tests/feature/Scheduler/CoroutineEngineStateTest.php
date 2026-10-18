<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use ArrayObject;
use Fiber;
use SConcur\Connection\Extension;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\EngineState\SerializeGate;
use SConcur\Tests\Impl\EngineState\SerializeProbe;
use SConcur\Tests\Impl\EngineState\SharedNode;
use SConcur\WaitGroup;

/**
 * The engine state the extension keeps per coroutine: json_last_error(),
 * preg_last_error(), error_get_last() and the serialize() object table. PHP
 * holds each once per process; the extension
 * moves them in and out of a block attached to the fiber on every switch, so a
 * neighbour that runs between a call and the check that reads its outcome
 * leaves no trace.
 *
 * Each state has a deterministic test — the neighbour runs while the probe is
 * parked on a feature wait — and the two most exposed ones are also hammered
 * under a 1 ms preemption quantum, where the switch lands right after the
 * internal call returns. The rest covers the edges the isolation has: a fatal
 * error must still reach the shutdown functions, a pooled fiber must start its
 * next coroutine clean, and the context outside a group keeps its own state.
 */
class CoroutineEngineStateTest extends BaseTestCase
{
    protected static string $sequentialProbe = '';

    public function testJsonErrorIsPerCoroutineAcrossAWait(): void
    {
        $keys      = [];
        $waitGroup = WaitGroup::create();

        $keys[] = $waitGroup->add(
            callback: static function (): int {
                json_decode('{"ok":true}');

                Sleeper::usleep(microseconds: 3_000);

                return json_last_error();
            },
        );

        $keys[] = $waitGroup->add(
            callback: static function (): int {
                Sleeper::usleep(microseconds: 1_000);

                json_decode('{broken');

                return json_last_error();
            },
        );

        self::assertSame(
            [
                JSON_ERROR_NONE,
                JSON_ERROR_SYNTAX,
            ],
            $this->resultsInAddOrder(
                waitGroup: $waitGroup,
                keys: $keys,
            ),
        );
    }

    public function testPcreErrorIsPerCoroutineAcrossAWait(): void
    {
        $keys      = [];
        $waitGroup = WaitGroup::create();

        $keys[] = $waitGroup->add(
            callback: static function (): int {
                preg_match('/a/', 'a');

                Sleeper::usleep(microseconds: 3_000);

                return preg_last_error();
            },
        );

        $keys[] = $waitGroup->add(
            callback: static function (): int {
                Sleeper::usleep(microseconds: 1_000);

                preg_match('/\d/u', "\xff");

                return preg_last_error();
            },
        );

        self::assertSame(
            [
                PREG_NO_ERROR,
                PREG_BAD_UTF8_ERROR,
            ],
            $this->resultsInAddOrder(
                waitGroup: $waitGroup,
                keys: $keys,
            ),
        );
    }

    public function testLastErrorIsPerCoroutineAcrossAWait(): void
    {
        $keys      = [];
        $waitGroup = WaitGroup::create();

        $keys[] = $waitGroup->add(
            callback: static function (): ?string {
                error_clear_last();

                Sleeper::usleep(microseconds: 3_000);

                return error_get_last()['message'] ?? null;
            },
        );

        $keys[] = $waitGroup->add(
            callback: static function (): ?string {
                Sleeper::usleep(microseconds: 1_000);

                self::raiseWarning('neighbour warning');

                return error_get_last()['message'] ?? null;
            },
        );

        self::assertSame(
            [
                null,
                'neighbour warning',
            ],
            $this->resultsInAddOrder(
                waitGroup: $waitGroup,
                keys: $keys,
            ),
        );
    }

    public function testSerializeObjectTableIsPerCoroutineAcrossAWait(): void
    {
        $expected = $this->serializeProbeSequentially();

        SerializeGate::onDestruct(static function (): void {
            Sleeper::usleep(microseconds: 3_000);
        });

        try {
            $keys      = [];
            $waitGroup = WaitGroup::create();

            $keys[] = $waitGroup->add(
                callback: static fn(): string => serialize(self::makeProbe()),
            );

            $keys[] = $waitGroup->add(
                callback: static function (): string {
                    Sleeper::usleep(microseconds: 1_000);

                    return serialize(self::makeProbe());
                },
            );

            $results = $this->resultsInAddOrder(
                waitGroup: $waitGroup,
                keys: $keys,
            );
        } finally {
            SerializeGate::onDestruct(null);
        }

        self::assertSame(
            [
                $expected,
                $expected,
            ],
            $results,
            'A serialize() that joined a parked neighbour\'s object table writes its back-references against it.',
        );

        foreach ($results as $result) {
            self::assertInstanceOf(SerializeProbe::class, unserialize($result));
        }
    }

    public function testJsonErrorIsPerCoroutineUnderPreemption(): void
    {
        $validJson = json_encode(array_fill(0, 2_000, [
            'id'   => 1,
            'name' => 'a name',
            'tags' => ['x', 'y'],
        ]));

        self::assertIsString($validJson);

        // One shared store rather than by-reference locals: the neighbour reads
        // what the probe wrote from inside its own closure.
        /** @var ArrayObject<string, mixed> $shared */
        $shared = new ArrayObject([
            'done'            => false,
            'neighbourError'  => JSON_ERROR_NONE,
            'neighbourRounds' => 0,
            'roundsAtStart'   => 0,
            'roundsAtEnd'     => 0,
        ]);

        Scheduler::get()->enablePreemption(quantumMs: 1);

        try {
            $keys      = [];
            $waitGroup = WaitGroup::create();

            // The neighbour fails a decode as often as it can until the probe
            // is through: every one of its iterations leaves a syntax error in
            // the process-wide slot. The round cap keeps a broken preemption from
            // turning the test into a hang.
            $keys[] = $waitGroup->add(
                callback: static function () use ($shared): void {
                    while (!$shared['done'] && $shared['neighbourRounds'] < 5_000_000) {
                        json_decode('{broken');

                        $shared['neighbourError'] = json_last_error();
                        $shared['neighbourRounds']++;
                    }
                },
            );

            $keys[] = $waitGroup->add(
                callback: static function () use ($validJson, $shared): int {
                    $mismatches = 0;

                    $shared['roundsAtStart'] = $shared['neighbourRounds'];

                    try {
                        for ($iteration = 0; $iteration < 1_000; $iteration++) {
                            json_decode($validJson);

                            if (json_last_error() !== JSON_ERROR_NONE) {
                                ++$mismatches;
                            }
                        }
                    } finally {
                        $shared['roundsAtEnd'] = $shared['neighbourRounds'];
                        $shared['done']        = true;
                    }

                    return $mismatches;
                },
            );

            $results = $this->resultsInAddOrder(
                waitGroup: $waitGroup,
                keys: $keys,
            );
        } finally {
            Scheduler::get()->disablePreemption();
        }

        self::assertSame(0, $results[1], 'json_last_error() reported the neighbour\'s syntax error.');
        self::assertSame(JSON_ERROR_SYNTAX, $shared['neighbourError'], 'The neighbour must have been failing decodes.');
        self::assertGreaterThan(
            $shared['roundsAtStart'],
            $shared['roundsAtEnd'],
            'The neighbour must have run while the probe was looping, or nothing was interleaved.',
        );
    }

    public function testSerializeObjectTableIsPerCoroutineUnderPreemption(): void
    {
        $this->serializeProbeSequentially();

        /** @var ArrayObject<string, mixed> $gate */
        $gate = new ArrayObject([
            'depth'    => 0,
            'maxDepth' => 0,
        ]);

        // The destructor crunches CPU across several quanta, so preemption
        // parks the coroutine inside serialize() with its table unlocked. The
        // depth counter shows how many coroutines stood in that window at once.
        SerializeGate::onDestruct(static function () use ($gate): void {
            $gate['depth']++;
            $gate['maxDepth'] = max($gate['maxDepth'], $gate['depth']);

            $digest = 'gate';

            for ($iteration = 0; $iteration < 5_000; $iteration++) {
                $digest = hash('sha256', $digest);
            }

            $gate['depth']--;
        });

        Scheduler::get()->enablePreemption(quantumMs: 1);

        try {
            $keys      = [];
            $waitGroup = WaitGroup::create();

            for ($coroutine = 0; $coroutine < 20; $coroutine++) {
                $keys[] = $waitGroup->add(
                    callback: static function (): int {
                        $mismatches = 0;

                        for ($round = 0; $round < 10; $round++) {
                            if (serialize(self::makeProbe()) !== self::$sequentialProbe) {
                                ++$mismatches;
                            }
                        }

                        return $mismatches;
                    },
                );
            }

            $results = $this->resultsInAddOrder(
                waitGroup: $waitGroup,
                keys: $keys,
            );
        } finally {
            Scheduler::get()->disablePreemption();

            SerializeGate::onDestruct(null);
        }

        self::assertSame(
            array_fill(0, 20, 0),
            $results,
            'A serialize() preempted in its unlocked window shared its table.',
        );
        self::assertGreaterThan(
            1,
            $gate['maxDepth'],
            'Coroutines must have been preempted inside the window at the same time.',
        );
    }

    public function testUnserializeObjectTableIsPerCoroutineAcrossAWait(): void
    {
        $serialized = $this->serializeProbeSequentially();

        // The engine calls __unserialize() under its lock; a wait inside it parks
        // the coroutine with the unserialize table and that lock in flight.
        SerializeProbe::onUnserialize(static function (): void {
            Sleeper::usleep(microseconds: 3_000);
        });

        try {
            $keys      = [];
            $waitGroup = WaitGroup::create();

            $keys[] = $waitGroup->add(
                callback: static fn(): bool => self::probeIsIntact(unserialize($serialized)),
            );

            $keys[] = $waitGroup->add(
                callback: static function () use ($serialized): bool {
                    Sleeper::usleep(microseconds: 1_000);

                    return self::probeIsIntact(unserialize($serialized));
                },
            );

            $results = $this->resultsInAddOrder(
                waitGroup: $waitGroup,
                keys: $keys,
            );
        } finally {
            SerializeProbe::onUnserialize(null);
        }

        self::assertSame(
            [
                true,
                true,
            ],
            $results,
            'An unserialize() parked inside __unserialize() must resolve its back-reference against its own table.',
        );
    }

    public function testPcreErrorIsPerCoroutineUnderPreemption(): void
    {
        /** @var ArrayObject<string, mixed> $shared */
        $shared = new ArrayObject([
            'done'            => false,
            'neighbourError'  => PREG_NO_ERROR,
            'neighbourRounds' => 0,
            'roundsAtStart'   => 0,
            'roundsAtEnd'     => 0,
        ]);

        $subject = str_repeat('a', 200_000);

        Scheduler::get()->enablePreemption(quantumMs: 1);

        try {
            $keys      = [];
            $waitGroup = WaitGroup::create();

            $keys[] = $waitGroup->add(
                callback: static function () use ($shared): void {
                    while (!$shared['done'] && $shared['neighbourRounds'] < 5_000_000) {
                        preg_match('/\d/u', "\xff");

                        $shared['neighbourError'] = preg_last_error();
                        $shared['neighbourRounds']++;
                    }
                },
            );

            $keys[] = $waitGroup->add(
                callback: static function () use ($subject, $shared): int {
                    $mismatches = 0;

                    $shared['roundsAtStart'] = $shared['neighbourRounds'];

                    try {
                        for ($iteration = 0; $iteration < 1_000; $iteration++) {
                            preg_match_all('/a/', $subject);

                            if (preg_last_error() !== PREG_NO_ERROR) {
                                ++$mismatches;
                            }
                        }
                    } finally {
                        $shared['roundsAtEnd'] = $shared['neighbourRounds'];
                        $shared['done']        = true;
                    }

                    return $mismatches;
                },
            );

            $results = $this->resultsInAddOrder(
                waitGroup: $waitGroup,
                keys: $keys,
            );
        } finally {
            Scheduler::get()->disablePreemption();
        }

        self::assertSame(0, $results[1], 'preg_last_error() reported the neighbour\'s error.');
        self::assertSame(
            PREG_BAD_UTF8_ERROR,
            $shared['neighbourError'],
            'The neighbour must have been failing matches.',
        );
        self::assertGreaterThan(
            $shared['roundsAtStart'],
            $shared['roundsAtEnd'],
            'The neighbour must have run while the probe was looping, or nothing was interleaved.',
        );
    }

    public function testTheContextOutsideKeepsItsOwnStateAcrossAGroup(): void
    {
        json_decode('[]');
        preg_match('/a/', 'a');
        error_clear_last();

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function (): void {
                json_decode('{broken');
                preg_match('/\d/u', "\xff");
                self::raiseWarning('inside the coroutine');

                Sleeper::usleep(microseconds: 1_000);
            },
        );

        $waitGroup->waitAll();

        self::assertSame(JSON_ERROR_NONE, json_last_error());
        self::assertSame(PREG_NO_ERROR, preg_last_error());
        self::assertNull(error_get_last(), 'The coroutine\'s warning must not become the last error outside it.');
    }

    public function testResetEngineStateClearsTheRunningContext(): void
    {
        json_decode('{broken');
        preg_match('/\d/u', "\xff");
        self::raiseWarning('before the reset');

        Extension::get()->resetEngineState();

        self::assertSame(JSON_ERROR_NONE, json_last_error());
        self::assertSame(PREG_NO_ERROR, preg_last_error());
        self::assertNull(error_get_last());
    }

    /**
     * A fatal error bails out of the fiber it happens in. The record the engine
     * keeps for error_get_last() must survive that switch, because the shutdown
     * functions are how an application learns about a fatal at all. A fatal ends
     * the process, so the script runs on its own.
     */
    public function testFatalErrorInsideACoroutineReachesTheShutdownFunctions(): void
    {
        $root      = dirname(__DIR__, 3);
        $extension = getenv('SCONCUR_EXT') ?: $root . '/ext/build/sconcur.so';

        $command = [
            PHP_BINARY,
            '-d',
            'extension=' . $extension,
            $root . '/tests/impl/EngineState/fatal-in-coroutine.php',
        ];

        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
        );

        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        self::assertSame(255, $exitCode, "A fatal error must end the script. stderr: $stderr");
        self::assertStringContainsString('extension: loaded', (string) $stdout, 'The child must run the extension.');
        self::assertStringContainsString(
            'last error: Class FatalInCoroutineChild cannot extend final class FatalInCoroutineBase',
            (string) $stdout,
        );
    }

    /**
     * Scheduler::spawn() runs on a fiber from the pool, and the block the
     * extension keeps on the fiber outlives the coroutine. The next coroutine on
     * that fiber must not read what the previous one left.
     */
    public function testAPooledFiberStartsItsNextCoroutineClean(): void
    {
        $scheduler = Scheduler::get();

        /** @var ArrayObject<string, mixed> $observed */
        $observed = new ArrayObject([
            'firstFiberId'  => 0,
            'secondFiberId' => 0,
            'second'        => [],
        ]);

        $scheduler->spawn(callback: static function () use ($observed): void {
            $observed['firstFiberId'] = self::currentFiberId();

            json_decode('{broken');
            preg_match('/\d/u', "\xff");
            self::raiseWarning('first spawn warning');
        });

        $scheduler->spawn(callback: static function () use ($observed): void {
            $observed['secondFiberId'] = self::currentFiberId();

            $observed['second'] = [
                json_last_error(),
                preg_last_error(),
                error_get_last()['message'] ?? null,
            ];
        });

        self::assertNotSame(0, $observed['firstFiberId']);
        self::assertSame(
            $observed['firstFiberId'],
            $observed['secondFiberId'],
            'Both coroutines must have run on the same pooled fiber.',
        );
        self::assertSame(
            [
                JSON_ERROR_NONE,
                PREG_NO_ERROR,
                null,
            ],
            $observed['second'],
            'The second coroutine read the first one\'s state.',
        );
    }

    protected static function currentFiberId(): int
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? 0 : spl_object_id($fiber);
    }

    /**
     * The graph came back with its one shared node shared, so the back-reference
     * resolved against the right table.
     */
    protected static function probeIsIntact(mixed $probe): bool
    {
        return $probe instanceof SerializeProbe && $probe->first() === $probe->second();
    }

    protected static function makeProbe(): SerializeProbe
    {
        $sharedNode = new SharedNode();

        return new SerializeProbe(
            first: $sharedNode,
            second: $sharedNode,
        );
    }

    /**
     * Raises a warning the engine records for error_get_last() without PHPUnit
     * seeing it: a handler that declines hands the warning to the engine's own.
     */
    protected static function raiseWarning(string $message): void
    {
        set_error_handler(static fn(): bool => false);

        try {
            @trigger_error($message, E_USER_WARNING);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The string serialize() produces when nothing runs beside it; the gate hook
     * is not attached yet, so the destructor neither waits nor crunches.
     */
    protected function serializeProbeSequentially(): string
    {
        self::$sequentialProbe = serialize(self::makeProbe());

        self::assertStringContainsString(
            'r:',
            self::$sequentialProbe,
            'The probe must contain a back-reference for the test to mean anything.',
        );

        return self::$sequentialProbe;
    }

    /**
     * Results in the order the coroutines were added, since the group yields
     * them in completion order.
     *
     * @param list<string> $keys what add() answered, in call order
     *
     * @return list<mixed>
     */
    protected function resultsInAddOrder(WaitGroup $waitGroup, array $keys): array
    {
        $results = $waitGroup->waitResults();

        return array_map(static fn(string $key): mixed => $results[$key], $keys);
    }
}
