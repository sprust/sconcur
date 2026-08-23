<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Worker\LogTarget;
use SConcur\Worker\MasterLogger;
use SConcur\Worker\RestartPolicy;
use SConcur\Worker\WorkerGroup;
use SConcur\Worker\WorkerGroupConfig;

/**
 * Retirement is not one-way. A group removed from the config drains rather than stopping
 * at once, and an operator who puts it back before that finishes means the pool they still
 * see running — so the master has to be able to take it back out of retirement.
 *
 * Tested on the group itself rather than through a running master: the window is however
 * long the drain takes, which on a healthy worker is a few milliseconds, and a test racing
 * that would pass for the wrong reason more often than not.
 */
class WorkerGroupRetirementTest extends BaseTestCase
{
    public function testAGroupCanBeTakenBackOutOfRetirement(): void
    {
        $group = $this->group();

        self::assertFalse($group->isRetiring());

        $group->retire();

        self::assertTrue($group->isRetiring());

        $group->unretire();

        self::assertFalse($group->isRetiring(), 'a group put back into the config must spawn again');
    }

    /**
     * A retiring group spawns nothing, which is what makes taking it out of retirement
     * necessary rather than cosmetic: without it the slots stay empty and the pool is
     * dropped on the next tick.
     */
    public function testARetiringGroupFillsNoSlots(): void
    {
        $group = $this->group();

        $group->retire();

        $group->spawnAll();

        self::assertSame(0, $group->aliveSlotCount());
        self::assertTrue($group->allSlotsEmpty());
    }

    protected function group(): WorkerGroup
    {
        return new WorkerGroup(
            config: new WorkerGroupConfig(
                name: 'retiring',
                workerScript: __FILE__,
                workerCount: 1,
                phpBinary: PHP_BINARY,
                phpArgs: [],
                workerArgs: [],
                env: [],
                restartPolicy: RestartPolicy::Never,
                shutdownTimeoutMs: 1_000,
                restartBackoffMs: 10,
                maxRestartBackoffMs: 10,
                server: [],
            ),
            logger: new MasterLogger(
                logDir: sys_get_temp_dir(),
                name: 'sconcur-test-retirement',
                rotateDays: 1,
                masterPid: getmypid() === false ? 0 : getmypid(),
                logTo: LogTarget::Stdout,
            ),
            masterPid: getmypid() === false ? 0 : getmypid(),
            cwd: sys_get_temp_dir(),
        );
    }
}
