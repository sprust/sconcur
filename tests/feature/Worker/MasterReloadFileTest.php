<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Worker\MasterReloadFile;

/**
 * Unit coverage of MasterReloadFile: the file-based reload trigger the `reload` CLI
 * command writes and the master consumes (request → requested → clear).
 */
class MasterReloadFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        // tempnam creates the file; start from an absent trigger.
        $this->path = (string) tempnam(sys_get_temp_dir(), 'sc-reload-');

        @unlink($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testRequestCreatesTheTriggerAndClearRemovesIt(): void
    {
        $reloadFile = new MasterReloadFile($this->path);

        self::assertFalse($reloadFile->requested(), 'no trigger before a request');

        self::assertTrue($reloadFile->request());
        self::assertTrue($reloadFile->requested(), 'request must create the trigger');
        self::assertFileExists($this->path);

        $reloadFile->clear();

        self::assertFalse($reloadFile->requested(), 'clear must remove the trigger');
        self::assertFileDoesNotExist($this->path);
    }

    public function testClearIsIdempotentWhenAbsent(): void
    {
        $reloadFile = new MasterReloadFile($this->path);

        $reloadFile->clear();

        self::assertFalse($reloadFile->requested());
    }
}
