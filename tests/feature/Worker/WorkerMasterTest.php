<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use Closure;
use PHPUnit\Framework\TestCase;
use SConcur\Worker\MasterCli;
use SConcur\Worker\WorkerMaster;
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

            self::assertStringContainsString('start groups=1 workers=2', $master->logText());
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

    public function testAHungWorkerIsKilledAndReplaced(): void
    {
        // One worker, so the request certainly lands on the one being watched, at the
        // lowest threshold the config accepts.
        $master = TestWorkerMaster::start([
            'workerCount'       => 1,
            'watchdogTimeoutMs' => 5_000,
        ]);

        try {
            $before = $master->workerPid();

            self::assertGreaterThan(0, $before, 'the worker must answer before it is frozen');

            // Freezes the worker's PHP thread inside a native call: nothing preempts it,
            // so the serve loop stops turning and the worker's mark goes stale. The
            // client gives up on the request long before the sleep ends; the worker does
            // not.
            $master->get('/native-msleep/20000');

            $replaced = $this->waitFor(
                static function () use ($master, $before): bool {
                    $pid = $master->workerPid();

                    return $pid !== 0 && $pid !== $before;
                },
                timeoutSeconds: 25.0,
            );

            self::assertTrue($replaced, 'the master should kill a hung worker and bring up a replacement');

            $log = $master->logText();

            self::assertStringContainsString('no heartbeat for', $log);
            self::assertStringContainsString('sending SIGTERM', $log);

            // A watchdog kill does not count as a healthy worker finishing, however long it
            // had been up, so the replacement waits out a backoff instead of coming up at
            // once — a pool hung by a shared cause would otherwise recycle itself forever.
            self::assertMatchesRegularExpression('/restarting in [1-9]\d*ms/', $log);
        } finally {
            $master->stop();
        }
    }

    public function testAnIdleWorkerIsNotMistakenForAHungOne(): void
    {
        // The common case at night: nothing is requested for longer than the threshold.
        // The serve loop still passes its top every SERVE_POLL_INTERVAL_MS, so the marks
        // keep coming with no traffic at all.
        $master = TestWorkerMaster::start([
            'workerCount'       => 1,
            'watchdogTimeoutMs' => 5_000,
        ]);

        try {
            $before = $master->workerPid();

            self::assertGreaterThan(0, $before);

            // Twice the threshold, and not one request in it.
            sleep(11);

            self::assertStringNotContainsString(
                'no heartbeat for',
                $master->logText(),
                'an idle worker marks itself alive without serving anything',
            );
            self::assertSame($before, $master->workerPid(), 'the idle worker kept its slot');
        } finally {
            $master->stop();
        }
    }

    public function testAPreemptedCpuBoundHandlerKeepsItsSlot(): void
    {
        // With preemption armed, a handler that computes for longer than the whole
        // watchdog threshold must not be mistaken for a hang: the scheduler parks its
        // coroutine and goes back round the serve loop, so the marks keep coming while
        // it works.
        $master = TestWorkerMaster::start(
            options: ['workerCount' => 1, 'watchdogTimeoutMs' => 5_000],
            workerArgs: ['--preemptionQuantumMs=5'],
        );

        try {
            $before = $master->workerPid();

            self::assertGreaterThan(0, $before);

            // 9 s of hashing, no I/O and no explicit switch() in the handler. Stated as a
            // duration, so the machine's speed cannot turn this into a handler that
            // finishes before the threshold and proves nothing.
            $master->get('/cpu-ms/9000');

            self::assertFalse(
                $this->waitFor(
                    static fn(): bool => str_contains($master->logText(), 'no heartbeat for'),
                    timeoutSeconds: 8.0,
                ),
                'a preempted handler keeps marking its worker alive',
            );

            self::assertSame($before, $master->workerPid(), 'the worker kept its slot');
        } finally {
            $master->stop();
        }
    }

    public function testACpuBoundHandlerWithoutPreemptionIsTreatedAsAHang(): void
    {
        // The other side of the same coin: with preemption off the handler never gives
        // control back, so nothing in the process is served while it computes and the
        // worker is indistinguishable from a hung one — the watchdog treats it as one.
        $master = TestWorkerMaster::start(
            options: ['workerCount' => 1, 'watchdogTimeoutMs' => 5_000],
            workerArgs: ['--preemptionQuantumMs=0'],
        );

        try {
            $before = $master->workerPid();

            self::assertGreaterThan(0, $before);

            $master->get('/cpu-ms/9000');

            $replaced = $this->waitFor(
                static function () use ($master, $before): bool {
                    $pid = $master->workerPid();

                    return $pid !== 0 && $pid !== $before;
                },
                timeoutSeconds: 25.0,
            );

            self::assertTrue($replaced, 'without preemption the computing worker is replaced');
            self::assertStringContainsString('no heartbeat for', $master->logText());
        } finally {
            $master->stop();
        }
    }

    public function testAWorkerWithNoServerMarksItselfThroughTheScheduler(): void
    {
        // No serve loop at all — an ordinary WaitGroup loop, the shape a pool of periodic
        // tasks has. It drives the scheduler, so it marks itself alive like a server does.
        $master = TestWorkerMaster::start(
            options: [
                'workerCount'       => 1,
                'watchdogTimeoutMs' => 5_000,
                'workerScript'      => dirname(__DIR__, 2) . '/servers/worker/waitgroup-worker.php',
                'server'            => [],
            ],
            waitReachable: false,
        );

        try {
            self::assertTrue(
                $this->waitFor(
                    static fn(): bool => str_contains($master->logText(), 'waitgroup worker started'),
                    timeoutSeconds: 10.0,
                ),
                'the worker should come up',
            );

            sleep(11);

            self::assertStringNotContainsString('no heartbeat for', $master->logText());
            self::assertSame(
                1,
                substr_count($master->logText(), 'waitgroup worker started'),
                'the worker was never replaced',
            );
        } finally {
            $master->stop();
        }
    }

    public function testAWorkerWithNoServerIsKilledWhenItsSchedulerStops(): void
    {
        // The same worker, frozen in a native call after half a second: it stops driving
        // the scheduler, so it stops marking itself and the watchdog replaces it.
        $master = TestWorkerMaster::start(
            options: [
                'workerCount'       => 1,
                'watchdogTimeoutMs' => 5_000,
                'workerScript'      => dirname(__DIR__, 2) . '/servers/worker/waitgroup-worker.php',
                'server'            => [],
            ],
            workerArgs: ['--hangAfterMs=500'],
            waitReachable: false,
        );

        try {
            self::assertTrue(
                $this->waitFor(
                    static fn(): bool => str_contains($master->logText(), 'no heartbeat for'),
                    timeoutSeconds: 20.0,
                ),
                'a worker that stopped driving the scheduler should be caught',
            );

            self::assertTrue(
                $this->waitFor(
                    static fn(): bool => substr_count($master->logText(), 'waitgroup worker started') >= 2,
                    timeoutSeconds: 20.0,
                ),
                'and replaced',
            );
        } finally {
            $master->stop();
        }
    }

    public function testWorkersAreLeftAloneWhileTheWatchdogIsOff(): void
    {
        // The same freeze with the watchdog disabled: the worker keeps its slot, which is
        // what every other test in this file relies on while it holds a worker busy.
        $master = TestWorkerMaster::start([
            'workerCount'       => 1,
            'watchdogTimeoutMs' => 0,
        ]);

        try {
            $before = $master->workerPid();

            self::assertGreaterThan(0, $before);

            $master->get('/native-msleep/6000');

            self::assertFalse(
                $this->waitFor(
                    static fn(): bool => str_contains($master->logText(), 'no heartbeat for'),
                    timeoutSeconds: 3.0,
                ),
                'a zero threshold must switch the watchdog off entirely',
            );

            self::assertSame($before, $master->workerPid(), 'the frozen worker keeps its slot');
        } finally {
            $master->stop();
        }
    }

    public function testOnFailurePolicyDoesNotRestartCleanExit(): void
    {
        // OnFailure: a worker that finishes cleanly of its own accord is "done", so the
        // single worker is not respawned and the master finishes on its own.
        $workerScript = (string) tempnam(sys_get_temp_dir(), 'sc-done-worker-');

        file_put_contents($workerScript, "<?php\nusleep(200_000);\nexit(0);\n");

        $master = TestWorkerMaster::start(
            options: [
                'workerScript'  => $workerScript,
                'workerCount'   => 1,
                'restartPolicy' => 'on-failure',
            ],
            waitReachable: false,
        );

        try {
            self::assertSame(
                0,
                $master->waitForExit(8.0),
                'master should exit once its only worker finishes cleanly under OnFailure: ' . $master->logText(),
            );

            self::assertStringContainsString('not restarting (policy=on-failure)', $master->logText());
        } finally {
            $master->stop();

            @unlink($workerScript);
        }
    }

    public function testOnFailurePolicyRestartsAWorkerAtItsLimit(): void
    {
        // A worker that reaches its maxRequests asks to be replaced, and OnFailure replaces
        // it: that clean exit is not the worker being done, it is the worker being recycled.
        $master = TestWorkerMaster::start(
            options: ['workerCount' => 1, 'restartPolicy' => 'on-failure'],
            workerArgs: ['--maxRequests=2'],
        );

        try {
            $first = $master->workerPid();

            // Past the limit wherever the spread put it: 2 to 3 under a master.
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

            self::assertTrue($replaced, 'the worker must be replaced at its limit: ' . $master->logText());
            self::assertTrue($master->isRunning(), 'the master keeps supervising');
            self::assertStringContainsString('its limit', $master->logText());
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
        // Caught while the config is read, not once the master is up: a usage error.
        $configPath = TestWorkerMaster::writeConfig(['workerCount' => -1]);

        [$code, $output] = TestWorkerMaster::runCommand('start', $configPath);

        self::assertSame(MasterCli::EXIT_USAGE, $code);
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
                static fn(): bool => str_contains($master->masterOutput(), 'start groups=1 workers=1'),
                timeoutSeconds: 4.0,
            );

            self::assertTrue($appeared, 'the journal must be mirrored to the master stdout');
            self::assertStringContainsString('spawned', $master->masterOutput());

            // The file sink still works alongside stdout.
            self::assertStringContainsString('start groups=1 workers=1', $master->logText());
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

    public function testWorkerFatalErrorTextIsLoggedEvenWithDisplayErrorsOff(): void
    {
        // A worker dying on startup must leave its reason in the journal even when
        // the deployment php.ini says display_errors=Off (the production
        // recommendation): the master forces display_errors=stderr into the worker
        // command, so the fatal text arrives on stderr and is logged at ERROR.
        $workerScript = (string) tempnam(sys_get_temp_dir(), 'sc-fatal-worker-');
        $iniPath      = (string) tempnam(sys_get_temp_dir(), 'sc-worker-ini-');

        file_put_contents($workerScript, "<?php\nthrow new RuntimeException('boom on worker startup');\n");
        file_put_contents($iniPath, "display_errors=Off\n");

        $master = TestWorkerMaster::start(
            options: [
                'workerScript'  => $workerScript,
                'workerCount'   => 1,
                'restartPolicy' => 'never',
                'phpArgs'       => [
                    '-c',
                    $iniPath,
                ],
            ],
            waitReachable: false,
        );

        try {
            // The only worker died before it was up: the group never came up, and the
            // master says so with its exit code instead of finishing as if all was well.
            $exitCode = $master->waitForExit(8.0);

            $logText = $master->logText();

            self::assertSame(WorkerMaster::EXIT_NEVER_CAME_UP, $exitCode, 'the master should stop: ' . $logText);
            self::assertStringContainsString('no worker came up', $logText);
            self::assertStringContainsString('boom on worker startup', $logText);
            self::assertMatchesRegularExpression(
                '/ERROR \[worker: \d+ \S+ #0\]: [^\n]*boom on worker startup/',
                $logText,
                'the fatal text must be logged at ERROR (stderr), not INFO',
            );
        } finally {
            $master->stop();

            @unlink($workerScript);
            @unlink($iniPath);
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

    /**
     * Each replacement has to say it is up before the worker it replaces is asked to stop,
     * so the group serves at full strength throughout the roll.
     */
    public function testAReloadStopsAWorkerOnlyOnceItsReplacementIsUp(): void
    {
        $master = TestWorkerMaster::start(['workerCount' => 2]);

        try {
            [$code, $output] = TestWorkerMaster::runCommand('reload', $master->configPath());

            self::assertSame(MasterCli::EXIT_OK, $code, 'reload should succeed: ' . $output);

            $log = $master->logText();

            foreach ([0, 1] as $slot) {
                $spawned = $this->journalPosition($log, sprintf('#%d]: spawned as a replacement', $slot));
                $up      = $this->journalPosition($log, sprintf('#%d]: up; took over the slot', $slot));
                $stopped = $this->journalPosition($log, sprintf('#%d]: reloading; sending SIGTERM', $slot));

                self::assertLessThan($up, $spawned, 'slot ' . $slot . ': the replacement comes first: ' . $log);
                self::assertLessThan($stopped, $up, 'slot ' . $slot . ': the old worker stops after: ' . $log);
            }
        } finally {
            $master->stop();
        }
    }

    /**
     * A version that dies on startup goes no further than its first replacement: the reload
     * is given up, the CLI says so, the workers it had not reached keep serving, and a
     * worker that crashes afterwards comes back on the settings that were running.
     */
    public function testAReloadWhoseReplacementDiesIsGivenUp(): void
    {
        $brokenScript = (string) tempnam(sys_get_temp_dir(), 'sc-broken-worker-');

        file_put_contents($brokenScript, "<?php\nthrow new RuntimeException('broken release');\n");

        $master = TestWorkerMaster::start(['workerCount' => 2]);

        try {
            $before = $master->distinctWorkerPids();

            self::assertCount(2, $before);

            $master->rewriteConfig($this->reloadConfig($master, ['workerScript' => $brokenScript]));

            [$code, $output] = TestWorkerMaster::runCommand('reload', $master->configPath());

            self::assertSame(MasterCli::EXIT_ERROR, $code, 'the CLI must report the failure: ' . $output);
            self::assertStringContainsString('reload failed', $output);
            self::assertStringContainsString('before it was up', $output);
            self::assertStringContainsString('reload given up', $master->logText());
            self::assertStringNotContainsString('reloading; sending SIGTERM', $master->logText());
            self::assertSame(
                1,
                substr_count($master->logText(), 'reload requested'),
                'a request given up is over: it must not be read and rolled again',
            );

            self::assertTrue($master->isRunning());
            self::assertEqualsCanonicalizing($before, $master->distinctWorkerPids(), 'no worker was replaced');

            // The settings the reload brought were put back: a crash now respawns the
            // worker that was running, not the broken one.
            posix_kill($before[0], SIGKILL);

            $respawned = $this->waitFor(
                static fn(): bool => array_diff($master->distinctWorkerPids(40), $before) !== [],
                timeoutSeconds: 6.0,
            );

            self::assertTrue($respawned, 'the killed worker must come back serving: ' . $master->logText());
        } finally {
            $master->stop();

            @unlink($brokenScript);
        }
    }

    /**
     * A replacement that runs but never says it is up — a script without a serve loop that
     * does not call Scheduler::markReady() — cannot be told from one that hangs while it
     * loads, and the reload is given up on it after readyTimeoutMs.
     */
    public function testAReloadWhoseReplacementNeverSaysItIsUpIsGivenUp(): void
    {
        $silentScript = (string) tempnam(sys_get_temp_dir(), 'sc-silent-worker-');

        file_put_contents($silentScript, "<?php\nsleep(30);\n");

        $master = TestWorkerMaster::start(['workerCount' => 1]);

        try {
            $before = $master->workerPid();

            $master->rewriteConfig(
                $this->reloadConfig(
                    master: $master,
                    overrides: [
                        'workerScript'   => $silentScript,
                        'readyTimeoutMs' => 1_000,
                    ],
                ),
            );

            [$code, $output] = TestWorkerMaster::runCommand('reload', $master->configPath());

            self::assertSame(MasterCli::EXIT_ERROR, $code, 'the CLI must report the failure: ' . $output);
            self::assertStringContainsString('did not say it was up within 1000ms', $output);

            self::assertTrue($master->isRunning());
            self::assertSame($before, $master->workerPid(), 'the serving worker stays');
        } finally {
            $master->stop();

            @unlink($silentScript);
        }
    }

    /**
     * A worker script that does its work in a loop of its own says it is up with
     * Scheduler::markReady(), and is then reloaded like a server.
     */
    public function testAWorkerWithNoServerThatSaysItIsUpCanBeReloaded(): void
    {
        $master = TestWorkerMaster::start(
            options: [
                'workerCount'  => 1,
                'workerScript' => dirname(__DIR__, 2) . '/servers/worker/waitgroup-worker.php',
                'server'       => [],
            ],
            waitReachable: false,
        );

        try {
            self::assertTrue(
                $this->waitFor(
                    static fn(): bool => str_contains($master->logText(), 'waitgroup worker started'),
                    timeoutSeconds: 10.0,
                ),
                'the worker should come up',
            );

            [$code, $output] = TestWorkerMaster::runCommand('reload', $master->configPath());

            self::assertSame(MasterCli::EXIT_OK, $code, 'reload should succeed: ' . $output);
            self::assertSame(2, substr_count($master->logText(), 'waitgroup worker started'));
        } finally {
            $master->stop();
        }
    }

    /**
     * A group with `listen` accepts on one socket the master bound, rather than on a socket
     * each worker binds with SO_REUSEPORT. Both workers serve from it.
     */
    public function testWorkersShareTheMastersListeningSocket(): void
    {
        $port = TestWorkerMaster::freePort();

        $master = TestWorkerMaster::start(
            options: [
                'workerCount' => 2,
                'listen'      => '127.0.0.1:' . $port,
                'server'      => [],
            ],
            port: $port,
        );

        try {
            self::assertGreaterThanOrEqual(2, count($master->distinctWorkerPids()), 'both workers should serve');

            // The journal is written once per supervision tick, so the banner may trail the
            // first answers by one.
            self::assertTrue(
                $this->waitFor(
                    static fn(): bool => str_contains($master->logText(), 'listening on fd'),
                    5.0,
                ),
                'the workers must say they accept on the inherited socket: ' . $master->logText(),
            );
        } finally {
            $master->stop();
        }
    }

    /**
     * The point of the shared socket: every worker accepts from one queue, so a rolling
     * reload, which stops the workers one after another, leaves no request without a
     * worker to take it. Requests run the whole time on fresh connections, from a process
     * of their own, and not one of them may fail.
     */
    public function testAReloadOnTheSharedSocketLosesNoRequest(): void
    {
        $port = TestWorkerMaster::freePort();

        $master = TestWorkerMaster::start(
            options: [
                'workerCount' => 2,
                'listen'      => '127.0.0.1:' . $port,
                'server'      => [],
            ],
            port: $port,
        );

        $stopFile = $master->runtimeDir() . '/stop-load';

        $load = proc_open(
            command: [
                PHP_BINARY,
                '-r',
                <<<'PHP'
                $ok     = 0;
                $failed = [];

                while (!file_exists($argv[2])) {
                    $body = @file_get_contents('http://' . $argv[1] . '/', false, stream_context_create([
                        'http' => ['timeout' => 5],
                    ]));

                    if ($body === 'ok') {
                        ++$ok;
                    } else {
                        $failed[] = error_get_last()['message'] ?? 'an empty answer';
                    }
                }

                echo json_encode(['ok' => $ok, 'failed' => $failed]);
                PHP,
                '127.0.0.1:' . $port,
                $stopFile,
            ],
            descriptor_spec: [1 => ['pipe', 'w']],
            pipes: $pipes,
        );

        self::assertIsResource($load);

        try {
            usleep(300_000);

            [$code, $output] = TestWorkerMaster::runCommand('reload', $master->configPath());

            self::assertSame(MasterCli::EXIT_OK, $code, 'reload should succeed: ' . $output);

            usleep(300_000);
        } finally {
            touch($stopFile);

            $result = json_decode((string) stream_get_contents($pipes[1]), true);

            proc_close($load);

            $master->stop();
        }

        self::assertIsArray($result);
        self::assertGreaterThan(0, $result['ok']);
        self::assertSame([], $result['failed'], 'requests failed during the reload');
    }

    /**
     * The workers serving now accept on the socket the master bound at the start: a reload
     * cannot move it, and says so instead of rolling the pool onto a port nobody listens on.
     */
    public function testAReloadThatMovesTheListeningSocketIsRefused(): void
    {
        $port = TestWorkerMaster::freePort();

        $master = TestWorkerMaster::start(
            options: [
                'workerCount' => 1,
                'listen'      => '127.0.0.1:' . $port,
                'server'      => [],
            ],
            port: $port,
        );

        try {
            $pid = $master->workerPid();

            /** @var array{groups: list<array<string, mixed>>} $config */
            $config = json_decode((string) file_get_contents($master->configPath()), true);

            $config['groups'][0]['listen'] = '127.0.0.1:' . TestWorkerMaster::freePort();

            $movedPath = $master->runtimeDir() . '/moved.json';

            file_put_contents($movedPath, (string) json_encode($config));
            file_put_contents($master->runtimeDir() . '/' . $master->name() . '.reload', $movedPath . "\n");

            self::assertTrue(
                $this->waitFor(
                    static fn(): bool => str_contains($master->logText(), 'reload refused'),
                    10.0,
                ),
                'the master must report the refusal: ' . $master->logText(),
            );

            self::assertStringContainsString('does not move on a reload', $master->logText());
            self::assertSame($pid, $master->workerPid(), 'the worker must be left as it was');
        } finally {
            $master->stop();
        }
    }

    public function testStartFailsWhenTheListenAddressIsTaken(): void
    {
        $taken = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        self::assertNotFalse($taken, $errorMessage);

        $address = (string) stream_socket_get_name($taken, false);

        $master = TestWorkerMaster::start(
            options: [
                'workerCount' => 1,
                'listen'      => $address,
                'server'      => [],
            ],
            waitReachable: false,
        );

        try {
            self::assertSame(MasterCli::EXIT_ERROR, $master->waitForExit(8.0));
            self::assertStringContainsString('cannot listen on ' . $address, $master->masterOutput());
        } finally {
            $master->stop();

            fclose($taken);
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
     * Two pools under one master: one supervisor, one lock, one journal. Each group
     * numbers its own slots, so the journal names the group beside the index.
     */
    public function testOneMasterSupervisesSeveralGroups(): void
    {
        $master = TestWorkerMaster::start(
            options: [
                'groups' => [
                    [
                        'name'         => 'alpha',
                        'workerScript' => self::demoWorkerScript(),
                        'workerCount'  => 1,
                        'server'       => ['address' => '127.0.0.1:0', 'reusePort' => true],
                    ],
                    [
                        'name'         => 'beta',
                        'workerScript' => self::demoWorkerScript(),
                        'workerCount'  => 2,
                        'server'       => ['address' => '127.0.0.1:0', 'reusePort' => true],
                    ],
                ],
            ],
            waitReachable: false,
        );

        try {
            self::assertTrue(
                $this->waitFor(
                    static fn(): bool => str_contains($master->logText(), 'beta #1'),
                    5.0,
                ),
                'both groups must have spawned their workers',
            );

            self::assertStringContainsString('start groups=2 workers=3', $master->logText());
            self::assertStringContainsString('alpha #0', $master->logText());
            self::assertStringContainsString('beta #0', $master->logText());
            self::assertStringContainsString('beta #1', $master->logText());

            [$code, $output] = TestWorkerMaster::runCommand('status', $master->configPath());

            self::assertSame(0, $code);
            self::assertStringContainsString('groups=2', $output);
            self::assertStringContainsString('alpha: workers=1', $output);
            self::assertStringContainsString('beta: workers=2', $output);
        } finally {
            $master->stop();
        }
    }

    /**
     * A reload re-reads the config, so scaling a pool is an edit plus a reload rather
     * than a restart of the whole master.
     */
    public function testReloadPicksUpAResizedGroup(): void
    {
        $master = TestWorkerMaster::start(['workerCount' => 1]);

        try {
            $master->rewriteConfig([
                'runtimeDir'   => $master->runtimeDir(),
                'logDir'       => $master->runtimeDir(),
                'name'         => $master->name(),
                'phpArgs'      => ['-d', 'extension=' . self::extensionPath()],
                'workerScript' => self::demoWorkerScript(),
                'workerCount'  => 3,
                'server'       => ['address' => '127.0.0.1:' . $master->port(), 'reusePort' => true],
            ]);

            [$code] = TestWorkerMaster::runCommand('reload', $master->configPath());

            self::assertSame(0, $code);

            self::assertTrue(
                $this->waitFor(
                    static fn(): bool => str_contains($master->logText(), 'default #2'),
                    15.0,
                ),
                'the third worker must come up after the reload',
            );

            [, $output] = TestWorkerMaster::runCommand('status', $master->configPath());

            self::assertStringContainsString('default: workers=3', $output);
        } finally {
            $master->stop();
        }
    }

    /**
     * A scoped reload touches its group and leaves the others where they are.
     */
    public function testReloadCanBeNarrowedToOneGroup(): void
    {
        $groups = [
            [
                'name'         => 'alpha',
                'workerScript' => self::demoWorkerScript(),
                'workerCount'  => 1,
                'server'       => ['address' => '127.0.0.1:0', 'reusePort' => true],
            ],
            [
                'name'         => 'beta',
                'workerScript' => self::demoWorkerScript(),
                'workerCount'  => 1,
                'server'       => ['address' => '127.0.0.1:0', 'reusePort' => true],
            ],
        ];

        $master = TestWorkerMaster::start(
            options: ['groups' => $groups],
            waitReachable: false,
        );

        try {
            self::assertTrue(
                $this->waitFor(static fn(): bool => str_contains($master->logText(), 'beta #0'), 5.0),
                'both groups must be up first',
            );

            $master->rewriteConfig([
                'runtimeDir' => $master->runtimeDir(),
                'logDir'     => $master->runtimeDir(),
                'name'       => $master->name(),
                'phpArgs'    => ['-d', 'extension=' . self::extensionPath()],
                'groups'     => $groups,
            ]);

            [$scopedCode, $scopedOutput] = TestWorkerMaster::runCommand(
                'reload',
                $master->configPath(),
                argv: ['--group=alpha'],
            );

            self::assertSame(0, $scopedCode, $scopedOutput);

            self::assertTrue(
                $this->waitFor(
                    static fn(): bool => str_contains($master->logText(), 'reload requested for group alpha'),
                    10.0,
                ),
                'the master must report the scoped request',
            );

            self::assertStringContainsString('group alpha: rolling', $master->logText());
            self::assertStringNotContainsString('group beta: rolling', $master->logText());
        } finally {
            $master->stop();
        }
    }

    /**
     * A config that does not parse must never take a working pool down: the reload is
     * refused and the master keeps running on what it had.
     */
    public function testAnInvalidReloadIsRefusedAndTheMasterKeepsRunning(): void
    {
        $master = TestWorkerMaster::start(['workerCount' => 1]);

        try {
            // Straight to the trigger file: the CLI reads the config before writing it,
            // so a broken one never gets that far. The master's own guard is what this
            // covers — it re-reads at a moment the CLI cannot vouch for.
            $brokenPath = $master->runtimeDir() . '/broken.json';

            file_put_contents($brokenPath, '{"groups": []}');

            file_put_contents(
                $master->runtimeDir() . '/' . $master->name() . '.reload',
                $brokenPath . "\n",
            );

            self::assertTrue(
                $this->waitFor(
                    static fn(): bool => str_contains($master->logText(), 'reload refused'),
                    10.0,
                ),
                'the master must report the refusal',
            );

            self::assertStringContainsString('keeping the running config', $master->logText());
            self::assertTrue($master->isRunning());
            self::assertGreaterThan(0, $master->workerPid());

            // A refusal is the end of that request. The master used to note the request as
            // served before deciding it could not honour it, and then announced it complete
            // on a later tick — a journal saying a reload landed when nothing had rolled.
            usleep(500_000);

            self::assertStringNotContainsString(
                'reload complete',
                $master->logText(),
                'a refused reload must not be reported complete',
            );
        } finally {
            $master->stop();
        }
    }

    /**
     * A config that parses but points at a script that is not there is a typo, and it must
     * not take a working pool down: without this check every slot rolls — SIGTERM to a
     * healthy worker, then `php /wrong/path` exiting 1 — and the pool falls into a crash
     * loop behind a backoff.
     */
    public function testAReloadNamingAMissingWorkerScriptIsRefused(): void
    {
        $master = TestWorkerMaster::start(['workerCount' => 1]);

        try {
            $servingPid = $master->workerPid();

            $brokenPath = TestWorkerMaster::writeConfig([
                'workerScript' => '/no/such/worker.php',
                'runtimeDir'   => $master->runtimeDir(),
            ]);

            file_put_contents(
                $master->runtimeDir() . '/' . $master->name() . '.reload',
                $brokenPath . "\n",
            );

            self::assertTrue(
                $this->waitFor(
                    static fn(): bool => str_contains($master->logText(), 'reload refused'),
                    10.0,
                ),
                'the master must report the refusal',
            );

            self::assertStringContainsString('Worker script not found', $master->logText());
            self::assertTrue($master->isRunning());
            self::assertSame($servingPid, $master->workerPid(), 'the serving worker must be left alone');
        } finally {
            $master->stop();
        }
    }

    /**
     * The master reads the trigger from its own working directory, not the operator's, so
     * a relative path written as it was typed resolves to nothing there. The CLI resolves
     * it before writing; a trigger that still names a path the master cannot find is
     * refused rather than silently rolling the workers onto the config already loaded.
     */
    public function testAReloadNamingAConfigThatIsNotThereIsRefused(): void
    {
        $master = TestWorkerMaster::start(['workerCount' => 1]);

        try {
            file_put_contents(
                $master->runtimeDir() . '/' . $master->name() . '.reload',
                "config/does-not-exist.json\n",
            );

            self::assertTrue(
                $this->waitFor(
                    static fn(): bool => str_contains($master->logText(), 'no config file at'),
                    10.0,
                ),
                'the master must say the config it was pointed at is not there',
            );

            self::assertTrue($master->isRunning());
        } finally {
            $master->stop();
        }
    }

    /**
     * The config a running test master was started with, as a reload would read it, with
     * the pool's settings overridden.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function reloadConfig(TestWorkerMaster $master, array $overrides): array
    {
        $config = json_decode((string) file_get_contents($master->configPath()), true);

        self::assertIsArray($config);
        self::assertIsArray($config['groups'][0] ?? null);

        $config['groups'][0] = [...$config['groups'][0], ...$overrides];

        return $config;
    }

    /** Where a line first appears in the journal; fails the test when it does not. */
    private function journalPosition(string $log, string $needle): int
    {
        $position = strpos($log, $needle);

        self::assertNotFalse($position, sprintf('"%s" is not in the journal: %s', $needle, $log));

        return $position;
    }

    private static function demoWorkerScript(): string
    {
        return dirname(__DIR__, 3) . '/tests/servers/http/http-server.php';
    }

    private static function extensionPath(): string
    {
        // The build under test, so a run that spawns its own worker loads the same
        // extension the suite does. Without it a run would answer for one core while
        // measuring another.
        return getenv('SCONCUR_EXT') ?: dirname(__DIR__, 3) . '/ext/build/sconcur.so';
    }

    /**
     * Polls $condition until it returns true or the timeout elapses.
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
