<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use Closure;
use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\Worker\TestWorkerMaster;
use SConcur\Worker\MasterCli;
use SConcur\Worker\WorkerMaster;

/**
 * A fork group with a bootstrap: its fork server loads the application once per generation and
 * every worker is a copy of it. What the application had when it loaded — its state, a
 * file it opened, its error and exception handlers, a scheduler it built — has to reach the
 * workers as it was, must not get in the way of the fork server's own loop, and a reload has to
 * load it again, from wherever the config's path now leads.
 *
 * The bootstrap is tests/servers/worker/bootstrap-app.php, the worker script
 * tests/servers/worker/bootstrap-worker.php.
 */
class BootstrapWorkerMasterTest extends TestCase
{
    protected string $appDirectory = '';

    protected function setUp(): void
    {
        $this->appDirectory = sys_get_temp_dir() . '/sc-bootstrap-app-' . uniqid('', true);

        mkdir($this->appDirectory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->appDirectory));
    }

    public function testWorkersRunTheApplicationTheirForkServerLoaded(): void
    {
        $master = $this->startMaster();

        try {
            $answer = $this->ask($master, '/');

            self::assertNotSame($master->pid(), $answer['loadedBy'], 'the master never loads the application');
            self::assertNotSame($answer['pid'], $answer['loadedBy'], 'the worker does not load it again');
            self::assertStringContainsString(
                sprintf('[fork-server: %d default]: loaded', $answer['loadedBy']),
                $master->logText(),
            );
        } finally {
            $master->stop();
        }
    }

    /**
     * A logger opens its file while the application loads. Shut out of the worker with
     * the fork server's own descriptors, the file would be /dev/null there, and the worker's
     * lines would go nowhere without an error.
     */
    public function testAFileTheBootstrapOpenedIsWrittenFromTheWorkers(): void
    {
        $master = $this->startMaster();

        try {
            $answer = $this->ask($master, '/log');

            self::assertStringContainsString('written by ' . $answer['pid'], $this->read('app.log'));
        } finally {
            $master->stop();
        }
    }

    /**
     * The fork server's loop silences warnings as the master's does, and an error handler that
     * does not ask error_reporting() would see each of them. One that turned them into
     * exceptions would end the fork server on its first tick.
     */
    public function testTheForkServersLoopRunsWithoutTheApplicationsErrorHandler(): void
    {
        $master = $this->startMaster();

        try {
            $answer = $this->ask($master, '/');

            usleep(500_000);

            $fromForkServer = array_filter(
                explode(PHP_EOL, $this->read('errors.log')),
                static fn(string $line): bool => str_starts_with($line, $answer['loadedBy'] . ' '),
            );

            self::assertSame([], array_values($fromForkServer));
        } finally {
            $master->stop();
        }
    }

    public function testAWorkerRunsUnderTheApplicationsErrorHandler(): void
    {
        $master = $this->startMaster();

        try {
            $answer = $this->ask($master, '/warn');

            self::assertStringContainsString($answer['pid'] . ' raised in the worker', $this->read('errors.log'));
        } finally {
            $master->stop();
        }
    }

    /**
     * As a php started on the script would: the exception goes to the handler the
     * application installed, and the worker ends with 255.
     */
    public function testAnExceptionThatEscapesTheWorkerScriptReachesTheApplicationsHandler(): void
    {
        $master = $this->startMaster(
            env: ['SCONCUR_TEST_WORKER_THROWS' => '1'],
            waitReachable: false,
        );

        try {
            $reported = $this->waitFor(
                fn(): bool => str_contains($this->read('uncaught.log'), 'the worker failed to start'),
                timeoutSeconds: 5.0,
            );

            self::assertTrue($reported, 'the application\'s exception handler should get the exception');

            $exited = $this->waitFor(
                static fn(): bool => str_contains($master->logText(), 'code=255'),
                timeoutSeconds: 5.0,
            );

            self::assertTrue($exited, 'the worker should end with 255: ' . $master->logText());
        } finally {
            $master->stop();
        }
    }

    /**
     * The scheduler is built while the application loads, in the fork server, which has no
     * liveness pipe. Kept from then on, it would mark nothing in the worker; the watchdog
     * starts watching a worker at its first mark, so this one would never be watched, and
     * a hung one never replaced.
     */
    public function testASchedulerBuiltWhileTheBootstrapLoadedKeepsItsWorkerWatched(): void
    {
        $master = $this->startMaster(options: [
            'workerCount'       => 1,
            'watchdogTimeoutMs' => 5_000,
        ]);

        try {
            $before = $master->workerPid();

            // The client gives up long before the worker comes back.
            $master->get('/hang');

            $replaced = $this->waitFor(
                static function () use ($master, $before): bool {
                    $pid = $master->workerPid();

                    return $pid !== 0 && $pid !== $before;
                },
                timeoutSeconds: 25.0,
            );

            self::assertTrue($replaced, 'the watchdog should replace the hung worker: ' . $master->logText());
            self::assertStringContainsString('no heartbeat for', $master->logText());
        } finally {
            $master->stop();
        }
    }

    /**
     * A bootstrap that starts the extension's runtime leaves its fork server with threads, and
     * a process with threads cannot fork. The fork server says why and the group never comes
     * up, which stops the master with its own exit code.
     */
    public function testABootstrapThatStartedTheRuntimeIsRefused(): void
    {
        $master = $this->startMaster(
            env: ['SCONCUR_TEST_APP_ASYNC' => '1'],
            waitReachable: false,
        );

        try {
            self::assertSame(WorkerMaster::EXIT_NEVER_CAME_UP, $master->waitForExit(10.0), $master->logText());
            self::assertStringContainsString('this generation cannot fork', $master->logText());
        } finally {
            $master->stop();
        }
    }

    public function testABootstrapThatDoesNotLoadStopsTheMaster(): void
    {
        $this->write('version', 'broken');

        $master = $this->startMaster(waitReachable: false);

        try {
            self::assertSame(WorkerMaster::EXIT_NEVER_CAME_UP, $master->waitForExit(10.0), $master->logText());
            self::assertStringContainsString('the application does not load', $master->logText());
        } finally {
            $master->stop();
        }
    }

    /**
     * What a reload is for: the next generation's fork server loads the application from disk
     * again, and every worker ends up on it.
     */
    public function testAReloadLoadsTheApplicationAgain(): void
    {
        $master = $this->startMaster(options: ['workerCount' => 2]);

        try {
            $before = $this->ask($master, '/');

            self::assertSame('v1', $before['version']);

            $this->write('version', 'v2');

            [$code, $output] = TestWorkerMaster::runCommand('reload', $master->configPath());

            self::assertSame(MasterCli::EXIT_OK, $code, 'reload should succeed: ' . $output);

            for ($request = 0; $request < 10; ++$request) {
                $after = $this->ask($master, '/');

                self::assertSame('v2', $after['version'], 'every worker should be on the new load');
                self::assertNotSame($before['loadedBy'], $after['loadedBy']);
            }
        } finally {
            $master->stop();
        }
    }

    /**
     * A next generation that does not load gives the reload up before any worker is
     * touched, and the CLI says why.
     */
    public function testAReloadWhoseBootstrapDoesNotLoadIsGivenUp(): void
    {
        $master = $this->startMaster(options: ['workerCount' => 2]);

        try {
            $before = $master->distinctWorkerPids();

            $this->write('version', 'broken');

            [$code, $output] = TestWorkerMaster::runCommand('reload', $master->configPath());

            self::assertSame(MasterCli::EXIT_ERROR, $code, 'the CLI must report the failure: ' . $output);
            self::assertStringContainsString('reload failed', $output);
            self::assertStringContainsString('the application does not load', $master->logText());

            self::assertEqualsCanonicalizing($before, $master->distinctWorkerPids(), 'no worker was replaced');
            self::assertSame('v1', $this->ask($master, '/')['version']);
        } finally {
            $master->stop();
        }
    }

    /**
     * A deploy that writes each release into a directory of its own and switches a
     * `current` symlink: a process that loaded through the symlink holds the paths of the
     * release it found, Composer's autoloader included. The next generation is a php of its
     * own, so it follows the symlink to the new release — the bootstrap and the classes it
     * autoloads both.
     */
    public function testAReloadAfterASymlinkSwitchRunsTheNewRelease(): void
    {
        foreach (['1', '2'] as $release) {
            $directory = $this->appDirectory . '/releases/' . $release;

            mkdir($directory, recursive: true);

            file_put_contents(
                $directory . '/ReleaseMarker.php',
                "<?php\nclass ReleaseMarker\n{\n    public const NAME = 'release-$release';\n}\n",
            );

            file_put_contents(
                $directory . '/bootstrap.php',
                "<?php\n"
                . "spl_autoload_register(static function (string \$class): void {\n"
                . "    if (\$class === 'ReleaseMarker') {\n"
                . "        require __DIR__ . '/ReleaseMarker.php';\n"
                . "    }\n"
                . "});\n"
                . 'require ' . var_export(dirname(__DIR__, 2) . '/servers/worker/bootstrap-app.php', true) . ";\n",
            );
        }

        symlink($this->appDirectory . '/releases/1', $this->appDirectory . '/current');

        $master = $this->startMaster(options: [
            'workerCount' => 2,
            'bootstrap'   => $this->appDirectory . '/current/bootstrap.php',
        ]);

        try {
            self::assertSame('release-1', $this->ask($master, '/')['release']);

            unlink($this->appDirectory . '/current');
            symlink($this->appDirectory . '/releases/2', $this->appDirectory . '/current');

            [$code, $output] = TestWorkerMaster::runCommand('reload', $master->configPath());

            self::assertSame(MasterCli::EXIT_OK, $code, 'reload should succeed: ' . $output);

            for ($request = 0; $request < 10; ++$request) {
                self::assertSame('release-2', $this->ask($master, '/')['release']);
            }
        } finally {
            $master->stop();
        }
    }

    /**
     * @param array<string, mixed>  $options
     * @param array<string, string> $env
     */
    protected function startMaster(array $options = [], array $env = [], bool $waitReachable = true): TestWorkerMaster
    {
        return TestWorkerMaster::start(
            options: [
                'launch'       => 'fork',
                'workerScript' => dirname(__DIR__, 2) . '/servers/worker/bootstrap-worker.php',
                'bootstrap'    => dirname(__DIR__, 2) . '/servers/worker/bootstrap-app.php',
                ...$options,
            ],
            env: ['SCONCUR_TEST_APP_DIR' => $this->appDirectory, ...$env],
            waitReachable: $waitReachable,
        );
    }

    /**
     * @return array{loadedBy: int, pid: int, version: string, release: string}
     */
    protected function ask(TestWorkerMaster $master, string $path): array
    {
        [$status, $body] = $master->get($path);

        self::assertSame(200, $status, 'the worker should answer ' . $path . ': ' . $body);

        /** @var array{loadedBy: int, pid: int, version: string, release: string} $answer */
        $answer = json_decode($body, true);

        return $answer;
    }

    protected function read(string $file): string
    {
        $path = $this->appDirectory . '/' . $file;

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    protected function write(string $file, string $contents): void
    {
        file_put_contents($this->appDirectory . '/' . $file, $contents);
    }

    private function waitFor(Closure $condition, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }

            usleep(100_000);
        }

        return $condition();
    }
}
