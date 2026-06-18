<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Worker\MasterCli;
use SConcur\Worker\MasterLock;

/**
 * Unit coverage of the CLI argument handling — exercised in-process with in-memory
 * streams, no child processes (the supervision loop is covered by WorkerMasterTest).
 */
class MasterCliTest extends TestCase
{
    public function testNoCommandPrintsUsage(): void
    {
        [$code, , $err] = $this->runCli([]);

        self::assertSame(MasterCli::EXIT_USAGE, $code);
        self::assertStringContainsString('Usage', $err);
    }

    public function testStartRequiresWorker(): void
    {
        [$code, , $err] = $this->runCli(['start']);

        self::assertSame(MasterCli::EXIT_USAGE, $code);
        self::assertStringContainsString('--workerScript', $err);
    }

    public function testStartRejectsUnknownRestartPolicy(): void
    {
        [$code, , $err] = $this->runCli(['start', '--workerScript=/tmp/x.php', '--restartPolicy=bogus']);

        self::assertSame(MasterCli::EXIT_USAGE, $code);
        self::assertStringContainsString('restartPolicy', $err);
    }

    public function testStatusReportsStoppedWhenNoState(): void
    {
        $directory = sys_get_temp_dir() . '/sc-cli-' . uniqid('', true);

        mkdir($directory, 0o775, true);

        try {
            [$code, $out] = $this->runCli(['status', '--runtimeDir=' . $directory, '--name=absent']);

            self::assertSame(MasterCli::EXIT_NOT_RUNNING, $code);
            self::assertStringContainsString('stopped', $out);
        } finally {
            @rmdir($directory);
        }
    }

    public function testStatusReportsRunningWhenLockHeldWithoutState(): void
    {
        // A live master holds the lock but a state file may be absent (e.g. just
        // before it is written): status decides liveness by the lock and still
        // reports "running". A second flock from the same process contends, so
        // holding MasterLock here simulates a running master.
        $directory = sys_get_temp_dir() . '/sc-cli-' . uniqid('', true);

        mkdir($directory, 0o775, true);

        $lock = new MasterLock(path: $directory . '/held.lock');

        $lock->acquire();

        try {
            [$code, $out] = $this->runCli(['status', '--runtimeDir=' . $directory, '--name=held']);

            self::assertSame(MasterCli::EXIT_OK, $code);
            self::assertStringContainsString('running', $out);
        } finally {
            $lock->release();

            @unlink($directory . '/held.lock');
            @rmdir($directory);
        }
    }

    public function testStopReportsNotRunningWhenNoState(): void
    {
        $directory = sys_get_temp_dir() . '/sc-cli-' . uniqid('', true);

        mkdir($directory, 0o775, true);

        try {
            [$code, $out] = $this->runCli(['stop', '--runtimeDir=' . $directory, '--name=absent']);

            self::assertSame(MasterCli::EXIT_OK, $code);
            self::assertStringContainsString('not running', $out);
        } finally {
            @rmdir($directory);
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function runCli(array $args): array
    {
        $stdout = fopen('php://memory', 'rw');
        $stderr = fopen('php://memory', 'rw');

        if ($stdout === false || $stderr === false) {
            self::fail('Could not open in-memory streams.');
        }

        $code = new MasterCli($stdout, $stderr)->run(['sconcur-http-server', ...$args]);

        rewind($stdout);
        rewind($stderr);

        return [
            $code,
            (string) stream_get_contents($stdout),
            (string) stream_get_contents($stderr),
        ];
    }
}
