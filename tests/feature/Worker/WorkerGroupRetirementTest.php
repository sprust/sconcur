<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use ReflectionMethod;
use ReflectionProperty;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Worker\LogTarget;
use SConcur\Worker\MasterLogger;
use SConcur\Worker\RestartPolicy;
use SConcur\Worker\WorkerGroup;
use SConcur\Worker\WorkerGroupConfig;
use SConcur\Worker\WorkerMaster;

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

    /**
     * A reload narrowed to one group takes it out of retirement exactly as a full reload
     * does. Naming the group is if anything the more deliberate way to ask for it back, and
     * without the put-back the pool stayed retiring: it spawns nothing, so its slots stayed
     * empty and the next tick dropped it for good — with no reload able to revive it.
     */
    public function testAScopedReloadTakesItsGroupOutOfRetirement(): void
    {
        $config = $this->config();
        $pool   = $this->group();

        $pool->retire();

        $master = new WorkerMaster(
            runtimeDir: sys_get_temp_dir(),
            name: 'sconcur-test-retirement',
        );

        $this->set(
            object: $master,
            property: 'logger',
            value: $this->logger(),
        );
        $this->set(
            object: $master,
            property: 'pools',
            value: [$config->name => $pool],
        );
        $this->set(
            object: $master,
            property: 'groups',
            value: [$config],
        );

        $applyOneGroup = new ReflectionMethod(WorkerMaster::class, 'applyOneGroup');

        self::assertTrue(
            $applyOneGroup->invoke($master, [$config], $config->name),
            'the master runs that group, so the reload is one it can honour',
        );

        self::assertFalse($pool->isRetiring(), 'a group named by a reload must spawn again');
    }

    protected function set(object $object, string $property, mixed $value): void
    {
        (new ReflectionProperty(WorkerMaster::class, $property))->setValue($object, $value);
    }

    protected function config(): WorkerGroupConfig
    {
        return new WorkerGroupConfig(
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
        );
    }

    protected function group(): WorkerGroup
    {
        return new WorkerGroup(
            config: $this->config(),
            logger: $this->logger(),
            masterPid: getmypid() === false ? 0 : getmypid(),
            cwd: sys_get_temp_dir(),
        );
    }

    protected function logger(): MasterLogger
    {
        return new MasterLogger(
            logDir: sys_get_temp_dir(),
            name: 'sconcur-test-retirement',
            rotateDays: 1,
            masterPid: getmypid() === false ? 0 : getmypid(),
            logTo: LogTarget::Stdout,
        );
    }
}
