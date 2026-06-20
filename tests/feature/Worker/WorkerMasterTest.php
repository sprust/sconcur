<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use Closure;
use PHPUnit\Framework\TestCase;
use SConcur\Worker\MasterCli;
use SConcur\Tests\Impl\Worker\TestWorkerMaster;

/**
 * End-to-end coverage of WorkerMaster via the bin/sconcur-server CLI: the
 * master spawns demo workers (HttpServer + SO_REUSEPORT + masterPid) and we observe
 * supervision, restarts, graceful shutdown, single-instance, status/stop, orphan
 * self-termination and crash-loop backoff. Each test manages its own master.
 */
class WorkerMasterTest extends TestCase
{
    public function testSpawnsWorkersAndAllServe(): void
    {
        $master = TestWorkerMaster::start(['workerCount' => 2]);

        try {
            $pids = $master->distinctWorkerPids();

            self::assertGreaterThanOrEqual(2, count($pids), 'both workers should serve requests');
            self::assertTrue($master->isRunning());

            self::assertStringContainsString('start workers=2', $master->logText());
            self::assertNotNull($master->readState(), 'a state file must exist while running');
        } finally {
            $master->stop();
        }
    }

    public function testRestartsKilledWorker(): void
    {
        $master = TestWorkerMaster::start(['workerCount' => 2]);

        try {
            $before = $master->distinctWorkerPids();

            self::assertNotEmpty($before);

            // Kill one worker outright; the Always policy must respawn it.
            posix_kill($before[0], SIGKILL);

            $restarted = $this->waitFor(
                static fn(): bool => array_diff($master->distinctWorkerPids(40), $before) !== [],
                timeoutSeconds: 6.0,
            );

            self::assertTrue($restarted, 'master should respawn a killed worker');

            // A signal-killed worker (the OOM killer sends SIGKILL too) is logged as
            // a signal death and restarted under the default Always policy.
            self::assertStringContainsString('signal=', $master->logText());
        } finally {
            $master->stop();
        }
    }

    public function testOnFailurePolicyDoesNotRestartCleanExit(): void
    {
        // OnFailure: a clean exit (here via the maxRequests quota) is "done", so the
        // single worker is not respawned and the master finishes on its own.
        $master = TestWorkerMaster::start(
            options: ['workerCount' => 1, 'restartPolicy' => 'on-failure'],
            workerArgs: ['--maxRequests=2'],
        );

        try {
            for ($i = 0; $i < 4; $i++) {
                $master->get('/');
            }

            self::assertSame(
                0,
                $master->waitForExit(8.0),
                'master should exit once its only worker finishes cleanly under OnFailure',
            );
        } finally {
            $master->stop();
        }
    }

    public function testOnFailurePolicyRestartsCrashedWorker(): void
    {
        // OnFailure: a signal death (SIGKILL, as the OOM killer would send) is a
        // failure, so the worker must be respawned — the complement of the clean-exit
        // case above.
        $master = TestWorkerMaster::start(['workerCount' => 2, 'restartPolicy' => 'on-failure']);

        try {
            $before = $master->distinctWorkerPids();

            self::assertNotEmpty($before);

            posix_kill($before[0], SIGKILL);

            $restarted = $this->waitFor(
                static fn(): bool => array_diff($master->distinctWorkerPids(40), $before) !== [],
                timeoutSeconds: 6.0,
            );

            self::assertTrue($restarted, 'OnFailure must respawn a worker that died by signal');
            self::assertStringContainsString('signal=', $master->logText());
        } finally {
            $master->stop();
        }
    }

    public function testNeverPolicyDoesNotRestartKilledWorker(): void
    {
        $master = TestWorkerMaster::start(['workerCount' => 1, 'restartPolicy' => 'never']);

        try {
            $pid = $master->workerPid();

            self::assertGreaterThan(0, $pid);

            posix_kill($pid, SIGKILL);

            self::assertSame(
                0,
                $master->waitForExit(8.0),
                'master should exit once its only worker dies under Never',
            );
        } finally {
            $master->stop();
        }
    }

    public function testStatusReportsStoppedAfterMasterCrash(): void
    {
        $master = TestWorkerMaster::start(['workerCount' => 1]);

        try {
            self::assertGreaterThan(0, $master->workerPid());

            // Hard-kill the master: the kernel releases the lock, but the state file
            // (with the now-dead pid) stays behind.
            posix_kill($master->pid(), SIGKILL);
            $master->waitForExit(5.0);

            // Liveness is read from the lock, not the stale pid, so status reports
            // stopped — immune to PID reuse of the dead master's pid.
            [$code, $output] = TestWorkerMaster::runCommand('status', $master->configPath());

            self::assertSame(MasterCli::EXIT_NOT_RUNNING, $code);
            self::assertStringContainsString('stopped', $output);
        } finally {
            $master->stop();
        }
    }

