<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Worker\MasterCli;
use SConcur\Worker\MasterLock;

/**
 * Unit coverage of the CLI argument handling — exercised in-process with in-memory
 * streams, no child processes (the supervision loop is covered by WorkerMasterTest).
 * Every command takes a single --configPath; the JSON config is written to a temp file.
 */
class MasterCliTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        foreach ($this->tempDirs as $directory) {
            @rmdir($directory);
        }

        parent::tearDown();
    }

    public function testNoCommandPrintsUsage(): void
    {
        [$code, , $err] = $this->runCli([]);

        self::assertSame(MasterCli::EXIT_USAGE, $code);
        self::assertStringContainsString('Usage: sconcur-server', $err);
    }

    public function testUnknownCommandPrintsUsage(): void
    {
        [$code, , $err] = $this->runCli(['bogus', '--configPath=/whatever.json']);

        self::assertSame(MasterCli::EXIT_USAGE, $code);
        self::assertStringContainsString('Usage: sconcur-server', $err);
    }

    public function testStartRequiresConfigPath(): void
    {
        [$code, , $err] = $this->runCli(['start']);

        self::assertSame(MasterCli::EXIT_USAGE, $code);
        self::assertStringContainsString('--configPath', $err);
    }

    public function testStartRejectsMissingConfigFile(): void
    {
        [$code, , $err] = $this->runCli(['start', '--configPath=/no/such/config.json']);

        self::assertSame(MasterCli::EXIT_USAGE, $code);
        self::assertStringContainsString('not found', $err);
    }

    public function testStartRejectsUnknownRestartPolicy(): void
    {
        $configPath = $this->writeConfig(['workerScript' => '/tmp/x.php', 'restartPolicy' => 'bogus']);

        [$code, , $err] = $this->runCli(['start', '--configPath=' . $configPath]);

        self::assertSame(MasterCli::EXIT_USAGE, $code);
        self::assertStringContainsString('restartPolicy', $err);
    }

    public function testStartRejectsConfigWithoutWorkerScript(): void
    {
        $configPath = $this->writeConfig(['workerCount' => 2]);

        [$code, , $err] = $this->runCli(['start', '--configPath=' . $configPath]);

        self::assertSame(MasterCli::EXIT_USAGE, $code);
        self::assertStringContainsString('workerScript', $err);
    }

    public function testStartRejectsNonScalarServerValue(): void
    {
        $configPath = $this->writeConfig([
            'workerScript' => '/tmp/x.php',
            'server'       => ['address' => ['nested' => 1]],
        ]);

        [$code, , $err] = $this->runCli(['start', '--configPath=' . $configPath]);

        self::assertSame(MasterCli::EXIT_USAGE, $code);
        self::assertStringContainsString('server.address', $err);
    }

    public function testStartRejectsInvalidName(): void
    {
        $configPath = $this->writeConfig([
            'workerScript' => '/tmp/x.php',
            'name'         => 'bad/name',
        ]);

        [$code, , $err] = $this->runCli(['start', '--configPath=' . $configPath]);

        self::assertSame(MasterCli::EXIT_USAGE, $code);
        self::assertStringContainsString('name', $err);
    }

    public function testStartRejectsUnknownConfigKey(): void
    {
        $configPath = $this->writeConfig([
            'workerScript' => '/tmp/x.php',
            'wokerCount'   => 4,
        ]);

        [$code, , $err] = $this->runCli(['start', '--configPath=' . $configPath]);

        self::assertSame(MasterCli::EXIT_USAGE, $code);
        self::assertStringContainsString('wokerCount', $err);
    }

    public function testStartRejectsNegativeTiming(): void
    {
        $configPath = $this->writeConfig([
            'workerScript'      => '/tmp/x.php',
            'shutdownTimeoutMs' => -1,
        ]);

        [$code, , $err] = $this->runCli(['start', '--configPath=' . $configPath]);

        self::assertSame(MasterCli::EXIT_USAGE, $code);
        self::assertStringContainsString('shutdownTimeoutMs', $err);
    }

    public function testStatusReportsStoppedWhenNoState(): void
    {
        $directory  = $this->makeDir();
        $configPath = $this->writeConfig([
            'workerScript' => '/tmp/x.php',
            'runtimeDir'   => $directory,
            'name'         => 'absent',
        ]);

        [$code, $out] = $this->runCli(['status', '--configPath=' . $configPath]);

        self::assertSame(MasterCli::EXIT_NOT_RUNNING, $code);
        self::assertStringContainsString('stopped', $out);
    }

    public function testStopReportsNotRunningWhenNoState(): void
    {
        $directory  = $this->makeDir();
        $configPath = $this->writeConfig([
            'workerScript' => '/tmp/x.php',
            'runtimeDir'   => $directory,
            'name'         => 'absent',
        ]);

        [$code, $out] = $this->runCli(['stop', '--configPath=' . $configPath]);

        self::assertSame(MasterCli::EXIT_OK, $code);
        self::assertStringContainsString('not running', $out);
    }

    public function testStatusReportsRunningWhenLockHeldWithoutState(): void
    {
        // A live master holds the lock but a state file may be absent (e.g. just
        // before it is written): status decides liveness by the lock and still
        // reports "running". A second flock from the same process contends, so
        // holding MasterLock here simulates a running master.
        $directory  = $this->makeDir();
        $configPath = $this->writeConfig([
            'workerScript' => '/tmp/x.php',
            'runtimeDir'   => $directory,
            'name'         => 'held',
        ]);

        $lock = new MasterLock(path: $directory . '/held.lock');

        $lock->acquire();

        try {
            [$code, $out] = $this->runCli(['status', '--configPath=' . $configPath]);

            self::assertSame(MasterCli::EXIT_OK, $code);
            self::assertStringContainsString('running', $out);
        } finally {
            $lock->release();

            @unlink($directory . '/held.lock');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeConfig(array $config): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sc-cli-cfg-');

        file_put_contents($path, (string) json_encode($config));

        $this->tempFiles[] = $path;

        return $path;
    }

    private function makeDir(): string
    {
        $directory = sys_get_temp_dir() . '/sc-cli-' . uniqid('', true);

        mkdir($directory, 0o775, true);

        $this->tempDirs[] = $directory;

        return $directory;
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

        $code = new MasterCli($stdout, $stderr)->run(['sconcur-server', ...$args]);

        rewind($stdout);
        rewind($stderr);

        return [
            $code,
            (string) stream_get_contents($stdout),
            (string) stream_get_contents($stderr),
        ];
    }
}
