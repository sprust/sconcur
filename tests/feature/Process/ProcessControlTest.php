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

    use SConcur\Exceptions\Process\ProcessException;
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
     * is refused rather than attempted, and the refusal says how many threads it found —
     * Linux, where the suite runs, can count them.
     */
    public function testAForkIsRefusedOnceTheRuntimeRuns(): void
    {
        $group = WaitGroup::create();

        $group->add(static fn() => Sleeper::usleep(microseconds: 1_000));

        $group->waitAll();

        $this->expectException(ForkRefusedException::class);
        $this->expectExceptionMessageMatches('~runtime already runs in this process \(\d+ threads\)~');

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

            // Not the child's pid again: once reaped, it may already be another process's.
            // Pids wrap around at pid_max, so no process has that one.
            $unknownPid = (int) file_get_contents('/proc/sys/kernel/pid_max');

            echo json_encode([
                'delivered'  => $delivered,
                'code'       => $exit->exitCode,
                'signal'     => $exit->signal,
                'unknownPid' => ProcessControl::kill($unknownPid, ProcessSignalEnum::Child),
            ]);
            PHP,
        );

        self::assertTrue($answer['delivered']);
        self::assertNull($answer['code']);
        // SIGTERM's number on Linux, where the suite runs.
        self::assertSame(15, $answer['signal']);
        self::assertFalse($answer['unknownPid'], 'a pid no process has is no process to signal');
    }

    /**
     * 0 and -1 name no single process: to kill(2) they are the caller's whole process
     * group — a master included — and every process it may signal, to waitpid(2) any
     * child, reported under the pid asked for. Both are refused before anything is
     * signalled or reaped, and a list with one of them in it is refused whole: the child
     * ahead of it is still there for the next reap. The signal is CHLD, which nothing acts
     * on by default, so a regression signals nobody that would mind.
     */
    public function testAPidThatNamesNoSingleProcessIsRefused(): void
    {
        $answer = $this->runScenario(
            <<<'PHP'
            $refusals = [];

            foreach ([0, -1] as $pid) {
                $attempts = [
                    'kill' => static fn() => ProcessControl::kill($pid, ProcessSignalEnum::Child),
                    'reap' => static fn() => ProcessControl::reap([$pid]),
                ];

                foreach ($attempts as $call => $attempt) {
                    try {
                        $attempt();

                        $refusals[] = "$call($pid) was answered";
                    } catch (ProcessException $exception) {
                        $refusals[] = $exception->getMessage();
                    }
                }
            }

            $fork = ProcessControl::fork();

            if ($fork->isChild()) {
                ProcessControl::exit(3);
            }

            // Exited by now, so a reap that went ahead would take it.
            usleep(200_000);

            try {
                ProcessControl::reap([$fork->pid, 0]);

                $refusals[] = 'the list was answered';
            } catch (ProcessException $exception) {
                $refusals[] = $exception->getMessage();
            }

            echo json_encode([
                'refusals' => $refusals,
                'code'     => waitFor($fork->pid)->exitCode,
            ]);
            PHP,
        );

        self::assertSame(
            [
                'process: pid 0 is not a child',
                'process: pid 0 is not a child',
                'process: pid -1 is not a child',
                'process: pid -1 is not a child',
                'process: pid 0 is not a child',
            ],
            $answer['refusals'],
        );
        self::assertSame(3, $answer['code'], 'the refused list reaped the child ahead of the bad pid');
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

    /**
     * A child that cannot point the parent's descriptors at /dev/null must not run on with
     * them: it says why on stderr and exits with 71 (EX_OSERR) before a line of PHP runs in
     * it. Here the parent holds every descriptor it may open, so the child has none left
     * for /dev/null.
     */
    public function testAChildThatCannotBeIsolatedExitsBeforeRunningAnyPhp(): void
    {
        $output = PhpScript::run(
            code: self::PRELUDE . <<<'PHP'
            // Loaded while a file can still be opened: what the fork and the reap use.
            ProcessControl::reap([]);
            class_exists(SConcur\Process\Dto\ForkResult::class);
            class_exists(ProcessExit::class);

            $held = [];

            while (($handle = @fopen('/dev/null', 'r')) !== false) {
                $held[] = $handle;
            }

            $fork = ProcessControl::fork(captureOutput: false);

            if ($fork->isChild()) {
                echo 'CHILD-RAN', PHP_EOL;

                ProcessControl::exit(0);
            }

            $exit = waitFor($fork->pid);

            echo 'CHILD-CODE:', $exit->exitCode, PHP_EOL;
            PHP,
            arguments: [PhpScript::projectRoot()],
            maxOpenDescriptors: 64,
        );

        self::assertStringContainsString('CHILD-CODE:71', $output);
        self::assertStringContainsString('a forked child cannot be isolated from its parent', $output);
        self::assertStringNotContainsString('CHILD-RAN', $output);
    }

    public function testAWatchedSignalIsHandedOverOnce(): void
    {
        $answer = $this->runScenario(
            <<<'PHP'
            ProcessControl::watchSignals([ProcessSignalEnum::Terminate]);

            ProcessControl::kill(getmypid(), ProcessSignalEnum::Terminate);

            $first  = ProcessControl::takeSignals();
            $second = ProcessControl::takeSignals();

            ProcessControl::restoreSignals();

            echo json_encode([
                'first'  => array_map(static fn (ProcessSignalEnum $signal): string => $signal->value, $first),
                'second' => $second,
            ]);
            PHP,
        );

        self::assertSame(['TERM'], $answer['first']);
        self::assertSame([], $answer['second']);
    }

    /**
     * The parent's watching ends at the fork: the child gets back what was installed
     * before it, so a TERM it receives does what TERM does by default — end the process —
     * rather than land in a record nobody reads.
     */
    public function testAChildDoesNotInheritTheParentsWatching(): void
    {
        $answer = $this->runScenario(
            <<<'PHP'
            ProcessControl::watchSignals([ProcessSignalEnum::Terminate]);

            $fork = ProcessControl::fork();

            if ($fork->isChild()) {
                ProcessControl::kill(getmypid(), ProcessSignalEnum::Terminate);

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

        self::assertNull($answer['code'], 'the child survived its TERM: it still had the parent\'s handler');
        // SIGTERM's number on Linux, where the suite runs.
        self::assertSame(15, $answer['signal']);
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
     * The heartbeat pipe: the child marks through the extension, which holds its end — the
     * child is handed no stream on it — and the parent reads the mark.
     */
    public function testTheHeartbeatPipeCarriesTheChildsMark(): void
    {
        $answer = $this->runScenario(
            <<<'PHP'
            $fork = ProcessControl::fork(heartbeat: true);

            if ($fork->isChild()) {
                echo json_encode([
                    'stream' => $fork->heartbeat,
                    'marked' => ProcessControl::markHeartbeat(SConcur\Process\HeartbeatMarkEnum::Alive),
                ]);

                ProcessControl::exit(0);
            }

            waitFor($fork->pid);

            echo json_encode([
                'child' => json_decode(stream_get_contents($fork->stdout), true),
                'mark'  => bin2hex(stream_get_contents($fork->heartbeat)),
            ]);
            PHP,
        );

        self::assertSame(
            [
                'stream' => null,
                'marked' => true,
            ],
            $answer['child'],
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

    /**
     * The pipes a fork makes reach nothing either side execs. The child's end of its
     * heartbeat stays with the extension, close-on-exec: a program a worker runs that held it
     * could mark the worker alive. The parent's ends are streams, which php://fd opens on a
     * copy without close-on-exec, and the extension puts the flag back: left off, a program
     * the parent runs would hold the read ends of the child's pipes.
     */
    public function testThePipesOfAForkReachNothingEitherSideExecs(): void
    {
        $answer = $this->runScenario(
            <<<'PHP'
            // The inodes of the pipes a php started from this process has open.
            function pipesOfAnExec(): array
            {
                $listing = <<<'LISTING'
                foreach (scandir('/proc/self/fd') as $entry) {
                    if (preg_match('~^pipe:\[(\d+)\]$~', (string) @readlink('/proc/self/fd/' . $entry), $match)) {
                        echo $match[1], PHP_EOL;
                    }
                }
                LISTING;

                $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($listing));

                return array_map('intval', array_values(array_filter(explode(PHP_EOL, $output))));
            }

            $fork = ProcessControl::fork(heartbeat: true);

            if ($fork->isChild()) {
                echo json_encode(pipesOfAnExec());

                ProcessControl::exit(0);
            }

            waitFor($fork->pid);

            echo json_encode([
                // The two ends of a pipe share its inode.
                'childHeartbeat' => fstat($fork->heartbeat)['ino'],
                'childExec'      => json_decode(stream_get_contents($fork->stdout), true),
                'parentEnds'     => [
                    fstat($fork->stdout)['ino'],
                    fstat($fork->stderr)['ino'],
                    fstat($fork->heartbeat)['ino'],
                ],
                'parentExec'     => pipesOfAnExec(),
            ]);
            PHP,
        );

        // The child's stderr is a pipe it hands on, so an empty listing is a broken one.
        self::assertNotEmpty($answer['childExec'], 'the exec listed no pipes at all');
        self::assertNotContains(
            $answer['childHeartbeat'],
            $answer['childExec'],
            'a program the child ran holds its heartbeat',
        );

        foreach ($answer['parentEnds'] as $inode) {
            self::assertNotContains(
                $inode,
                $answer['parentExec'],
                'a program the parent ran holds a pipe of its child',
            );
        }
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
