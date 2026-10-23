<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use SConcur\Tests\Impl\Worker\TestWorkerMaster;
use SConcur\Worker\WorkerMaster;

/**
 * Every scenario of WorkerMasterTest once more, with the workers launched by fork — the
 * default: the group's fork server copies itself into each worker instead of starting php
 * on the script.
 * Supervision, restarts, the watchdog, reload, the journal and the CLI must not depend on
 * how a worker came to be.
 */
class ForkedWorkerMasterTest extends WorkerMasterTest
{
    protected function setUp(): void
    {
        parent::setUp();

        TestWorkerMaster::launchWith('fork');
    }

    protected function tearDown(): void
    {
        TestWorkerMaster::launchWith(null);

        parent::tearDown();
    }

    /**
     * The fork counterpart of the exec scenario. A forked worker runs on its fork server's
     * php.ini, so display_errors=Off goes there; the worker forces display_errors=stderr
     * on itself, as an exec worker gets it on its command line. The fatal is a real one —
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
            // The limit sits above what the copy of the fork server already holds — a lower one
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
}
