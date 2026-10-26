<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Worker\MasterControl;
use SConcur\Worker\MasterGroupState;
use SConcur\Worker\MasterLock;
use SConcur\Worker\MasterState;
use SConcur\Worker\MasterStateFile;

/**
 * What another process learns of a master, and how it stops one, by its runtime directory
 * and name. A master is stood in for by its lock, held here: a second flock from the same
 * process contends, which is all a running master is to MasterControl.
 */
class MasterControlTest extends TestCase
{
    protected string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sc-master-control-' . uniqid('', true);

        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testNoMasterRunsWithoutItsLock(): void
    {
        $control = $this->control();

        self::assertFalse($control->isRunning());
        self::assertNull($control->state());
        self::assertTrue($control->stop(), 'stopping a master that does not run is done at once');
    }

    public function testAHeldLockIsARunningMasterWithOrWithoutState(): void
    {
        $control = $this->control();
        $lock    = new MasterLock(path: $control->lockPath());

        $lock->acquire();

        try {
            self::assertTrue($control->isRunning());
            self::assertNull($control->state(), 'it has not written its state yet');

            new MasterStateFile(path: $control->stateFilePath())->write(new MasterState(
                pid: 4711,
                startedAt: 1.5,
                workerCount: 3,
                groups: [new MasterGroupState(name: 'http', workerCount: 3)],
            ));

            $state = $control->state();

            self::assertNotNull($state);
            self::assertSame(4711, $state->pid);
            self::assertSame(3, $state->workerCount);
            self::assertSame('http', $state->groups[0]->name);
        } finally {
            $lock->release();
        }

        self::assertFalse($control->isRunning(), 'a lock let go is a master gone');
    }

    /**
     * Stopping removes the state file, which the master watches for, and waits for the lock:
     * a master that does not leave in time is reported as still running.
     */
    public function testStopAsksByRemovingTheStateFileAndWaitsForTheLock(): void
    {
        $control = $this->control();
        $lock    = new MasterLock(path: $control->lockPath());

        $lock->acquire();

        try {
            new MasterStateFile(path: $control->stateFilePath())->write(new MasterState(
                pid: 4711,
                startedAt: 1.5,
                workerCount: 1,
            ));

            self::assertFalse($control->stop(timeoutMs: 300), 'the lock is still held');
            self::assertFileDoesNotExist($control->stateFilePath(), 'the stop was asked for');
        } finally {
            $lock->release();
        }

        self::assertTrue($control->stop(timeoutMs: 300));
    }

    protected function control(): MasterControl
    {
        return new MasterControl(
            runtimeDir: $this->directory,
            name: 'held',
        );
    }
}
