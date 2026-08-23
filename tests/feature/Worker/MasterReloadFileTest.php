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

    /**
     * Two identical requests written inside one second are still two requests. The
     * signature is what the master clears by, and it used to be taken from the contents
     * and the file's mtime — which counts whole seconds. Asking twice for the same group
     * that fast produced one signature for both, and the clear that ended the first roll
     * deleted the second request unread.
     */
    public function testTwoIdenticalRequestsInTheSameSecondAreToldApart(): void
    {
        $reloadFile = new MasterReloadFile($this->path);

        self::assertTrue($reloadFile->request(configPath: '/etc/sconcur.json', group: 'api'));

        $first = $reloadFile->pending();

        self::assertTrue($reloadFile->request(configPath: '/etc/sconcur.json', group: 'api'));

        $second = $reloadFile->pending();

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first->signature, $second->signature);

        // The request itself is unchanged — only what identifies it differs.
        self::assertSame('/etc/sconcur.json', $second->configPath);
        self::assertSame('api', $second->group);

        // And the clear that ends the first roll leaves the second request alone.
        $reloadFile->clear($first->signature);

        self::assertTrue($reloadFile->requested(), 'the second request must survive the first clear');
    }

    public function testClearIsIdempotentWhenAbsent(): void
    {
        $reloadFile = new MasterReloadFile($this->path);

        $reloadFile->clear();

        self::assertFalse($reloadFile->requested());
    }
}
