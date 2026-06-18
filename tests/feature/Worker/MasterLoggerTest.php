<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Worker\MasterLogger;

/**
 * Unit coverage of the master log line format and daily-file retention rotation.
 */
class MasterLoggerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/sc-log-' . uniqid('', true);

        mkdir($this->directory, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory . '/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    public function testMasterLineFormat(): void
    {
        $logger = new MasterLogger($this->directory, 'sconcur-http-server', 3, 12345);

        $logger->master(MasterLogger::INFO, 'start workers=8');
        $logger->close();

        self::assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\] INFO \[master: 12345\]: start workers=8 \[\]$/',
            trim($this->todayLog()),
        );
    }

    public function testWorkerScopeLineFormat(): void
    {
        $logger = new MasterLogger($this->directory, 'sconcur-http-server', 3, 12345);

        $logger->worker(MasterLogger::ERROR, 12346, 0, 'boom');
        $logger->close();

        self::assertStringContainsString('ERROR [worker: 12346 #0]: boom []', $this->todayLog());
    }

    public function testContextIsRenderedAsTrailingJson(): void
    {
        $logger = new MasterLogger($this->directory, 'sconcur-http-server', 3, 12345);

        $logger->master(MasterLogger::INFO, 'event', ['code' => 1]);
        $logger->close();

        self::assertStringContainsString('event {"code":1}', $this->todayLog());
    }

    public function testRotationPrunesFilesOlderThanRotateDays(): void
    {
        // A stale file far in the past must be removed on the next write (rotateDays=1
        // keeps only today).
        $stalePath = $this->directory . '/sconcur-http-server-2000-01-01.log';

        file_put_contents($stalePath, "old\n");

        $logger = new MasterLogger($this->directory, 'sconcur-http-server', 1, 12345);

        $logger->master(MasterLogger::INFO, 'fresh');
        $logger->close();

        self::assertFileDoesNotExist($stalePath, 'logs older than rotateDays must be pruned');
        self::assertFileExists($this->directory . '/sconcur-http-server-' . date('Y-m-d') . '.log');
    }

    private function todayLog(): string
    {
        return (string) file_get_contents(
            $this->directory . '/sconcur-http-server-' . date('Y-m-d') . '.log',
        );
    }
}
