<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use ArrayObject;
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
 * holds each once per process; the extension moves them in and out of a block
 * attached to the fiber on every switch, so a neighbour that runs between a
 * call and the check that reads its outcome leaves no trace.
 *
 * Each state has a deterministic test — the neighbour runs while the probe is
 * parked on a feature wait — and the two most exposed ones are also hammered
 * under a 1 ms preemption quantum, where the switch lands right after the
 * internal call returns.
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
            $this->resultsInAddOrder($waitGroup, $keys),
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
            $this->resultsInAddOrder($waitGroup, $keys),
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

                // A handler that declines, so the engine's own handler records
                // the warning as the last error without PHPUnit seeing it.
                set_error_handler(static fn(): bool => false);

                try {
                    @trigger_error('neighbour warning', E_USER_WARNING);
                } finally {
                    restore_error_handler();
                }

                return error_get_last()['message'] ?? null;
            },
        );

        self::assertSame(
            [
                null,
                'neighbour warning',
            ],
            $this->resultsInAddOrder($waitGroup, $keys),
        );
    }

    public function testSerializeObjectTableIsPerCoroutineAcrossAWait(): void
    {
        $expected = $this->serializeProbeSequentially();

        SerializeGate::$onDestruct = static function (): void {
            Sleeper::usleep(microseconds: 3_000);
        };

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

            $results = $this->resultsInAddOrder($waitGroup, $keys);
        } finally {
            SerializeGate::$onDestruct = null;
        }

        self::assertSame(
            [
                $expected,
                $expected,
            ],
            $results,
            'A serialize() that joined a parked neighbour\'s object table writes its back-references against that table.',
        );

        foreach ($results as $result) {
            self::assertInstanceOf(SerializeProbe::class, unserialize($result));
        }
    }

    public function testJsonErrorIsPerCoroutineUnderPreemption(): void
    {
        $validJson = json_encode(array_fill(0, 2_000, ['id' => 1, 'name' => 'a name', 'tags' => ['x', 'y']]));

        self::assertIsString($validJson);

        // One shared store rather than by-reference locals: the neighbour reads
        // what the probe wrote from inside its own closure.
        $shared = new ArrayObject([
            'done'           => false,
            'neighbourError' => JSON_ERROR_NONE,
        ]);

        Scheduler::get()->enablePreemption(quantumMs: 1);

        try {
            $keys      = [];
            $waitGroup = WaitGroup::create();

            // The neighbour fails a decode as often as it can until the probe
            // is through: every one of its iterations leaves a syntax error in
            // the process-wide slot.
            $keys[] = $waitGroup->add(
                callback: static function () use ($shared): void {
                    while (!$shared['done']) {
                        json_decode('{broken');

                        $shared['neighbourError'] = json_last_error();
                    }
                },
            );

            $keys[] = $waitGroup->add(
                callback: static function () use ($validJson, $shared): int {
                    $mismatches = 0;

                    try {
                        for ($iteration = 0; $iteration < 1_000; $iteration++) {
                            json_decode($validJson);

                            if (json_last_error() !== JSON_ERROR_NONE) {
                                ++$mismatches;
                            }
                        }
                    } finally {
                        $shared['done'] = true;
                    }

                    return $mismatches;
                },
            );

            $results = $this->resultsInAddOrder($waitGroup, $keys);
        } finally {
            Scheduler::get()->disablePreemption();
        }

        self::assertSame(0, $results[1], 'json_last_error() reported the neighbour\'s syntax error.');
        self::assertSame(JSON_ERROR_SYNTAX, $shared['neighbourError'], 'The neighbour must have been failing decodes all along.');
    }

    public function testSerializeObjectTableIsPerCoroutineUnderPreemption(): void
    {
        $expected = $this->serializeProbeSequentially();

        // The destructor crunches CPU across several quanta, so preemption
        // parks the coroutine inside serialize() with its table unlocked.
        SerializeGate::$onDestruct = static function (): void {
            $digest = 'gate';

            for ($iteration = 0; $iteration < 5_000; $iteration++) {
                $digest = hash('sha256', $digest);
            }
        };

        Scheduler::get()->enablePreemption(quantumMs: 1);

        try {
            $keys      = [];
            $waitGroup = WaitGroup::create();

            foreach (range(1, 20) as $index) {
                $keys[] = $waitGroup->add(
                    callback: static function (): int {
                        $mismatches = 0;

                        for ($round = 0; $round < 10; $round++) {
                            $serialized = serialize(self::makeProbe());

                            if ($serialized !== self::$sequentialProbe) {
                                ++$mismatches;
                            }
                        }

                        return $mismatches;
                    },
                );
            }

            $results = $this->resultsInAddOrder($waitGroup, $keys);
        } finally {
            Scheduler::get()->disablePreemption();

            SerializeGate::$onDestruct = null;
        }

        self::assertSame(array_fill(0, 20, 0), $results, 'A serialize() preempted in its unlocked window shared its object table.');
        self::assertNotSame('', $expected);
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
     * The string serialize() produces when nothing runs beside it; the gate hook
     * is detached for the duration so the destructor neither waits nor crunches.
     */
    protected function serializeProbeSequentially(): string
    {
        $hook = SerializeGate::$onDestruct;

        SerializeGate::$onDestruct = null;

        try {
            self::$sequentialProbe = serialize(self::makeProbe());
        } finally {
            SerializeGate::$onDestruct = $hook;
        }

        self::assertStringContainsString('r:', self::$sequentialProbe, 'The probe must contain a back-reference for the test to mean anything.');

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
