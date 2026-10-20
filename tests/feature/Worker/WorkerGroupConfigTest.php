<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Exceptions\Worker\InvalidConfigException;
use SConcur\Worker\AccessLogTarget;
use SConcur\Worker\LaunchMode;
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
            // An interpreter of its own takes exec: a forked worker runs on the master's.
            'launch'            => 'exec',
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

    /**
     * Fork is what a config that says nothing gets; a top-level launch is the default of
     * every group, and a group's own wins.
     */
    public function testLaunchIsForkUnlessTheConfigSaysOtherwise(): void
    {
        self::assertSame(LaunchMode::Fork, $this->group([])->launch);

        $config = MasterConfig::fromArray([
            'launch' => 'exec',
            'groups' => [
                ['name' => 'inherits', 'workerScript' => '/app/x.php'],
                ['name' => 'overrides', 'workerScript' => '/app/x.php', 'launch' => 'fork'],
            ],
        ]);

        self::assertSame(LaunchMode::Exec, $config->groups()[0]->launch);
        self::assertSame(LaunchMode::Fork, $config->groups()[1]->launch);
    }

    public function testAnUnknownLaunchIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('launch must be exec|fork');

        $this->group(['launch' => 'spawn']);
    }

    /**
     * A fork group's workers are copies of its fork server, which the master starts like an
     * exec'd worker: any interpreter and any flags are honoured, as for exec.
     */
    public function testAForkGroupRunsOnAnyPhpWithAnyFlags(): void
    {
        $group = $this->group([
            'launch'    => 'fork',
            'phpBinary' => '/usr/bin/php8.3',
            'phpArgs'   => ['-c', '/etc/php.ini', '-n'],
        ]);

        self::assertSame('/usr/bin/php8.3', $group->phpBinary);
        self::assertSame(['-c', '/etc/php.ini', '-n'], $group->phpArgs);
    }

    /**
     * The bootstrap is loaded by a fork server, which only a fork group has; an exec'd worker
     * loads what it needs in its script.
     */
    public function testABootstrapNeedsLaunchFork(): void
    {
        self::assertSame('/app/bootstrap.php', $this->group(['bootstrap' => '/app/bootstrap.php'])->bootstrap);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('"bootstrap", which needs launch=fork');

        $this->group([
            'launch'    => 'exec',
            'bootstrap' => '/app/bootstrap.php',
        ]);
    }

    /**
     * The master hands a group to its fork server as toArray(), and the fork server reads it back
     * with fromResolvedArray(): every setting has to make the trip, defaults it did not write
     * included.
     */
    public function testAGroupMakesTheTripToItsForkServerWhole(): void
    {
        $group = MasterConfig::fromArray([
            'shutdownTimeoutMs' => 4000,
            'env'               => ['SHARED' => '1'],
            'groups'            => [
                [
                    'name'           => 'http',
                    'workerScript'   => '/app/worker.php',
                    'workerCount'    => 3,
                    'phpArgs'        => ['-d', 'memory_limit=1G'],
                    'workerArgs'     => ['--flag=1'],
                    'restartPolicy'  => 'on-failure',
                    'readyTimeoutMs' => 9000,
                    'listen'         => '0.0.0.0:8080',
                    'bootstrap'      => '/app/bootstrap.php',
                    'accessLog'      => 'file',
                    'server'         => ['queues' => [['name' => 'orders']], 'maxRequests' => 10],
                ],
            ],
        ])->groups()[0];

        $decoded = json_decode((string) json_encode($group->toArray()), true);

        self::assertIsArray($decoded);

        $copy = WorkerGroupConfig::fromResolvedArray($decoded);

        self::assertEquals($group, $copy);
    }

    /**
     * A setting missing from what the fork server is handed means it runs another release of
     * the library than the master; it is refused by name rather than filled with a guess.
     */
    public function testAGroupHandedOverWithoutASettingIsRefused(): void
    {
        $handed = $this->group([])->toArray();

        unset($handed['readyTimeoutMs']);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('without readyTimeoutMs');

        WorkerGroupConfig::fromResolvedArray($handed);
    }

    /**
     * The master's socket and a socket of each worker's are two ways to share a port; a
     * group asking for both would leave the master's unused.
     */
    public function testListenRefusesAServerThatBindsItsOwnSocket(): void
    {
        foreach ([['reusePort' => true], ['listenFd' => 5]] as $server) {
            try {
                $this->group([
                    'listen' => '0.0.0.0:8080',
                    'server' => $server,
                ]);

                self::fail('server ' . json_encode($server) . ' should have been refused next to listen');
            } catch (InvalidConfigException $exception) {
                self::assertStringContainsString('accept on the master\'s socket', $exception->getMessage());
            }
        }

        self::assertSame('0.0.0.0:8080', $this->group(['listen' => '0.0.0.0:8080'])->listen);
        self::assertSame('', $this->group([])->listen);
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

    public function testAWatchdogThresholdBelowTheFloorIsRefused(): void
    {
        // Below the floor the watchdog reads the ordinary gap between two marks as a hang
        // and kills every worker it has, replacements included.
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('must be 0 (off) or at least');

        MasterConfig::fromArray([
            'groups' => [
                [
                    'name'              => 'orders',
                    'workerScript'      => '/app/consumer.php',
                    'watchdogTimeoutMs' => 200,
                ],
            ],
        ]);
    }

    public function testAWatchdogThresholdThatIsNotAWholeNumberIsRefused(): void
    {
        // "off" casts to 0, which would read as "switched off" — a typo must not disable
        // the watch in silence.
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('must be a whole number of milliseconds');

        MasterConfig::fromArray([
            'watchdogTimeoutMs' => 'off',
            'groups'            => [
                ['name' => 'orders', 'workerScript' => '/app/consumer.php'],
            ],
        ]);
    }

    public function testAMasterWideWatchdogThresholdBelowTheFloorIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('config: "watchdogTimeoutMs" must be 0 (off) or at least 5000ms');

        MasterConfig::fromArray([
            'watchdogTimeoutMs' => 100,
            'groups'            => [
                ['name' => 'orders', 'workerScript' => '/app/consumer.php'],
            ],
        ]);
    }

    public function testTheWatchdogIsSwitchedOffByZeroAndInheritedOtherwise(): void
    {
        $config = MasterConfig::fromArray([
            'watchdogTimeoutMs' => 30_000,
            'groups'            => [
                ['name' => 'http', 'workerScript' => '/app/worker.php'],
                ['name' => 'orders', 'workerScript' => '/app/consumer.php', 'watchdogTimeoutMs' => 0],
            ],
        ]);

        [$http, $orders] = $config->groups();

        self::assertSame(30_000, $http->watchdogTimeoutMs, 'a group inherits the master-wide threshold');
        self::assertSame(0, $orders->watchdogTimeoutMs, 'zero switches the watch off for one pool');
    }

    public function testTheReadyTimeoutDefaultsAndIsInheritedOrOverridden(): void
    {
        $defaulted = MasterConfig::fromArray([
            'groups' => [
                ['name' => 'http', 'workerScript' => '/app/worker.php'],
            ],
        ]);

        self::assertSame(MasterConfig::DEFAULT_READY_TIMEOUT_MS, $defaulted->groups()[0]->readyTimeoutMs);
        self::assertSame(30_000, MasterConfig::DEFAULT_READY_TIMEOUT_MS);

        $config = MasterConfig::fromArray([
            'readyTimeoutMs' => 45_000,
            'groups'         => [
                ['name' => 'http', 'workerScript' => '/app/worker.php'],
                ['name' => 'orders', 'workerScript' => '/app/consumer.php', 'readyTimeoutMs' => 120_000],
            ],
        ]);

        [$http, $orders] = $config->groups();

        self::assertSame(45_000, $http->readyTimeoutMs, 'a group inherits the master-wide wait');
        self::assertSame(120_000, $orders->readyTimeoutMs, 'and may set its own');
    }

    public function testAReadyTimeoutBelowTheFloorIsRefused(): void
    {
        // There is no "off": a zero would be a reload that never waits, which is the one
        // this setting exists to prevent.
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('config: group "orders": "readyTimeoutMs" must be at least 1000ms');

        MasterConfig::fromArray([
            'groups' => [
                ['name' => 'orders', 'workerScript' => '/app/consumer.php', 'readyTimeoutMs' => 0],
            ],
        ]);
    }

    public function testTheAccessLogDefaultsToStdoutAndIsInheritedOrOverridden(): void
    {
        self::assertSame(AccessLogTarget::Stdout, $this->group([])->accessLog);

        $config = MasterConfig::fromArray([
            'accessLog' => 'file',
            'groups'    => [
                ['name' => 'http', 'workerScript' => '/app/worker.php'],
                ['name' => 'orders', 'workerScript' => '/app/consumer.php', 'accessLog' => 'off'],
            ],
        ]);

        [$http, $orders] = $config->groups();

        self::assertSame(AccessLogTarget::File, $http->accessLog, 'a group inherits the master-wide target');
        self::assertSame(AccessLogTarget::Off, $orders->accessLog, 'and may name its own');
    }

    public function testAnUnknownAccessLogIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('config: group "group": "accessLog" must be stdout|file|off');

        $this->group(['accessLog' => 'journal']);
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
