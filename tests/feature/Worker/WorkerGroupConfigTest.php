<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Exceptions\Worker\InvalidConfigException;
use SConcur\Worker\MasterConfig;
use SConcur\Worker\RestartPolicy;
use SConcur\Worker\WorkerGroupConfig;

/**
 * The group half of the master config: what a pool may say, what it inherits from the
 * master, and how its `server` block reaches the worker's argv.
 */
class WorkerGroupConfigTest extends TestCase
{
    public function testAGroupInheritsTheMasterWideDefaults(): void
    {
        $config = MasterConfig::fromArray([
            'phpBinary'         => '/usr/bin/php8.4',
            'phpArgs'           => ['-d', 'extension=x.so'],
            'env'               => ['SHARED' => '1'],
            'restartPolicy'     => 'on-failure',
            'shutdownTimeoutMs' => 4000,
            'groups'            => [
                ['name' => 'orders', 'workerScript' => '/app/consumer.php'],
            ],
        ]);

        $group = $config->groups()[0];

        self::assertSame('/usr/bin/php8.4', $group->phpBinary);
        self::assertSame(['-d', 'extension=x.so'], $group->phpArgs);
        self::assertSame(['SHARED' => '1'], $group->env);
        self::assertSame(RestartPolicy::OnFailure, $group->restartPolicy);
        self::assertSame(4000, $group->shutdownTimeoutMs);
    }

    public function testAGroupOverridesWhatItNamesItself(): void
    {
        $config = MasterConfig::fromArray([
            'restartPolicy'     => 'always',
            'shutdownTimeoutMs' => 4000,
            'env'               => ['SHARED' => '1'],
            'groups'            => [
                [
                    'name'              => 'emails',
                    'workerScript'      => '/app/consumer.php',
                    'restartPolicy'     => 'never',
                    'shutdownTimeoutMs' => 9000,
                    'env'               => ['OWN' => '2'],
                ],
            ],
        ]);

        $group = $config->groups()[0];

        self::assertSame(RestartPolicy::Never, $group->restartPolicy);
        self::assertSame(9000, $group->shutdownTimeoutMs);
        self::assertSame(['SHARED' => '1', 'OWN' => '2'], $group->env);
    }

    public function testTheServerBlockBecomesWorkerFlags(): void
    {
        $group = $this->group([
            'server' => [
                'address'   => '0.0.0.0:8080',
                'reusePort' => true,
                'quiet'     => false,
                'workers'   => 4,
            ],
        ]);

        self::assertSame(
            ['--address=0.0.0.0:8080', '--reusePort=1', '--quiet=0', '--workers=4'],
            $group->argumentFlags(),
        );
    }

    /**
     * The reason a structured value is allowed at all: a consumer takes its queue list
     * through argv, and there is no shell on the way to mangle the quotes.
     */
    public function testAStructuredServerValueTravelsAsJson(): void
    {
        $group = $this->group([
            'server' => [
                'queues' => [
                    ['name' => 'orders', 'coroutineCount' => 8],
                    ['name' => 'tenant:1:invoices'],
                ],
            ],
        ]);

        self::assertSame(
            ['--queues=[{"name":"orders","coroutineCount":8},{"name":"tenant:1:invoices"}]'],
            $group->argumentFlags(),
        );
    }

    public function testAGroupWithoutANameIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('requires a "name"');

        MasterConfig::fromArray(['groups' => [['workerScript' => '/app/x.php']]]);
    }

    public function testAGroupWithoutAScriptIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('requires a "workerScript"');

        MasterConfig::fromArray(['groups' => [['name' => 'orders']]]);
    }

    public function testAGroupNameThatIsNotAPathComponentIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('may contain only letters');

        MasterConfig::fromArray(['groups' => [['name' => 'bad/name', 'workerScript' => '/app/x.php']]]);
    }

    public function testTwoGroupsWithTheSameNameAreRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('defined twice');

        MasterConfig::fromArray([
            'groups' => [
                ['name' => 'orders', 'workerScript' => '/app/x.php'],
                ['name' => 'orders', 'workerScript' => '/app/y.php'],
            ],
        ]);
    }

    public function testAnUnknownGroupKeyIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('unknown key(s): wokerCount');

        MasterConfig::fromArray([
            'groups' => [['name' => 'orders', 'workerScript' => '/app/x.php', 'wokerCount' => 2]],
        ]);
    }

    public function testAConfigWithoutGroupsIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('"groups" must be a non-empty list');

        MasterConfig::fromArray(['name' => 'lonely']);
    }

    /**
     * The keys that used to sit at the top level are named rather than called unknown,
     * so an old config says where its settings went.
     */
    public function testATopLevelPoolKeyPointsAtTheGroups(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('move them into an entry of "groups"');

        MasterConfig::fromArray([
            'workerScript' => '/app/x.php',
            'workerCount'  => 2,
        ]);
    }

    public function testTheTotalsCoverEveryGroup(): void
    {
        $config = MasterConfig::fromArray([
            'groups' => [
                ['name' => 'a', 'workerScript' => '/app/x.php', 'workerCount' => 3, 'shutdownTimeoutMs' => 1000],
                ['name' => 'b', 'workerScript' => '/app/y.php', 'workerCount' => 2, 'shutdownTimeoutMs' => 7000],
            ],
        ]);

        self::assertSame(5, $config->totalWorkerCount());
        self::assertSame(7000, $config->maxShutdownTimeoutMs());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function group(array $overrides): WorkerGroupConfig
    {
        return MasterConfig::fromArray([
            'groups' => [
                ['name' => 'group', 'workerScript' => '/app/x.php', ...$overrides],
            ],
        ])->groups()[0];
    }
}
