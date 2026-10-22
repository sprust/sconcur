<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Process\ProcessControl;
use SConcur\Tests\Impl\PhpScript;
use SConcur\Tests\Impl\Worker\SupervisedScript;
use SConcur\Worker\ExecWorkerProcess;

/**
 * The worker's half of the channel to its master: the marks Scheduler::markAlive() and
 * markReady() write through the extension, which holds the worker's end of the pipe. Each
 * scenario is a worker of its own — exec'd, the way a master starts one, or forked — since
 * the pipe is found as a process starts, or handed over by its fork.
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
     * What the master's end reads from a worker it exec'd: the pipe on HEARTBEAT_FD, its
     * number in the environment, as WorkerGroup starts one.
     */
    public function testTheMasterReadsTheMarksOfAWorkerItExecd(): void
    {
        $process = new ExecWorkerProcess(
            command: [
                PHP_BINARY,
                '-d',
                'extension=' . PhpScript::extensionPath(),
                '-r',
                self::PRELUDE . <<<'PHP'
                Scheduler::get()->markAlive();
                Scheduler::get()->markReady();

                usleep(200_000);
                PHP,
                PhpScript::projectRoot(),
            ],
            cwd: sys_get_temp_dir(),
            env: [
                ...getenv(),
                ProcessControl::HEARTBEAT_FD_ENVIRONMENT_NAME => (string) ProcessControl::HEARTBEAT_FD,
            ],
        );

        try {
            $deadline = microtime(true) + 5.0;

            while (microtime(true) < $deadline && !$process->isReady()) {
                $process->drainHeartbeat();

                usleep(20_000);
            }

            self::assertTrue($process->isReady(), 'the worker said it is up');
            self::assertNotNull($process->heartbeatAgeSeconds(), 'the worker said its thread is alive');
        } finally {
            $deadline = microtime(true) + 5.0;

            while (microtime(true) < $deadline && $process->isRunning()) {
                usleep(20_000);
            }

            $process->close();
        }
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

                // What the fork server's process found for itself: no master, asked and kept,
                // and an alive mark that would throttle the worker's first one.
                Scheduler::get()->markAlive();

                $script = (string) tempnam(sys_get_temp_dir(), 'heartbeat-worker-');

                file_put_contents($script, <<<'WORKER'
                <?php
                SConcur\Scheduler\Scheduler::get()->markAlive();
                SConcur\Scheduler\Scheduler::get()->markReady();

                echo json_encode(SConcur\Process\ProcessControl::hasHeartbeat());
                WORKER);

                $fork = ProcessControl::fork(heartbeat: true);

                if ($fork->isChild()) {
                    ForkedWorker::run(
                        title: 'heartbeat-test-worker',
                        cwd: sys_get_temp_dir(),
                        environment: [],
                        argv: [$script],
                    );
                }

                $deadline = microtime(true) + 5.0;

                while (ProcessControl::reap([$fork->pid]) === [] && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                unlink($script);

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
     * marks it too, and so does one that asks again long after the variable that named the
     * pipe left the environment — what a process that rebuilt its scheduler used to lose.
     */
    public function testEverySchedulerOfAWorkerMarksItsPipe(): void
    {
        $run = SupervisedScript::run(
            code: self::PRELUDE . <<<'PHP'
            new Scheduler()->markAlive();
            new Scheduler()->markReady();

            Scheduler::forgetSupervision();

            new Scheduler()->markAlive();

            echo json_encode(getenv(ProcessControl::HEARTBEAT_FD_ENVIRONMENT_NAME));
            PHP,
            arguments: [PhpScript::projectRoot()],
        );

        self::assertSame("\x01\x02\x01", $run['marks'], $run['output']);
        self::assertSame('false', $run['output'], 'the variable is still in the environment');
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
     * A variable naming a descriptor that is not a pipe open for writing did not come from a
     * master: nothing is written there, and the variable is consumed all the same.
     */
    public function testAVariableNamingNoPipeIsRefused(): void
    {
        $run = SupervisedScript::run(
            code: self::PRELUDE . <<<'PHP'
            Scheduler::get()->markAlive();

            echo json_encode([
                'hasHeartbeat' => ProcessControl::hasHeartbeat(),
                'variable'     => getenv(ProcessControl::HEARTBEAT_FD_ENVIRONMENT_NAME),
                'server'       => $_SERVER[ProcessControl::HEARTBEAT_FD_ENVIRONMENT_NAME] ?? null,
            ]);
            PHP,
            arguments: [PhpScript::projectRoot()],
            heartbeat: false,
            environment: [ProcessControl::HEARTBEAT_FD_ENVIRONMENT_NAME => (string) ProcessControl::HEARTBEAT_FD],
        );

        self::assertSame(
            [
                'hasHeartbeat' => false,
                'variable'     => false,
                'server'       => null,
            ],
            $this->decode($run['output']),
        );
    }

    /**
     * Nothing a worker starts inherits its heartbeat: neither the pipe nor the variable
     * naming it, from the worker's first line on — a program run by its bootstrap included,
     * before the worker has marked anything. Either would let a SConcur program among them
     * mark the worker alive, the one reading a watchdog must have from the worker itself.
     */
    public function testNothingAWorkerStartsInheritsItsHeartbeat(): void
    {
        $run = SupervisedScript::run(
            code: <<<'PHP'
            $heartbeat = (string) readlink('/proc/self/fd/3');

            $listing = <<<'LISTING'
            $descriptors = [];

            foreach (scandir('/proc/self/fd') as $entry) {
                $descriptors[] = (string) @readlink('/proc/self/fd/' . $entry);
            }

            echo json_encode([
                'descriptors' => $descriptors,
                'variable'    => getenv('SCONCUR_HEARTBEAT_FD'),
            ]);
            LISTING;

            $started = json_decode(
                (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($listing)),
                true,
            );

            require $argv[1] . '/vendor/autoload.php';

            // The pipe still works for the worker itself.
            SConcur\Scheduler\Scheduler::get()->markAlive();

            echo json_encode([
                'heartbeat' => $heartbeat,
                'started'   => $started,
            ]);
            PHP,
            arguments: [PhpScript::projectRoot()],
        );

        $answer = $this->decode($run['output']);

        self::assertStringStartsWith('pipe:[', $answer['heartbeat'], 'the worker was given no pipe');
        self::assertNotEmpty($answer['started']['descriptors'], 'the started program listed nothing');
        self::assertNotContains(
            $answer['heartbeat'],
            $answer['started']['descriptors'],
            'a program the worker started holds its heartbeat pipe',
        );
        self::assertFalse(
            $answer['started']['variable'],
            'a program the worker started finds the variable naming its heartbeat',
        );
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
