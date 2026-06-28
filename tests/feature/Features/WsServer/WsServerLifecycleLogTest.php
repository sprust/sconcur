<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\WsServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\WsServer\TestWsServer;

/**
 * The server writes one startup line (with its pid and bound address) when it begins
 * serving, and the graceful-shutdown steps as it drains and stops. Manages its own server
 * so it can read the captured stdout.
 */
class WsServerLifecycleLogTest extends TestCase
{
    public function testStartupLineCarriesPidAddressAndVersion(): void
    {
        $server = TestWsServer::start();

        try {
            $output = $this->waitForOutput($server, 'sconcur ws server listening on');

            self::assertStringContainsString('127.0.0.1:' . $server->port(), $output);
            self::assertStringContainsString('pid=' . $server->pid(), $output);
            self::assertStringContainsString('version=', $output);
        } finally {
            $server->stop();
        }
    }

    public function testGracefulShutdownStepsAreLogged(): void
    {
        $server = TestWsServer::start();

        try {
            $server->signal(SIGTERM);

            self::assertSame(
                0,
                $server->waitForExit(5.0),
                'server should exit cleanly after a shutdown signal',
            );

            $output = $server->output();

            self::assertStringContainsString('sconcur ws server shutdown: stop accepting (reason=signal)', $output);
            self::assertStringContainsString('sconcur ws server shutdown: drained all in-flight', $output);
            self::assertStringContainsString('sconcur ws server shutdown: stopped', $output);
        } finally {
            $server->stop();
        }
    }

    private function waitForOutput(TestWsServer $server, string $needle): string
    {
        $deadline = microtime(true) + 5.0;

        while (true) {
            $output = $server->output();

            if (str_contains($output, $needle)) {
                return $output;
            }

            if (microtime(true) > $deadline) {
                self::fail(sprintf('the server did not log "%s"; got: %s', $needle, $output));
            }

            usleep(50_000);
        }
    }
}
