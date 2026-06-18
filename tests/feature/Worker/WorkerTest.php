<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Worker\Worker;

/**
 * Unit coverage of the Worker helper: it reads the master-injected metadata from the
 * argv flags (--sconcurMasterPid / --sconcurWorkerIndex), not the environment, so a
 * worker receives everything through a single channel.
 */
class WorkerTest extends TestCase
{
    /** @var list<string> */
    private array $originalArgv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalArgv = $_SERVER['argv'] ?? [];
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->originalArgv;

        parent::tearDown();
    }

    public function testReadsMasterPidAndIndexFromArgv(): void
    {
        $_SERVER['argv'] = [
            'worker.php',
            '0.0.0.0:8080',
            '--reusePort=1',
            Worker::MASTER_PID_ARG . '=12345',
            Worker::INDEX_ARG . '=3',
        ];

        self::assertSame(12345, Worker::masterPid());
        self::assertSame(3, Worker::index());
    }

    public function testMasterPidIsNullAndIndexZeroWhenFlagsAbsent(): void
    {
        // Standalone run (no master): the orphan check must be disabled (null) and
        // the index defaults to 0.
        $_SERVER['argv'] = ['worker.php', '0.0.0.0:8080'];

        self::assertNull(Worker::masterPid());
        self::assertSame(0, Worker::index());
    }

    public function testMasterPidIsNullWhenFlagHasEmptyValue(): void
    {
        $_SERVER['argv'] = ['worker.php', Worker::MASTER_PID_ARG . '='];

        self::assertNull(Worker::masterPid());
    }
}
