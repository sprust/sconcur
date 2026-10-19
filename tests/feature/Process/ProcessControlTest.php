<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Process;

use SConcur\Exceptions\Process\ForkRefusedException;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Process\ProcessControl;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\PhpScript;
use SConcur\WaitGroup;

/**
 * The process control a worker master forks and supervises with, in place of ext-pcntl
 * and ext-posix.
 *
 * Every scenario but the first runs in a process of its own: a fork is refused once the
 * extension's runtime runs, and this process has started it long ago.
 */
class ProcessControlTest extends BaseTestCase
{
    /**
     * Prepended to every scenario: the autoloader, and a reap that gives a child five
     * seconds to exit.
     */
    protected const string PRELUDE = <<<'PHP'
    require $argv[1] . '/vendor/autoload.php';

    use SConcur\Process\Dto\ProcessExit;
    use SConcur\Process\ProcessControl;
    use SConcur\Process\ProcessSignalEnum;

    function waitFor(int $pid): ProcessExit
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            foreach (ProcessControl::reap([$pid]) as $exit) {
                return $exit;
            }

            usleep(10_000);
        }

        fwrite(STDERR, "child $pid did not exit\n");

        exit(1);
    }

    PHP;

    /**
     * A fork keeps only the calling thread, and the runtime runs threads of its own: a
     * child would inherit their locks without them. So once the runtime is up, the fork
     * is refused rather than attempted.
     */
    public function testAForkIsRefusedOnceTheRuntimeRuns(): void
    {
        $group = WaitGroup::create();

        $group->add(static fn() => Sleeper::usleep(microseconds: 1_000));

        $group->waitAll();

        $this->expectException(ForkRefusedException::class);

        ProcessControl::fork();
    }

    /**
     * The child's stdout and stderr are pipes the parent reads — `fwrite(STDOUT)` as well
     * as echo, because the pipe is put on fd 1 itself rather than STDOUT being reopened —
     * and its exit code comes back through the reap.
     */
    public function testTheChildsOutputAndExitCodeReachTheParent(): void
    {
        $answer = $this->runScenario(
            <<<'PHP'
            $fork = ProcessControl::fork();

            if ($fork->isChild()) {
                echo "echoed\n";
                fwrite(STDOUT, "written to STDOUT\n");
                fwrite(STDERR, "written to STDERR\n");

                ProcessControl::exit(7);
            }

            $exit = waitFor($fork->pid);

            echo json_encode([
                'stdout' => stream_get_contents($fork->stdout),
                'stderr' => stream_get_contents($fork->stderr),
                'code'   => $exit->exitCode,
                'signal' => $exit->signal,
            ]);
            PHP,
        );

        self::assertSame("echoed\nwritten to STDOUT\n", $answer['stdout']);
        self::assertSame("written to STDERR\n", $answer['stderr']);
        self::assertSame(7, $answer['code']);
        self::assertNull($answer['signal']);
    }

    public function testAKilledChildIsReapedWithTheSignalThatEndedIt(): void
    {
        $answer = $this->runScenario(
            <<<'PHP'
            $fork = ProcessControl::fork();

            if ($fork->isChild()) {
                while (true) {
                    usleep(10_000);
                }
            }

            $delivered = ProcessControl::kill($fork->pid, ProcessSignalEnum::Terminate);
            $exit      = waitFor($fork->pid);

            echo json_encode([
                'delivered'     => $delivered,
                'code'          => $exit->exitCode,
                'signal'        => $exit->signal,
                'deliveredAgain' => ProcessControl::kill($fork->pid, ProcessSignalEnum::Terminate),
            ]);
            PHP,
        );

        self::assertTrue($answer['delivered']);
        self::assertNull($answer['code']);
        // SIGTERM's number on Linux, where the suite runs.
        self::assertSame(15, $answer['signal']);
        self::assertFalse($answer['deliveredAgain'], 'a reaped child is no process to signal');
    }

    /**
     * A worker leaves through exit(), and whatever the parent registered or created before
     * the fork — a shutdown function, an object with a destructor — is the parent's: it
     * runs once, in the parent, not once more per worker.
     */
    public function testAChildRunsNoneOfTheParentsShutdownFunctionsOrDestructors(): void
    {
        $output = PhpScript::run(
            code: self::PRELUDE . <<<'PHP'
            class Probe
            {
                public function __destruct()
                {
                    echo 'DESTRUCT:', getmypid(), PHP_EOL;
                }
            }

            $probe = new Probe();

            register_shutdown_function(static function (): void {
                echo 'SHUTDOWN:', getmypid(), PHP_EOL;
            });

            $fork = ProcessControl::fork();

            if ($fork->isChild()) {
                ProcessControl::exit(0);
            }

            waitFor($fork->pid);

            echo 'CHILD-OUTPUT:', stream_get_contents($fork->stdout), PHP_EOL;
            echo 'PARENT:', getmypid(), PHP_EOL;
            PHP,
            arguments: [PhpScript::projectRoot()],
        );

        self::assertSame(1, preg_match('~PARENT:(\d+)~', $output, $parent), $output);
        self::assertSame(['DESTRUCT:' . $parent[1]], $this->linesStartingWith($output, 'DESTRUCT:'));
        self::assertSame(['SHUTDOWN:' . $parent[1]], $this->linesStartingWith($output, 'SHUTDOWN:'));
    }

    /**
     * A fatal error is the one exit a worker does not choose, and PHP's shutdown runs on
     * it. The parent's objects were marked destroyed at the fork, so their destructors
     * still stay the parent's; its shutdown functions do run in the child, on purpose —
     * that is how a framework reports a fatal error.
     */
    public function testAFatalErrorInTheChildLeavesTheParentsObjectsAlone(): void
    {
        $output = PhpScript::run(
            code: self::PRELUDE . <<<'PHP'
            class Probe
            {
                public function __destruct()
                {
                    echo 'DESTRUCT:', getmypid(), PHP_EOL;
                }
            }

            $probe = new Probe();

            register_shutdown_function(static function (): void {
                echo 'SHUTDOWN:', getmypid(), PHP_EOL;
            });

            $fork = ProcessControl::fork();

            if ($fork->isChild()) {
                echo 'CHILD:', getmypid(), PHP_EOL;

                missing_function();
            }

            $exit = waitFor($fork->pid);

            echo stream_get_contents($fork->stdout);
            echo 'CHILD-CODE:', $exit->exitCode, PHP_EOL;
            echo 'PARENT:', getmypid(), PHP_EOL;
            PHP,
            arguments: [PhpScript::projectRoot()],
        );

        self::assertSame(1, preg_match('~PARENT:(\d+)~', $output, $parent), $output);
        self::assertSame(1, preg_match('~CHILD:(\d+)~', $output, $child), $output);
        self::assertStringContainsString('CHILD-CODE:255', $output);
        self::assertSame(['DESTRUCT:' . $parent[1]], $this->linesStartingWith($output, 'DESTRUCT:'));
        self::assertSame(
            [
                'SHUTDOWN:' . $child[1],
                'SHUTDOWN:' . $parent[1],
            ],
            $this->linesStartingWith($output, 'SHUTDOWN:'),
        );
    }

    /**
     * The parent drew from mt_rand before forking, so every child would continue the same
     * sequence unless the generator is reseeded in each.
     */
    public function testChildrenDrawRandomNumbersOfTheirOwn(): void
    {
        $answer = $this->runScenario(
            <<<'PHP'
            mt_rand();

            $draws = [];

            for ($index = 0; $index < 3; ++$index) {
                $fork = ProcessControl::fork();

                if ($fork->isChild()) {
                    echo mt_rand();

                    ProcessControl::exit(0);
                }

                waitFor($fork->pid);

                $draws[] = stream_get_contents($fork->stdout);
            }

            echo json_encode($draws);
            PHP,
        );

        self::assertCount(3, array_unique($answer), 'children drew the same numbers: ' . implode(', ', $answer));
    }

    /**
     * Whatever the parent holds open — its lock, its log, its sockets — must not live on
     * in a worker, but a descriptor asked to be kept is kept. The parent's are pointed at
     * /dev/null rather than closed, so a stream still open on one writes nowhere instead of
     * into whatever file takes the number next.
     */
    public function testTheParentsDescriptorsReachTheChildAsDevNullUnlessKept(): void
    {
        $answer = $this->runScenario(
            <<<'PHP'
            $directory = sys_get_temp_dir() . '/sconcur-fork-fd-' . getmypid();

            @mkdir($directory);

            $dropped = fopen($directory . '/dropped', 'a');
            $kept    = fopen($directory . '/kept', 'a');

            $keptDescriptor = null;

            foreach (scandir('/proc/self/fd') as $entry) {
                if (ctype_digit($entry) && @readlink('/proc/self/fd/' . $entry) === $directory . '/kept') {
                    $keptDescriptor = (int) $entry;
                }
            }

            $fork = ProcessControl::fork(keepDescriptors: [$keptDescriptor]);

            if ($fork->isChild()) {
                fwrite($dropped, 'written by the child');
                fwrite($kept, 'written by the child');

                ProcessControl::exit(0);
            }

            waitFor($fork->pid);

            echo json_encode([
                'dropped' => file_get_contents($directory . '/dropped'),
                'kept'    => file_get_contents($directory . '/kept'),
            ]);

            unlink($directory . '/dropped');
            unlink($directory . '/kept');
            rmdir($directory);
            PHP,
        );

        self::assertSame('', $answer['dropped']);
        self::assertSame('written by the child', $answer['kept']);
    }

    public function testAWatchedSignalIsHandedOverOnce(): void
    {
        $answer = $this->runScenario(
            <<<'PHP'
            ProcessControl::watchSignals([ProcessSignalEnum::User1]);

            ProcessControl::kill(getmypid(), ProcessSignalEnum::User1);

            $first  = ProcessControl::takeSignals();
            $second = ProcessControl::takeSignals();

            ProcessControl::restoreSignals();

            echo json_encode([
                'first'  => array_map(static fn (ProcessSignalEnum $signal): string => $signal->value, $first),
                'second' => $second,
            ]);
            PHP,
        );

        self::assertSame(['USR1'], $answer['first']);
        self::assertSame([], $answer['second']);
    }

    /**
     * The parent's watching ends at the fork: the child gets back what was installed
     * before it, so a USR1 it receives does what USR1 does by default — end the process —
     * rather than land in a record nobody reads.
     */
    public function testAChildDoesNotInheritTheParentsWatching(): void
    {
        $answer = $this->runScenario(
            <<<'PHP'
            ProcessControl::watchSignals([ProcessSignalEnum::User1]);

            $fork = ProcessControl::fork();

            if ($fork->isChild()) {
                ProcessControl::kill(getmypid(), ProcessSignalEnum::User1);

                usleep(500_000);

                ProcessControl::exit(0);
            }

            $exit = waitFor($fork->pid);

            echo json_encode([
                'code'   => $exit->exitCode,
                'signal' => $exit->signal,
            ]);
            PHP,
        );

        self::assertNull($answer['code'], 'the child survived its USR1: it still had the parent\'s handler');
        // SIGUSR1's number on Linux, where the suite runs.
        self::assertSame(10, $answer['signal']);
    }

    public function testTheChildsParentPidIsTheForkingProcess(): void
    {
        $answer = $this->runScenario(
            <<<'PHP'
            $fork = ProcessControl::fork();

            if ($fork->isChild()) {
                echo ProcessControl::parentPid();

                ProcessControl::exit(0);
            }

            waitFor($fork->pid);

            echo json_encode([
                'parent'      => getmypid(),
                'childAnswer' => (int) stream_get_contents($fork->stdout),
            ]);
            PHP,
        );

        self::assertSame($answer['parent'], $answer['childAnswer']);
    }

    /**
     * The liveness pipe: the child marks, the parent reads the mark.
     */
    public function testTheHeartbeatPipeCarriesTheChildsMark(): void
    {
        $answer = $this->runScenario(
            <<<'PHP'
            $fork = ProcessControl::fork(heartbeat: true);

            if ($fork->isChild()) {
                fwrite($fork->heartbeat, "\x01");

                ProcessControl::exit(0);
            }

            waitFor($fork->pid);

            echo json_encode(['mark' => bin2hex(stream_get_contents($fork->heartbeat))]);
            PHP,
        );

        self::assertSame('01', $answer['mark']);
    }

    /**
     * What the child printed into an output buffer is written before it leaves; what the
     * parent had buffered before the fork is the parent's, written once, by the parent.
     */
    public function testBufferedOutputIsWrittenOnceByTheProcessThatBufferedIt(): void
    {
        $output = PhpScript::run(
            code: self::PRELUDE . <<<'PHP'
            ob_start();

            echo 'BUFFERED-BY-THE-PARENT', PHP_EOL;

            $fork = ProcessControl::fork();

            if ($fork->isChild()) {
                ob_start();

                echo 'BUFFERED-BY-THE-CHILD', PHP_EOL;

                ProcessControl::exit(0);
            }

            waitFor($fork->pid);

            echo stream_get_contents($fork->stdout);

            ob_end_flush();
            PHP,
            arguments: [PhpScript::projectRoot()],
        );

        self::assertSame(1, substr_count($output, 'BUFFERED-BY-THE-PARENT'), $output);
        self::assertSame(1, substr_count($output, 'BUFFERED-BY-THE-CHILD'), $output);
    }

    public function testAProcessThatNeverStartedTheRuntimeRunsOneThread(): void
    {
        $answer = $this->runScenario(
            <<<'PHP'
            $before = ProcessControl::threadCount();

            SConcur\Features\Sleeper\Sleeper::usleep(microseconds: 1_000);

            echo json_encode([
                'before' => $before,
                'after'  => ProcessControl::threadCount(),
            ]);
            PHP,
        );

        if ($answer['before'] === null) {
            self::markTestSkipped('this platform cannot count threads cheaply');
        }

        self::assertSame(1, $answer['before']);
        self::assertGreaterThan(1, $answer['after']);
    }

    /**
     * Runs a scenario that ends by echoing one JSON document, and answers it decoded.
     *
     * @return array<array-key, mixed>
     */
    protected function runScenario(string $code): array
    {
        $output = PhpScript::run(
            code: self::PRELUDE . $code,
            arguments: [PhpScript::projectRoot()],
        );

        $answer = json_decode($output, true);

        self::assertIsArray($answer, 'the scenario printed no JSON: ' . $output);

        return $answer;
    }

    /**
     * @return list<string>
     */
    protected function linesStartingWith(string $output, string $prefix): array
    {
        return array_values(
            array_filter(
                array_map('trim', explode(PHP_EOL, $output)),
                static fn(string $line): bool => str_starts_with($line, $prefix),
            ),
        );
    }
}
