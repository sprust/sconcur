<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\PhpScript;
use SConcur\Tests\Impl\Worker\SupervisedScript;

/**
 * The worker's half of the channel to its master: the marks Scheduler::markAlive() and
 * markReady() write through the extension, which holds the worker's end of the pipe. Each
 * scenario is a worker of its own, forked, since the pipe is handed over by its fork.
 */
class HeartbeatTest extends TestCase
{
    /** Prepended to every worker script: the autoloader, and what the scripts use. */
    protected const string PRELUDE = <<<'PHP'
    require $argv[1] . '/vendor/autoload.php';

    use SConcur\Process\HeartbeatMarkEnum;
    use SConcur\Process\ProcessControl;
    use SConcur\Scheduler\Scheduler;

    PHP;

    /**
     * What the master's end reads from a worker it forked: the pipe the fork made, read by
     * ForkedWorkerProcess as WorkerGroup reads it. The master side runs in a php of its own:
     * a test process runs the runtime, and cannot fork.
     */
    public function testTheMasterReadsTheMarksOfAWorkerItForked(): void
    {
        $answer = $this->decode(
            PhpScript::run(
                code: self::PRELUDE . <<<'PHP'
                use SConcur\Worker\ForkedWorkerProcess;

                $process = new ForkedWorkerProcess(
                    child: static function (): never {
                        Scheduler::forgetSupervision();
                        Scheduler::get()->markAlive();
                        Scheduler::get()->markReady();

                        usleep(200_000);

                        ProcessControl::exit(0);
                    },
                );

                $deadline = microtime(true) + 5.0;

                while (microtime(true) < $deadline && !$process->isReady()) {
                    $process->drainHeartbeat();

                    usleep(20_000);
                }

                echo json_encode([
                    'ready' => $process->isReady(),
                    'alive' => $process->heartbeatAgeSeconds() !== null,
                ]);

                $deadline = microtime(true) + 5.0;

                while (microtime(true) < $deadline && $process->isRunning()) {
                    usleep(20_000);
                }

                $process->close();
                PHP,
                arguments: [PhpScript::projectRoot()],
            ),
        );

        self::assertTrue($answer['ready'], 'the worker said it is up');
        self::assertTrue($answer['alive'], 'the worker said its thread is alive');
    }

