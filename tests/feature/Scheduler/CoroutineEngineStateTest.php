<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use ArrayObject;
use DOMDocument;
use Fiber;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\EngineState\SerializeGate;
use SConcur\Tests\Impl\EngineState\SerializeProbe;
use SConcur\Tests\Impl\EngineState\SharedNode;
use SConcur\WaitGroup;

/**
 * The engine state the extension keeps per coroutine: json_last_error(),
 * preg_last_error(), error_get_last(), the libxml error list and the
 * serialize() object table. PHP holds each once per process; the extension
 * moves them in and out of a block attached to the fiber on every switch, so a
 * neighbour that runs between a call and the check that reads its outcome
 * leaves no trace.
 *
 * Each state has a deterministic test — the neighbour runs while the probe is
 * parked on a feature wait — and the two most exposed ones are also hammered
 * under a 1 ms preemption quantum, where the switch lands right after the
 * internal call returns. The rest covers the edges the isolation has: a fatal
 * error must still reach the shutdown functions, a pooled fiber must start its
 * next coroutine clean, and libxml_use_internal_errors() stays the process-wide
 * switch it always was while the collected errors are per coroutine.
 */
class CoroutineEngineStateTest extends BaseTestCase
{
    protected const string LIBXML_ERROR_A = 'Premature end of data in tag a line 1';
    protected const string LIBXML_ERROR_B = 'Premature end of data in tag b line 1';
    protected const string LIBXML_ERROR_M = 'Premature end of data in tag m line 1';

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

    public function testLibxmlErrorsArePerCoroutineAcrossAWait(): void
    {
        $keys      = [];
        $waitGroup = WaitGroup::create();

        $keys[] = $waitGroup->add(
            callback: static function (): array {
                libxml_use_internal_errors(true);

                try {
                    (new DOMDocument())->loadXML('<a>');

                    Sleeper::usleep(microseconds: 3_000);

                    return self::libxmlErrorMessages();
                } finally {
                    libxml_use_internal_errors(false);
                }
            },
        );

        // Reads its own list while the first coroutine's is still live, and only
        // then turns the process-wide switch off — which, as always, ends the
        // collecting for everyone.
        $keys[] = $waitGroup->add(
            callback: static function (): array {
                Sleeper::usleep(microseconds: 1_000);

                libxml_use_internal_errors(true);

                try {
                    (new DOMDocument())->loadXML('<b>');

                    $messages = self::libxmlErrorMessages();

                    Sleeper::usleep(microseconds: 3_000);

                    return $messages;
                } finally {
                    libxml_use_internal_errors(false);
                }
            },
        );

        self::assertSame(
            [
                [self::LIBXML_ERROR_A],
                [self::LIBXML_ERROR_B],
            ],
            $this->resultsInAddOrder(
                waitGroup: $waitGroup,
                keys: $keys,
            ),
            'Each coroutine must see the errors of its own parse and nothing of the neighbour\'s.',
        );
    }

    /**
     * libxml_use_internal_errors() installs one error handler for the whole
     * process, and that handler appends to the list of whatever context is
     * running. A coroutine that never called it still parses while the switch is
     * on, so it must collect into a list of its own rather than crash on a
     * missing one or warn.
     */
    public function testLibxmlSwitchOnInOneCoroutineGivesTheNeighbourAListOfItsOwn(): void
    {
        $keys      = [];
        $waitGroup = WaitGroup::create();

        $keys[] = $waitGroup->add(
            callback: static function (): array {
                libxml_use_internal_errors(true);

                try {
                    (new DOMDocument())->loadXML('<a>');

                    Sleeper::usleep(microseconds: 3_000);

                    return self::libxmlErrorMessages();
                } finally {
                    libxml_use_internal_errors(false);
                }
            },
        );

        $keys[] = $waitGroup->add(
            callback: static function (): array {
                Sleeper::usleep(microseconds: 1_000);

                $warnings = self::countWarnings(static function (): void {
                    (new DOMDocument())->loadXML('<b>');
                });

                return [
                    $warnings,
                    self::libxmlErrorMessages(),
                ];
            },
        );

        self::assertSame(
            [
                [self::LIBXML_ERROR_A],
                [
                    0,
                    [self::LIBXML_ERROR_B],
                ],
            ],
            $this->resultsInAddOrder(
                waitGroup: $waitGroup,
                keys: $keys,
            ),
        );
    }

