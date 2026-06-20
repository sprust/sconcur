<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\HttpServer\TestHttpServer;

/**
 * Covers the HttpServer `masterPid` orphan-check in isolation: the server is a
 * direct child of this test process, so posix_getppid() equals this pid. Passing
 * that pid keeps it serving; passing a different pid makes it self-terminate (as if
 * its WorkerMaster had died). Manages its own server per test.
 */
class HttpServerMasterPidTest extends TestCase
{
    public function testKeepsServingWhenMasterPidIsItsParent(): void
    {
        // The server's parent is this test process, so a matching masterPid must not
        // trigger the orphan shutdown.
        $server = TestHttpServer::start(['masterPid' => (int) getmypid()]);

        try {
            $curl = curl_init($server->baseUrl() . '/');

            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
            ]);

            $body   = (string) curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

            curl_close($curl);

            self::assertSame(200, $status);
            self::assertSame('ok', $body);
            self::assertTrue($server->isRunning(), 'a matching masterPid must not stop the server');
        } finally {
            $server->stop();
        }
    }

    public function testSelfTerminatesWhenOrphaned(): void
    {
        // masterPid that is not this server's parent (pid 1 is never the parent here):
        // the orphan check fires on the first serve tick and the server shuts itself
        // down gracefully (exit 0), without being killed.
        $server = TestHttpServer::start(
            options: ['masterPid' => 1],
            waitReachable: false,
        );

        try {
            self::assertSame(
                0,
                $server->waitForExit(3.0),
                'server must self-terminate gracefully when orphaned (masterPid != parent)',
            );
        } finally {
            $server->stop();
        }
    }
}