    /**
     * A forked worker writes to the pipe its fork made, whatever the process it was forked
     * from had asked and marked before: ForkedWorker has its scheduler ask afresh, and the
     * extension answers with the child's own end.
     */
    public function testAForkedWorkerMarksThePipeItsForkMade(): void
    {
        $answer = $this->decode(
            PhpScript::run(
                code: self::PRELUDE . <<<'PHP'
                use SConcur\Worker\ForkedWorker;

                // What the master's process found for itself: no master, asked and kept,
                // and an alive mark that would throttle the worker's first one.
                Scheduler::get()->markAlive();

                $fork = ProcessControl::fork(heartbeat: true);

                if ($fork->isChild()) {
                    ForkedWorker::run(
                        title: 'heartbeat-test-worker',
                        cwd: sys_get_temp_dir(),
                        environment: [],
                        role: static function (): void {
                            Scheduler::get()->markAlive();
                            Scheduler::get()->markReady();

                            echo json_encode(ProcessControl::hasHeartbeat());
                        },
                        context: new SConcur\Worker\WorkerContext(group: 'test', slot: 0, masterPid: getmypid()),
                    );
                }

                $deadline = microtime(true) + 5.0;

                while (ProcessControl::reap([$fork->pid]) === [] && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                echo json_encode([
                    'parentHasHeartbeat' => ProcessControl::hasHeartbeat(),
                    'workerHasHeartbeat' => json_decode((string) stream_get_contents($fork->stdout)),
                    'marks'              => bin2hex((string) stream_get_contents($fork->heartbeat)),
                ]);
                PHP,
                arguments: [PhpScript::projectRoot()],
            ),
        );

        self::assertFalse($answer['parentHasHeartbeat']);
        self::assertTrue($answer['workerHasHeartbeat']);
        self::assertSame('0102', $answer['marks'], 'alive, then ready, on the pipe the fork made');
    }

    /**
     * A fork asked for no pipe has none, even when the process it was forked from has one:
     * that one is the parent's, and its marks are the parent's to write.
     */
    public function testAForkHandedNoPipeHasNoneWhateverItsParentHas(): void
    {
        $run = SupervisedScript::run(
            code: self::PRELUDE . <<<'PHP'
            $fork = ProcessControl::fork();

            if ($fork->isChild()) {
                echo json_encode([
                    'hasHeartbeat' => ProcessControl::hasHeartbeat(),
                    'marked'       => ProcessControl::markHeartbeat(HeartbeatMarkEnum::Alive),
                ]);

                ProcessControl::exit(0);
            }

            $deadline = microtime(true) + 5.0;

            while (ProcessControl::reap([$fork->pid]) === [] && microtime(true) < $deadline) {
                usleep(10_000);
            }

            Scheduler::get()->markReady();

            echo json_encode([
                'parentHasHeartbeat' => ProcessControl::hasHeartbeat(),
                'child'              => json_decode((string) stream_get_contents($fork->stdout), true),
            ]);
            PHP,
            arguments: [PhpScript::projectRoot()],
        );

        $answer = $this->decode($run['output']);

        self::assertTrue($answer['parentHasHeartbeat']);
        self::assertSame(
            [
                'hasHeartbeat' => false,
                'marked'       => false,
            ],
            $answer['child'],
        );
        self::assertSame("\x02", $run['marks'], 'only the parent marked its pipe');
    }

    /**
     * Alive is throttled to two marks a second, and ready is said once: a script that starts
     * a second server after the first ended was up the first time.
     */
    public function testAliveIsThrottledAndReadyIsSaidOnce(): void
    {
        $run = SupervisedScript::run(
            code: self::PRELUDE . <<<'PHP'
            Scheduler::get()->markAlive();
            Scheduler::get()->markAlive();
            Scheduler::get()->markReady();
            Scheduler::get()->markReady();

            usleep(600_000);

            Scheduler::get()->markAlive();
            PHP,
            arguments: [PhpScript::projectRoot()],
        );

        self::assertSame("\x01\x02\x01", $run['marks'], $run['output']);
    }

    /**
     * The pipe is the process's, not a scheduler's: a second one built in the same worker
     * marks it too, and so does one that asks again after forgetting what it was told.
     */
    public function testEverySchedulerOfAWorkerMarksItsPipe(): void
    {
        $run = SupervisedScript::run(
            code: self::PRELUDE . <<<'PHP'
            new Scheduler()->markAlive();
            new Scheduler()->markReady();

            Scheduler::forgetSupervision();

            new Scheduler()->markAlive();
            PHP,
            arguments: [PhpScript::projectRoot()],
        );

        self::assertSame("\x01\x02\x01", $run['marks'], $run['output']);
    }

    /**
     * Without a master the marks go nowhere and nothing fails, and the extension says so.
     */
    public function testWithoutAMasterThereIsNoHeartbeat(): void
    {
        $run = SupervisedScript::run(
            code: self::PRELUDE . <<<'PHP'
            Scheduler::get()->markAlive();
            Scheduler::get()->markReady();

            echo json_encode([
                'hasHeartbeat' => ProcessControl::hasHeartbeat(),
                'marked'       => ProcessControl::markHeartbeat(HeartbeatMarkEnum::Alive),
            ]);
            PHP,
            arguments: [PhpScript::projectRoot()],
            heartbeat: false,
        );

        self::assertSame(
            [
                'hasHeartbeat' => false,
                'marked'       => false,
            ],
            $this->decode($run['output']),
        );
    }

    /**
     * Nothing a worker starts inherits its heartbeat. A SConcur program among them would
     * otherwise mark the worker alive, the one reading a watchdog must have from the worker
     * itself. The pipe the fork made is close-on-exec, so a program the worker starts holds
     * its stdio and nothing else.
     */
    public function testNothingAWorkerStartsInheritsItsHeartbeat(): void
    {
        $run = SupervisedScript::run(
            code: self::PRELUDE . <<<'PHP'
            $listing = <<<'LISTING'
            $descriptors = [];

            foreach (scandir('/proc/self/fd') as $entry) {
                if ($entry !== '.' && $entry !== '..' && (int) $entry > 2) {
                    $descriptors[] = $entry . ' -> ' . (string) @readlink('/proc/self/fd/' . $entry);
                }
            }

            echo json_encode($descriptors);
            LISTING;

            $started = json_decode(
                (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($listing)),
                true,
            );

            // The pipe still works for the worker itself.
            Scheduler::get()->markAlive();

            echo json_encode([
                'hasHeartbeat' => ProcessControl::hasHeartbeat(),
                'started'      => $started,
            ]);
            PHP,
            arguments: [PhpScript::projectRoot()],
        );

        $answer = $this->decode($run['output']);

        self::assertTrue($answer['hasHeartbeat'], 'the worker was given no pipe');
        self::assertIsArray($answer['started'], 'the started program listed nothing');

        // Its own listing of /proc/self/fd is the one descriptor above stderr it holds.
        self::assertCount(1, $answer['started'], 'a program the worker started holds more than its stdio: ' . $run['output']);
        self::assertSame("\x01", $run['marks']);
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function decode(string $output): array
    {
        $answer = json_decode($output, true);

        self::assertIsArray($answer, 'the scenario printed no JSON: ' . $output);

        return $answer;
    }
}