    public function testRemovingStateFileDrainsInFlightAndStops(): void
    {
        // The state file doubles as the control file: removing it gracefully stops the
        // master and all its workers (in-flight requests still drain).
        $master = TestWorkerMaster::start(['workerCount' => 1]);

        try {
            self::assertNotNull($master->readState());

            $multi = curl_multi_init();
            $slow  = curl_init($master->baseUrl() . '/msleep/600');

            curl_setopt_array($slow, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
            ]);

            curl_multi_add_handle($multi, $slow);

            $running   = null;
            $pumpUntil = microtime(true) + 0.2;

            do {
                curl_multi_exec($multi, $running);
                usleep(10_000);
            } while (microtime(true) < $pumpUntil && $running > 0);

            // Stop by removing the state file (no signal).
            unlink($master->stateFilePath());

            do {
                curl_multi_exec($multi, $running);

                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0);

            $status = (int) curl_getinfo($slow, CURLINFO_HTTP_CODE);
            $body   = (string) curl_multi_getcontent($slow);

            curl_multi_remove_handle($multi, $slow);
            curl_close($slow);
            curl_multi_close($multi);

            self::assertSame(200, $status, 'the in-flight request must drain, not be dropped');
            self::assertSame('slept', $body);

            self::assertSame(0, $master->waitForExit(8.0), 'removing the state file must gracefully stop the master');
            self::assertSame(0, $master->workerPid(), 'all workers must be stopped with the master');
            self::assertStringContainsString('state file removed', $master->logText());
        } finally {
            $master->stop();
        }
    }

    public function testStartFailsForMissingWorkerScript(): void
    {
        $configPath = TestWorkerMaster::writeConfig(['workerScript' => '/no/such/worker.php']);

        [$code, $output] = TestWorkerMaster::runCommand('start', $configPath);

        self::assertSame(MasterCli::EXIT_ERROR, $code);
        self::assertStringContainsString('not found', $output);
    }

    public function testStartFailsForNegativeWorkerCount(): void
    {
        $configPath = TestWorkerMaster::writeConfig(['workerCount' => -1]);

        [$code, $output] = TestWorkerMaster::runCommand('start', $configPath);

        self::assertSame(MasterCli::EXIT_ERROR, $code);
        self::assertStringContainsString('workerCount', $output);
    }

    public function testWorkerSelfExitOnMaxRequestsIsRestarted(): void
    {
        $master = TestWorkerMaster::start(
            options: ['workerCount' => 1],
            workerArgs: ['--maxRequests=3'],
        );

        try {
            $first = $master->workerPid();

            self::assertGreaterThan(0, $first);

            // Exceed the per-worker request quota; the worker exits cleanly and the
            // master brings up a fresh one (new pid).
            for ($i = 0; $i < 6; $i++) {
                $master->get('/');
            }

            $replaced = $this->waitFor(
                static function () use ($master, $first): bool {
                    $pid = $master->workerPid();

                    return $pid > 0 && $pid !== $first;
                },
                timeoutSeconds: 6.0,
            );

            self::assertTrue($replaced, 'worker should be replaced after reaching maxRequests');
        } finally {
            $master->stop();
        }
    }

    public function testGracefulShutdownDrainsInFlightAndExitsClean(): void
    {
        $master = TestWorkerMaster::start(['workerCount' => 1]);

        try {
            $multi = curl_multi_init();
            $slow  = curl_init($master->baseUrl() . '/msleep/600');

            curl_setopt_array($slow, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
            ]);

            curl_multi_add_handle($multi, $slow);

            $running   = null;
            $pumpUntil = microtime(true) + 0.2;

            do {
                curl_multi_exec($multi, $running);
                usleep(10_000);
            } while (microtime(true) < $pumpUntil && $running > 0);

            // Request is in flight: ask the master to stop.
            $master->signal(SIGTERM);

            do {
                curl_multi_exec($multi, $running);

                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0);

            $status = (int) curl_getinfo($slow, CURLINFO_HTTP_CODE);
            $body   = (string) curl_multi_getcontent($slow);

            curl_multi_remove_handle($multi, $slow);
            curl_close($slow);
            curl_multi_close($multi);

            self::assertSame(200, $status, 'the in-flight request must be drained, not dropped');
            self::assertSame('slept', $body);

            self::assertSame(0, $master->waitForExit(8.0), 'master should exit cleanly after draining');
        } finally {
            $master->stop();
        }
    }

    public function testSecondInstanceIsRefused(): void
    {
        $master = TestWorkerMaster::start(['workerCount' => 1]);

        try {
            // A second master over the same runtime dir + name must fail fast on the
            // lock (it never reaches the supervision loop, so runCommand returns).
            $configPath = TestWorkerMaster::writeConfig([
                'runtimeDir' => $master->runtimeDir(),
                'name'       => $master->name(),
            ]);

            [$code, $output] = TestWorkerMaster::runCommand('start', $configPath);

            self::assertSame(MasterCli::EXIT_ERROR, $code);
            self::assertStringContainsString('lock', $output);
            self::assertTrue($master->isRunning(), 'the first master must keep running');
        } finally {
            $master->stop();
        }
    }

    public function testLogToBothMirrorsTheJournalToStdout(): void
    {
        // logTo=both: the master writes its journal to the daily file AND to its own
        // stdout, so `docker logs` (here: the captured master output) sees it too.
        $master = TestWorkerMaster::start(['workerCount' => 1, 'logTo' => 'both']);

        try {
            self::assertGreaterThan(0, $master->workerPid());

            $appeared = $this->waitFor(
                static fn(): bool => str_contains($master->masterOutput(), 'start workers=1'),
                timeoutSeconds: 4.0,
            );

            self::assertTrue($appeared, 'the journal must be mirrored to the master stdout');
            self::assertStringContainsString('spawned', $master->masterOutput());

            // The file sink still works alongside stdout.
            self::assertStringContainsString('start workers=1', $master->logText());
        } finally {
            $master->stop();
        }
    }

    public function testStatusAndStopCommands(): void
    {
        $master = TestWorkerMaster::start(['workerCount' => 1]);

        $configPath = $master->configPath();

        try {
            [$statusCode, $statusOut] = TestWorkerMaster::runCommand('status', $configPath);

            self::assertSame(MasterCli::EXIT_OK, $statusCode);
            self::assertStringContainsString('running', $statusOut);

            [$stopCode, $stopOut] = TestWorkerMaster::runCommand('stop', $configPath);

            self::assertSame(MasterCli::EXIT_OK, $stopCode);
            self::assertStringContainsString('stopped', $stopOut);

            self::assertSame(0, $master->waitForExit(8.0), 'stop should let the master exit cleanly');

            [$afterCode, $afterOut] = TestWorkerMaster::runCommand('status', $configPath);

            self::assertSame(MasterCli::EXIT_NOT_RUNNING, $afterCode);
            self::assertStringContainsString('stopped', $afterOut);
        } finally {
            $master->stop();
        }
    }

    public function testOrphanedWorkersSelfTerminate(): void
    {
        $master = TestWorkerMaster::start(['workerCount' => 2]);

        try {
            self::assertGreaterThan(0, $master->workerPid());

            // Hard-kill the master (no graceful forward): workers are orphaned.
            posix_kill($master->pid(), SIGKILL);

            // The masterPid orphan-check notices the parent changed and each
            // worker drains and exits, freeing the port.
            $freed = $this->waitFor(
                static fn(): bool => $master->workerPid() === 0,
                timeoutSeconds: 8.0,
            );

            self::assertTrue($freed, 'orphaned workers must self-terminate and free the port');
        } finally {
            $master->stop();
        }
    }

    public function testCrashLoopIsThrottledByBackoff(): void
    {
        // An unbindable address (TEST-NET-1, not assignable locally) makes the worker
        // fail on start and exit non-zero immediately — a crash to throttle.
        $master = TestWorkerMaster::start(
            options: ['workerCount' => 1, 'restartBackoffMs' => 200, 'address' => '192.0.2.1:9099'],
            waitReachable: false,
        );

        try {
            // With exponential backoff the master must not spin — only a handful of
            // restart attempts fit in this window.
            usleep(2_000_000);

            $log      = $master->logText();
            $restarts = substr_count($log, 'restarting in');

            self::assertGreaterThanOrEqual(1, $restarts, 'the crashing worker should be restarted');
            self::assertLessThan(15, $restarts, 'backoff must throttle the crash loop');
        } finally {
            $master->stop();
        }
    }

    public function testReloadRollsEveryWorkerWithoutDowntime(): void
    {
        $master = TestWorkerMaster::start(['workerCount' => 2]);

        try {
            $before = $master->distinctWorkerPids();

            self::assertGreaterThanOrEqual(2, count($before), 'both workers should serve before reload');

            // The reload command blocks until the master has rolled every worker.
            [$code, $output] = TestWorkerMaster::runCommand('reload', $master->configPath());

            self::assertSame(MasterCli::EXIT_OK, $code, 'reload should succeed: ' . $output);
            self::assertStringContainsString('reloaded', $output);

            // After a full roll the serving pids are all fresh — disjoint from the old
            // set — and the server kept answering throughout (master still running).
            $rolled = $this->waitFor(
                static function () use ($master, $before): bool {
                    $after = $master->distinctWorkerPids(40);

                    return count($after) >= 2 && array_intersect($before, $after) === [];
                },
                timeoutSeconds: 6.0,
            );

            self::assertTrue($rolled, 'after reload every worker pid must be fresh and serving');
            self::assertTrue($master->isRunning());

            [$status, $body] = $master->get('/');

            self::assertSame(200, $status, 'the server must stay reachable after a reload');
            self::assertSame('ok', $body);

            self::assertStringContainsString('reload requested', $master->logText());
            self::assertStringContainsString('reload complete', $master->logText());
        } finally {
            $master->stop();
        }
    }

    public function testReloadReportsNotRunningWithoutMaster(): void
    {
        $configPath = TestWorkerMaster::writeConfig(['workerCount' => 1]);

        [$code, $output] = TestWorkerMaster::runCommand('reload', $configPath);

        self::assertSame(MasterCli::EXIT_NOT_RUNNING, $code);
        self::assertStringContainsString('not running', $output);
    }

    /**
     * Polls $condition until it returns true or the timeout elapses.
     *
     * @param Closure(): bool $condition
     */
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
