<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use Closure;
use PHPUnit\Framework\TestCase;
use SConcur\Worker\WorkerMaster;
use SConcur\Tests\Impl\Worker\TestWorkerMaster;

/**
 * End-to-end coverage of WorkerMaster through a master of its own (TestWorkerMaster): the
 * master forks demo workers (HttpServer + SO_REUSEPORT + masterPid) and we observe
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

            self::assertSame(3, $code);
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

    /**
     * A role that fails as the worker starts — here, a script it requires that is not there —
     * fails every worker before any is up: the master stops with EXIT_NEVER_CAME_UP.
     */
    public function testARoleThatFailsAtOnceStopsTheMaster(): void
    {
        $configPath = TestWorkerMaster::writeConfig(['workerScript' => '/no/such/worker.php']);

        [$code] = TestWorkerMaster::runCommand('start', $configPath);

        self::assertSame(WorkerMaster::EXIT_NEVER_CAME_UP, $code);
    }

    public function testStartFailsForNegativeWorkerCount(): void
    {
        // Caught as the group is built, before a master starts.
        $configPath = TestWorkerMaster::writeConfig(['workerCount' => -1]);

        [$code, $output] = TestWorkerMaster::runCommand('start', $configPath);

        self::assertSame(1, $code);
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

            self::assertSame(1, $code);
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

            self::assertSame(0, $statusCode);
            self::assertStringContainsString('running', $statusOut);

            [$stopCode, $stopOut] = TestWorkerMaster::runCommand('stop', $configPath);

            self::assertSame(0, $stopCode);
            self::assertStringContainsString('stopped', $stopOut);

            self::assertSame(0, $master->waitForExit(8.0), 'stop should let the master exit cleanly');

            [$afterCode, $afterOut] = TestWorkerMaster::runCommand('status', $configPath);

            self::assertSame(3, $afterCode);
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

    /**
     * A worker dying on startup must leave its reason in the journal even when the php.ini
     * says display_errors=Off (the production recommendation). A worker runs on its
     * master's php.ini, so display_errors=Off goes there; the worker forces
     * display_errors=stderr on itself. The fatal is a real one —
     * an exhausted memory limit — because an uncaught exception would be reported by the
     * worker's own catch whatever display_errors says.
     */
    public function testWorkerFatalErrorTextIsLoggedEvenWithDisplayErrorsOff(): void
    {
        $iniDirectory = sys_get_temp_dir() . '/sc-master-ini-' . uniqid('', true);
        $workerScript = (string) tempnam(sys_get_temp_dir(), 'sc-fatal-worker-');

        mkdir($iniDirectory);

        file_put_contents($iniDirectory . '/display-errors.ini', "display_errors=Off\n");
        file_put_contents(
            $workerScript,
            // The limit sits above what the copy of the master already holds — a lower one
            // is refused outright — and the string is well past it.
            "<?php\nini_set('memory_limit', (string) (memory_get_usage(true) + 8 * 1024 * 1024));\n"
            . "\$filler = str_repeat('x', 32 * 1024 * 1024);\n",
        );

        $master = TestWorkerMaster::start(
            options: [
                'workerScript'  => $workerScript,
                'workerCount'   => 1,
                'restartPolicy' => 'never',
            ],
            // Appended to the scan directory rather than replacing it, which the leading
            // separator says: the image's own ini files still load.
            env: ['PHP_INI_SCAN_DIR' => ':' . $iniDirectory],
            waitReachable: false,
        );

        try {
            $exitCode = $master->waitForExit(8.0);

            $logText = $master->logText();

            self::assertSame(WorkerMaster::EXIT_NEVER_CAME_UP, $exitCode, 'the master should stop: ' . $logText);
            self::assertStringContainsString('no worker came up', $logText);
            self::assertMatchesRegularExpression(
                '/ERROR \[worker: \d+ \S+ #0\]: [^\n]*Allowed memory size/',
                $logText,
                'the fatal text must be logged at ERROR (stderr), not INFO',
            );
        } finally {
            $master->stop();

            @unlink($workerScript);
            @unlink($iniDirectory . '/display-errors.ini');
            @rmdir($iniDirectory);
        }
    }

    /**
     * With `accessLog: file` a worker writes its access lines itself, to a file of the
     * day beside the journal, signed with its place in the pool — instead of handing them
     * to its supervisor to journal.
     */
    public function testAccessLinesGoToAFileOfTheDayWhenAskedTo(): void
    {
        $master = TestWorkerMaster::start([
            'workerCount' => 1,
            'accessLog'   => 'file',
        ]);

        try {
            for ($request = 0; $request < 3; ++$request) {
                $master->get('/');
            }

            $pattern = $master->runtimeDir() . '/' . $master->name() . '-access-*.log';

            $written = $this->waitFor(
                static function () use ($pattern): bool {
                    $files = glob($pattern) ?: [];

                    return $files !== [] && str_contains((string) file_get_contents($files[0]), 'GET / 200');
                },
                timeoutSeconds: 5.0,
            );

            self::assertTrue($written, 'the access lines should reach the file: ' . $master->logText());

            $lines = (string) file_get_contents((glob($pattern) ?: [''])[0]);

            self::assertMatchesRegularExpression('/GET \/ 200 [\d.]+ms default#0 pid=\d+\n/', $lines);
            self::assertStringNotContainsString('GET / 200', $master->logText(), 'none of them in the journal');
        } finally {
            $master->stop();
        }
    }

    public function testAccessLinesCanBeSwitchedOff(): void
    {
        $master = TestWorkerMaster::start([
            'workerCount' => 1,
            'accessLog'   => 'off',
        ]);

        try {
            for ($request = 0; $request < 3; ++$request) {
                $master->get('/');
            }

            usleep(500_000);

            self::assertStringNotContainsString('GET / 200', $master->logText());
            self::assertSame([], glob($master->runtimeDir() . '/' . $master->name() . '-access-*.log') ?: []);
        } finally {
            $master->stop();
        }
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

    /** Where a line first appears in the journal; fails the test when it does not. */
    protected static function demoWorkerScript(): string
    {
        return dirname(__DIR__, 3) . '/tests/servers/http/http-server.php';
    }

    protected static function extensionPath(): string
    {
        // The build under test, so a run that spawns its own worker loads the same
        // extension the suite does. Without it a run would answer for one core while
        // measuring another.
        return getenv('SCONCUR_EXT') ?: dirname(__DIR__, 3) . '/ext/build/sconcur.so';
    }

    /**
     * Polls $condition until it returns true or the timeout elapses.
     */
    protected function waitFor(Closure $condition, float $timeoutSeconds): bool
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