    public function testLibxmlSwitchLeftOnByAFinishedCoroutineStillCollectsOutside(): void
    {
        try {
            $keys      = [];
            $waitGroup = WaitGroup::create();

            // Turns the switch on and returns without turning it off: the list it
            // collected into dies with the fiber, the switch stays.
            $keys[] = $waitGroup->add(
                callback: static function (): array {
                    libxml_use_internal_errors(true);

                    (new DOMDocument())->loadXML('<a>');

                    return self::libxmlErrorMessages();
                },
            );

            self::assertSame(
                [
                    [self::LIBXML_ERROR_A],
                ],
                $this->resultsInAddOrder(
                    waitGroup: $waitGroup,
                    keys: $keys,
                ),
            );

            self::assertTrue(libxml_use_internal_errors(), 'The switch is process-wide and was left on.');

            $warnings = self::countWarnings(static function (): void {
                (new DOMDocument())->loadXML('<m>');
            });

            self::assertSame(0, $warnings);
            self::assertSame([self::LIBXML_ERROR_M], self::libxmlErrorMessages());
        } finally {
            libxml_use_internal_errors(false);
        }
    }

    public function testLibxmlSwitchOnOutsideAppliesInsideACoroutine(): void
    {
        libxml_use_internal_errors(true);

        try {
            $keys      = [];
            $waitGroup = WaitGroup::create();

            $keys[] = $waitGroup->add(
                callback: static function (): array {
                    $warnings = self::countWarnings(static function (): void {
                        (new DOMDocument())->loadXML('<a>');
                    });

                    return [
                        $warnings,
                        self::libxmlErrorMessages(),
                    ];
                },
            );

            self::assertSame(
                [
                    [
                        0,
                        [self::LIBXML_ERROR_A],
                    ],
                ],
                $this->resultsInAddOrder(
                    waitGroup: $waitGroup,
                    keys: $keys,
                ),
            );

            self::assertSame([], libxml_get_errors(), 'The coroutine\'s error must not land in the list outside.');
        } finally {
            libxml_use_internal_errors(false);
        }
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
        $validJson = json_encode(array_fill(0, 2_000, ['id' => 1, 'name' => 'a name', 'tags' => ['x', 'y']]));

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
        $expected = $this->serializeProbeSequentially();

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

        self::assertSame(array_fill(0, 20, 0), $results, 'A serialize() preempted in its unlocked window shared its table.');
        self::assertGreaterThan(1, $gate['maxDepth'], 'Coroutines must have been preempted inside the window at the same time.');
        self::assertNotSame('', $expected);
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
            'php',
            '-d',
            'extension=' . $extension,
            '-d',
            'display_errors=0',
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

        try {
            $scheduler->spawn(static function () use ($observed): void {
                $observed['firstFiberId'] = self::currentFiberId();

                json_decode('{broken');
                preg_match('/\d/u', "\xff");
                self::raiseWarning('first spawn warning');

                libxml_use_internal_errors(true);

                (new DOMDocument())->loadXML('<a>');
            });

            $scheduler->spawn(static function () use ($observed): void {
                $observed['secondFiberId'] = self::currentFiberId();

                $observed['second'] = [
                    json_last_error(),
                    preg_last_error(),
                    error_get_last()['message'] ?? null,
                    libxml_use_internal_errors(),
                    self::libxmlErrorMessages(),
                ];
            });
        } finally {
            libxml_use_internal_errors(false);
        }

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
                true,
                [],
            ],
            $observed['second'],
            'The second coroutine read the first one\'s state; only the process-wide libxml switch may carry over.',
        );
    }

    protected static function currentFiberId(): int
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? 0 : spl_object_id($fiber);
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
     * Runs the callback with a handler that swallows warnings and answers how
     * many it swallowed.
     */
    protected static function countWarnings(callable $callback): int
    {
        $warnings = 0;

        set_error_handler(static function () use (&$warnings): bool {
            ++$warnings;

            return true;
        });

        try {
            $callback();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    /**
     * The messages libxml collected so far, leaving the list as it is.
     *
     * @return list<string>
     */
    protected static function libxmlErrorMessages(): array
    {
        $messages = [];

        foreach (libxml_get_errors() as $error) {
            $messages[] = trim($error->message);
        }

        return $messages;
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
