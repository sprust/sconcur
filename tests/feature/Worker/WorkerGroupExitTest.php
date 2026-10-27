<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\Worker\ExitingWorkerGroup;
use SConcur\Tests\Impl\Worker\ProcOpenWorkerProcess;
use SConcur\Worker\MasterLogger;
use SConcur\Worker\RestartPolicy;

/**
 * What the group makes of a worker's exit when the worker's last marks are still on its
 * heartbeat pipe: a tick reads the pipe before it asks whether the worker is running, so
 * a worker that marks itself and exits in between is reaped with its marks unread.
 */
class WorkerGroupExitTest extends TestCase
{
    protected string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/sc-group-exit-' . uniqid('', true);

        mkdir($this->directory, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory . '/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    public function testAWorkerThatMarkedItsLimitAndExitedIsReplacedUnderOnFailure(): void
    {
        $group = ExitingWorkerGroup::make(
            logger: $this->logger(),
            restartPolicy: RestartPolicy::OnFailure,
        );

        $group->exited($this->exitedWorker('"\x02\x03"'));

        self::assertStringContainsString('at its limit; restarting', $this->logText());
        self::assertStringNotContainsString('not restarting', $this->logText());
    }

    /**
     * Up, then failed: a crash, not a failure to start, so the group did come up and a
     * broken deploy is not what ends the master.
     */
    public function testAWorkerThatSaidItWasUpAndFailedIsNotAFailedStart(): void
    {
        $group = ExitingWorkerGroup::make(
            logger: $this->logger(),
            restartPolicy: RestartPolicy::Always,
        );

        $group->exited($this->exitedWorker('"\x02"', exitCode: 1));

        self::assertFalse($group->neverCameUp(), $this->logText());
    }

    protected function exitedWorker(string $marks, int $exitCode = 0): ProcOpenWorkerProcess
    {
        $process = new ProcOpenWorkerProcess(
            command: [PHP_BINARY, '-r', 'fwrite(fopen("php://fd/3", "w"), ' . $marks . '); exit(' . $exitCode . ');'],
        );

        $deadline = microtime(true) + 5.0;

        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }

        self::assertFalse($process->isRunning(), 'the worker must exit');

        return $process;
    }

    protected function logger(): MasterLogger
    {
        return new MasterLogger($this->directory, 'sconcur-test-master', 3, 12345);
    }

    protected function logText(): string
    {
        $text = '';

        foreach ((array) glob($this->directory . '/*') as $file) {
            if (is_string($file)) {
                $text .= (string) file_get_contents($file);
            }
        }

        return $text;
    }
}
