<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use Closure;
use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\Worker\TestWorkerMaster;

/**
 * A master started by an application that loaded itself first, with its workers launched
 * by fork: every worker is a copy of the loaded application. What the application had
 * before the master started — its state, a file it opened, its error and exception
 * handlers, a scheduler it built — has to reach the workers as it was, and must not get
 * in the way of the master's own loop.
 *
 * The master runs tests/servers/worker/preloaded-master.php, its workers
 * tests/servers/worker/preloaded-worker.php.
 */
class PreloadedWorkerMasterTest extends TestCase
{
    protected string $appDirectory = '';

    protected function setUp(): void
    {
        $this->appDirectory = sys_get_temp_dir() . '/sc-preloaded-app-' . uniqid('', true);

        mkdir($this->appDirectory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->appDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->appDirectory);
    }

    public function testWorkersRunTheApplicationTheMasterLoaded(): void
    {
        $master = $this->startMaster();

        try {
            $answer = $this->ask($master, '/');

            self::assertSame($master->pid(), $answer['loadedBy'], 'the application should load once, in the master');
            self::assertNotSame($master->pid(), $answer['pid']);
        } finally {
            $master->stop();
        }
    }

    /**
     * A logger opens its file while the application loads. Shut out of the worker with
     * the master's own descriptors, the file would be /dev/null there, and the worker's
     * lines would go nowhere without an error.
     */
    public function testAFileTheApplicationOpenedBeforeTheMasterStartedIsWrittenFromTheWorkers(): void
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
     * The master silences a warning on every tick — it looks for a reload file that is not
     * there — and an error handler that does not ask error_reporting() would see each of
     * them. One that turned them into exceptions would end the master on its first tick.
     */
    public function testTheMastersLoopRunsWithoutTheApplicationsErrorHandler(): void
    {
        $master = $this->startMaster();

        try {
            usleep(500_000);

            $fromMaster = array_filter(
                explode(PHP_EOL, $this->read('errors.log')),
                static fn(string $line): bool => str_starts_with($line, $master->pid() . ' '),
            );

            self::assertSame([], array_values($fromMaster));
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
            self::assertStringNotContainsString($master->pid() . ' ', $this->read('uncaught.log'));

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
     * The scheduler is built while the application loads, in the master, which has no
     * liveness pipe. Kept from then on, it would mark nothing in the worker; the watchdog
     * starts watching a worker at its first mark, so this one would never be watched, and
     * a hung one never replaced.
     */
    public function testASchedulerBuiltBeforeTheMasterStartedKeepsItsWorkerWatched(): void
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
     * Refused before anything is written, and with what to do about it: refused at the
     * first fork instead, the journal would already say the pool started.
     */
    public function testAnApplicationThatStartedTheRuntimeWhileLoadingIsRefused(): void
    {
        $master = $this->startMaster(
            env: ['SCONCUR_TEST_APP_ASYNC' => '1'],
            waitReachable: false,
        );

        try {
            $exitCode = $master->waitForExit(10.0);

            self::assertNotNull($exitCode, 'the master should not start');
            self::assertNotSame(0, $exitCode);
            self::assertStringContainsString(
                'is launched by fork, and this process cannot fork',
                $master->masterOutput(),
            );
            self::assertStringNotContainsString('start groups=', $master->logText());
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
                'workerScript' => dirname(__DIR__, 2) . '/servers/worker/preloaded-worker.php',
                ...$options,
            ],
            env: ['SCONCUR_TEST_APP_DIR' => $this->appDirectory, ...$env],
            waitReachable: $waitReachable,
            masterScript: dirname(__DIR__, 2) . '/servers/worker/preloaded-master.php',
        );
    }

    /**
     * @return array{loadedBy: int, pid: int}
     */
    protected function ask(TestWorkerMaster $master, string $path): array
    {
        [$status, $body] = $master->get($path);

        self::assertSame(200, $status, 'the worker should answer ' . $path . ': ' . $body);

        /** @var array{loadedBy: int, pid: int} $answer */
        $answer = json_decode($body, true);

        return $answer;
    }

    protected function read(string $file): string
    {
        $path = $this->appDirectory . '/' . $file;

        return is_file($path) ? (string) file_get_contents($path) : '';
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
