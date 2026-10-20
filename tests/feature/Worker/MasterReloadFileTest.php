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
        @unlink($this->path . '.failed');
    }

    public function testRequestCreatesTheTriggerAndClearRemovesIt(): void
    {
        $reloadFile = new MasterReloadFile($this->path);

        self::assertFalse($reloadFile->requested(), 'no trigger before a request');

        self::assertNotNull($reloadFile->request());
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

        self::assertNotNull($reloadFile->request(configPath: '/etc/sconcur.json', group: 'api'));

        $first = $reloadFile->pending();

        self::assertNotNull($reloadFile->request(configPath: '/etc/sconcur.json', group: 'api'));

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

    /**
     * A reload the master refuses or gives up removes the trigger as a finished one does,
     * and the CLI reads the trigger's removal as the end of it. The reason is what tells
     * the two apart, and only the CLI that asked finds it.
     */
    public function testAFailedRequestLeavesItsReasonForTheCliThatAsked(): void
    {
        $reloadFile = new MasterReloadFile($this->path);

        $nonce = $reloadFile->request(configPath: '/etc/sconcur.json');

        self::assertNotNull($nonce);

        $pending = $reloadFile->pending();

        self::assertNotNull($pending);
        self::assertSame($nonce, $pending->nonce);

        $reloadFile->fail(
            request: $pending,
            reason: 'reload given up: group api: the replacement did not come up',
        );

        self::assertFalse($reloadFile->requested(), 'a failed request is ended like any other');
        self::assertNull($reloadFile->failure('someone-else'), 'another request finds nothing');
        self::assertSame(
            'reload given up: group api: the replacement did not come up',
            $reloadFile->failure($nonce),
        );
        self::assertNull($reloadFile->failure($nonce), 'the reason is read once');
    }

    public function testASucceededRequestLeavesNoReason(): void
    {
        $reloadFile = new MasterReloadFile($this->path);

        $nonce = $reloadFile->request();

        self::assertNotNull($nonce);

        $pending = $reloadFile->pending();

        self::assertNotNull($pending);

        $reloadFile->clear($pending->signature);

        self::assertNull($reloadFile->failure($nonce));
    }

    public function testClearIsIdempotentWhenAbsent(): void
    {
        $reloadFile = new MasterReloadFile($this->path);

        $reloadFile->clear();

        self::assertFalse($reloadFile->requested());
    }
}
