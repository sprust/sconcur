<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use Closure;
use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\Worker\TestWorkerMaster;

/**
 * An application loaded in the process before it starts the master: every worker is a fork
 * of that process, so it starts with the application in memory, and a worker that dies is
 * replaced by another fork — the application is not loaded again. What the application
 * opened while loading stays open in the workers, and reaches no program a worker starts.
 */
class AppBeforeMasterTest extends TestCase
{
    protected string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sc-app-master-' . uniqid('', true);

        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testAReplacedWorkerFindsTheApplicationTheMasterLoadedOnce(): void
    {
        $root = dirname(__DIR__, 3);

        $master = TestWorkerMaster::start(
            options: [
                'workerScript' => $root . '/tests/servers/worker/app-worker.php',
                'workerCount'  => 1,
            ],
            env: [
                'SCONCUR_TEST_APP_LOADS'  => $this->directory . '/loads.txt',
                'SCONCUR_TEST_APP_LOG'    => $this->directory . '/app.log',
                'SCONCUR_TEST_APP_REPORT' => $this->directory . '/report.txt',
            ],
            masterScript: $root . '/tests/servers/worker/app-master.php',
        );

        try {
            $first = $master->workerPid();

            self::assertGreaterThan(0, $first);

            posix_kill($first, SIGKILL);

            $replaced = $this->waitFor(
                condition: static fn(): bool => !in_array($master->workerPid(), [0, $first], true),
                timeoutSeconds: 5.0,
            );

            self::assertTrue($replaced, 'the killed worker should be replaced: ' . $master->logText());

            $reports = $this->reports();

            self::assertCount(2, $reports, 'two workers, one after the other');
            self::assertSame(
                [(string) $master->pid()],
                file($this->directory . '/loads.txt', FILE_IGNORE_NEW_LINES),
                'the application loaded once, in the master',
            );

            foreach ($reports as $report) {
                self::assertIsString($report['token'], 'the worker found no application');
                self::assertSame($reports[0]['token'], $report['token'], 'both workers hold the application the master loaded');
                self::assertTrue($report['holdsLog'], 'the worker lost the file the application opened');
                self::assertFalse($report['startedHoldsLog'], 'a program the worker started holds the application\'s file');
            }
        } finally {
            $master->stop();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function reports(): array
    {
        $reports = [];

        foreach ((array) file($this->directory . '/report.txt', FILE_IGNORE_NEW_LINES) as $line) {
            $reports[] = (array) json_decode((string) $line, true);
        }

        return $reports;
    }

    protected function waitFor(Closure $condition, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }

            usleep(50_000);
        }

        return $condition();
    }
}
