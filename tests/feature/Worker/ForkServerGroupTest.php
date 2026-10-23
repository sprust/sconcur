<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use Closure;
use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\Worker\TestWorkerMaster;
use SConcur\Worker\MasterReloadFile;

/**
 * The generations of a fork group when a fork server dies or a reload cannot finish: a
 * reload given up halfway has to leave the group at full strength on one generation, and a
 * fork server that keeps dying must not be started again on every tick.
 *
 * The worker script is tests/servers/worker/held-slot-worker.php: a test holds a slot to
 * have its replacement run without ever saying it is up, which is where a reload stops.
 * Which generation a worker belongs to is told by its parent, the fork server it was forked
 * from.
 */
class ForkServerGroupTest extends TestCase
{
    protected string $holdDirectory = '';

    protected function setUp(): void
    {
        $this->holdDirectory = sys_get_temp_dir() . '/sc-held-slots-' . uniqid('', true);

        mkdir($this->holdDirectory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->holdDirectory));
    }

    /**
     * The next generation's fork server dies once it has taken worker 0 over and while it
     * is bringing worker 1 up. The old generation had let its worker 0 go, so the reload
     * given up has to bring that slot back up there.
     */
    public function testAReloadWhoseNextForkServerDiesMovesTheSlotsItTookBack(): void
    {
        $master = $this->startMaster(
            options: [
                'workerCount'    => 2,
                'readyTimeoutMs' => 20_000,
            ],
        );

        try {
            $runningForkServerPid = $this->forkServerPid(
                master: $master,
                generation: 1,
            );

            $this->assertWorkersOf(
                master: $master,
                forkServerPid: $runningForkServerPid,
                message: 'both workers should serve before the reload',
            );

            $this->holdSlot(1);

            $nonce = $this->requestReload($master);

            $this->assertJournalSays(
                master: $master,
                needle: 'default #1]: spawned as a replacement',
                message: 'the next generation should have taken worker 0 over and be bringing worker 1 up',
            );

            posix_kill(
                $this->forkServerPid(
                    master: $master,
                    generation: 2,
                ),
                SIGKILL,
            );

            $failure = $this->waitForReloadToEnd(
                master: $master,
                nonce: $nonce,
            );

            self::assertStringContainsString('the fork server of the next generation exited signal=9', $failure);
            self::assertStringContainsString('reload given up', $master->logText());

            $this->assertWorkersOf(
                master: $master,
                forkServerPid: $runningForkServerPid,
                message: 'both workers should serve from the generation that was running',
            );
        } finally {
            $master->stop();
        }
    }

    /**
     * A replacement that does not come up gives the reload up after the slots before it
     * have moved: they go back to the old generation, and the next one is stopped.
     */
    public function testAReloadWhoseReplacementNeverComesUpMovesTheSlotsItTookBack(): void
    {
        $master = $this->startMaster(
            options: [
                'workerCount'    => 2,
                'readyTimeoutMs' => 2_000,
            ],
        );

        try {
            $runningForkServerPid = $this->forkServerPid(
                master: $master,
                generation: 1,
            );

            $this->assertWorkersOf(
                master: $master,
                forkServerPid: $runningForkServerPid,
                message: 'both workers should serve before the reload',
            );

            $this->holdSlot(1);

            $failure = $this->waitForReloadToEnd(
                master: $master,
                nonce: $this->requestReload($master),
            );

            self::assertStringContainsString(
                'the replacement for worker 1 did not say it was up within 2000ms',
                $failure,
            );
            self::assertSame(
                2,
                substr_count($master->logText(), 'default #0]: up; took over the slot'),
                'worker 0 should have moved to the next generation and back: ' . $master->logText(),
            );

            $this->assertWorkersOf(
                master: $master,
                forkServerPid: $runningForkServerPid,
                message: 'both workers should serve from the generation that was running',
            );
            $this->assertJournalSays(
                master: $master,
                needle: 'fork server of generation 2 exited',
                message: 'the next generation should be stopped',
            );
        } finally {
            $master->stop();
        }
    }

    /**
     * The reload is given up on worker 1, and while worker 0 is moved back the old
     * generation's fork server dies. The next generation is then all the group has: it
     * keeps worker 0 and brings worker 1 up again.
     */
    public function testTheRunningGenerationDyingWhileAReloadIsGivenUpLeavesTheGroupOnTheNextOne(): void
    {
        $master = $this->startMaster(
            options: [
                'workerCount'    => 2,
                'readyTimeoutMs' => 20_000,
            ],
        );

        try {
            $runningForkServerPid = $this->forkServerPid(
                master: $master,
                generation: 1,
            );

            $this->assertWorkersOf(
                master: $master,
                forkServerPid: $runningForkServerPid,
                message: 'both workers should serve before the reload',
            );

            // The new settings give up on worker 1 soon; the old ones, which moving worker 0
            // back waits by, would wait far longer than the test.
            $master->rewriteConfig(
                $this->reloadConfig(
                    master: $master,
                    overrides: ['readyTimeoutMs' => 1_500],
                ),
            );

            $this->holdSlot(1);

            $nonce = $this->requestReload($master);

            $this->assertJournalSays(
                master: $master,
                needle: 'default #0]: up; took over the slot',
                message: 'the next generation should take worker 0 over',
            );

            // Worker 0 must not come up in the old generation again, so the move back is still
            // waiting on it when that generation dies.
            $this->holdSlot(0);

            $this->assertJournalSays(
                master: $master,
                needle: 'reload given up',
                message: 'the reload should be given up on worker 1',
            );

            $this->unholdSlot(1);

            posix_kill($runningForkServerPid, SIGKILL);

            $failure = $this->waitForReloadToEnd(
                master: $master,
                nonce: $nonce,
                timeoutSeconds: 8.0,
            );

            self::assertStringContainsString('did not say it was up within 1500ms', $failure);

            $this->assertWorkersOf(
                master: $master,
                forkServerPid: $this->forkServerPid(
                    master: $master,
                    generation: 2,
                ),
                message: 'both workers should serve from the next generation',
            );
        } finally {
            $master->stop();
        }
    }

    /**
     * A fork server that dies right after it loaded is a fast fail like one that does not
     * load: the next generation waits restartBackoffMs, doubled with each such death.
     */
    public function testAForkServerThatKeepsDyingSoonAfterItLoadsIsRestartedWithAGrowingBackoff(): void
    {
        $master = $this->startMaster(
            options: [
                'workerCount'         => 1,
                'restartBackoffMs'    => 300,
                'maxRestartBackoffMs' => 10_000,
            ],
            waitReachable: false,
        );

        try {
            foreach ([1, 2] as $generation) {
                $forkServerPid = $this->forkServerPid(
                    master: $master,
                    generation: $generation,
                );

                $this->assertJournalSays(
                    master: $master,
                    needle: sprintf('[fork-server: %d default]: loaded', $forkServerPid),
                    message: sprintf('generation %d should load', $generation),
                );

                posix_kill($forkServerPid, SIGKILL);

                $this->assertJournalSays(
                    master: $master,
                    needle: sprintf('fork server of generation %d exited signal=9', $generation),
                    message: sprintf('the master should see generation %d die', $generation),
                );
            }

            preg_match_all('/starting a new generation in (\d+)ms/', $master->logText(), $matches);

            self::assertSame(['300', '600'], $matches[1], $master->logText());
        } finally {
            $master->stop();
        }
    }

    /**
     * The fork server's events pipe is its own. Its bootstrap runs before the fork server
     * does anything, and a worker is forked with whatever the fork server holds, so a
     * program either of them starts — a `shell_exec` while a framework boots, a sidecar —
     * would otherwise inherit the pipe and could write into the master's view of the group.
     * The fork server holds it once, close-on-exec, and nobody else holds it at all.
     */
    public function testNothingButTheForkServerHoldsItsEventsPipe(): void
    {
        $root      = dirname(__DIR__, 3);
        $bootstrap = $this->holdDirectory . '/bootstrap.php';
        $worker    = $this->holdDirectory . '/worker.php';

        $listChildDescriptors = static fn(string $file): string => sprintf(
            "file_put_contents(getenv('SCONCUR_TEST_HOLD_DIR') . '/%s', (string) shell_exec('ls -l /proc/self/fd'));\n",
            $file,
        );

        file_put_contents(
            $bootstrap,
            "<?php\nrequire " . var_export($root . '/vendor/autoload.php', true) . ";\n"
            . $listChildDescriptors('bootstrap-child.txt'),
        );

        file_put_contents(
            $worker,
            "<?php\n" . $listChildDescriptors('worker-child.txt')
            . 'require ' . var_export($root . '/tests/servers/http/http-server.php', true) . ";\n",
        );

        $master = $this->startMaster(
            options: [
                'workerCount'  => 1,
                'workerScript' => $worker,
                'bootstrap'    => $bootstrap,
            ],
        );

        try {
            $forkServerPid = $this->forkServerPid(
                master: $master,
                generation: 1,
            );

            $workerPid = $master->workerPid();

            self::assertGreaterThan(0, $workerPid, 'the worker should answer');

            $eventsPipe = (string) readlink(sprintf('/proc/%d/fd/3', $forkServerPid));

            self::assertStringStartsWith('pipe:', $eventsPipe, 'the fork server holds its events pipe as 3');

            $forkServerHolds = $this->descriptorsOn(
                pid: $forkServerPid,
                target: $eventsPipe,
            );

            self::assertSame([3], $forkServerHolds, 'the fork server holds a copy of its events pipe');

            $flags = (string) file_get_contents(sprintf('/proc/%d/fdinfo/3', $forkServerPid));

            self::assertMatchesRegularExpression('/^flags:\s+0*2\d{6}$/m', $flags, 'the events pipe is not close-on-exec');

            self::assertSame(
                [],
                $this->descriptorsOn(
                    pid: $workerPid,
                    target: $eventsPipe,
                ),
                'the worker holds the fork server\'s events pipe',
            );

            foreach (['bootstrap-child.txt' => 'the bootstrap', 'worker-child.txt' => 'the worker'] as $file => $who) {
                $listing = (string) @file_get_contents($this->holdDirectory . '/' . $file);

                self::assertNotSame('', $listing, sprintf('a program %s started should have listed its descriptors', $who));
                self::assertStringNotContainsString(
                    $eventsPipe,
                    $listing,
                    sprintf('a program %s started inherited the events pipe', $who),
                );
            }
        } finally {
            $master->stop();
        }
    }

    /**
     * The descriptors of a process that are open on `target`, as readlink names it.
     *
     * @return list<int>
     */
    protected function descriptorsOn(int $pid, string $target): array
    {
        $descriptors = [];

        foreach ((array) glob(sprintf('/proc/%d/fd/*', $pid)) as $path) {
            if (@readlink((string) $path) === $target) {
                $descriptors[] = (int) basename((string) $path);
            }
        }

        sort($descriptors);

        return $descriptors;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function startMaster(array $options, bool $waitReachable = true): TestWorkerMaster
    {
        return TestWorkerMaster::start(
            options: [
                'launch'       => 'fork',
                'workerScript' => dirname(__DIR__, 2) . '/servers/worker/held-slot-worker.php',
                // The tests ask for pids hundreds of times; the journal is read for the rest.
                'accessLog'    => 'off',
                ...$options,
            ],
            env: ['SCONCUR_TEST_HOLD_DIR' => $this->holdDirectory],
            waitReachable: $waitReachable,
        );
    }

    /** Makes the workers of a slot started from now on run without ever saying they are up. */
    protected function holdSlot(int $slot): void
    {
        touch($this->holdDirectory . '/hold-' . $slot);
    }

    protected function unholdSlot(int $slot): void
    {
        unlink($this->holdDirectory . '/hold-' . $slot);
    }

    /** Asks the master to reload its config, as the `reload` command does, without waiting for it. */
    protected function requestReload(TestWorkerMaster $master): string
    {
        $nonce = $this->reloadFile($master)->request(configPath: $master->configPath());

        self::assertNotNull($nonce, 'the reload trigger should be written');

        return $nonce;
    }

    /** Waits until the master is done with the reload, and answers why it gave the reload up. */
    protected function waitForReloadToEnd(
        TestWorkerMaster $master,
        string $nonce,
        float $timeoutSeconds = 15.0,
    ): string {
        $reloadFile = $this->reloadFile($master);

        $ended = $this->waitFor(
            condition: static function () use ($reloadFile): bool {
                // The master removes the trigger, and PHP would answer from its stat cache.
                clearstatcache();

                return !$reloadFile->requested();
            },
            timeoutSeconds: $timeoutSeconds,
        );

        self::assertTrue($ended, 'the reload should end: ' . $master->logText());

        $failure = $reloadFile->failure($nonce);

        self::assertNotNull($failure, 'the reload should be given up: ' . $master->logText());

        return $failure;
    }

    protected function reloadFile(TestWorkerMaster $master): MasterReloadFile
    {
        return new MasterReloadFile($master->runtimeDir() . '/' . $master->name() . '.reload');
    }

    /** The pid of a generation's fork server, as the master wrote it down when it started it. */
    protected function forkServerPid(TestWorkerMaster $master, int $generation): int
    {
        $pattern = sprintf('/fork server of generation %d started pid=(\d+)/', $generation);

        $started = $this->waitFor(
            condition: static fn(): bool => preg_match($pattern, $master->logText()) === 1,
            timeoutSeconds: 10.0,
        );

        self::assertTrue($started, sprintf('generation %d should start: %s', $generation, $master->logText()));

        preg_match($pattern, $master->logText(), $matches);

        return (int) $matches[1];
    }

    /** Asserts that the group serves at full strength — two workers — from one fork server's generation. */
    protected function assertWorkersOf(TestWorkerMaster $master, int $forkServerPid, string $message): void
    {
        $parents = [];

        $served = $this->waitFor(
            condition: static function () use ($master, $forkServerPid, &$parents): bool {
                $parents = [];

                foreach ($master->distinctWorkerPids(attempts: 40) as $pid) {
                    $parents[$pid] = self::parentPid($pid);
                }

                return array_values($parents) === [$forkServerPid, $forkServerPid];
            },
            timeoutSeconds: 8.0,
        );

        self::assertTrue(
            $served,
            sprintf(
                '%s: the workers serving (pid => parent) are %s, not two of fork server %d. %s',
                $message,
                json_encode($parents),
                $forkServerPid,
                $master->logText(),
            ),
        );
    }

    protected function assertJournalSays(TestWorkerMaster $master, string $needle, string $message): void
    {
        $said = $this->waitFor(
            condition: static fn(): bool => str_contains($master->logText(), $needle),
            timeoutSeconds: 10.0,
        );

        self::assertTrue($said, $message . ': ' . $master->logText());
    }

    /**
     * The config a running test master was started with, as a reload would read it, with
     * the pool's settings overridden.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function reloadConfig(TestWorkerMaster $master, array $overrides): array
    {
        $config = json_decode((string) file_get_contents($master->configPath()), true);

        self::assertIsArray($config);
        self::assertIsArray($config['groups'][0] ?? null);

        $config['groups'][0] = [...$config['groups'][0], ...$overrides];

        return $config;
    }

    protected static function parentPid(int $pid): int
    {
        $status = (string) @file_get_contents('/proc/' . $pid . '/status');

        return preg_match('/^PPid:\s+(\d+)$/m', $status, $matches) === 1 ? (int) $matches[1] : 0;
    }

    protected function waitFor(Closure $condition, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }

            usleep(20_000);
        }

        return $condition();
    }
}
