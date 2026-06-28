<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\SocketServer\TestSocketServer;

/**
 * The server writes one startup line (with its pid and bound address) when it begins
 * serving, and the graceful-shutdown steps as it drains and stops. Manages its own server
 * so it can read the captured stdout.
 */
class SocketServerLifecycleLogTest extends TestCase
{
    public function testStartupLineCarriesPidAddressAndVersion(): void
    {
        $server = TestSocketServer::start();

        try {
            $output = $this->waitForOutput($server, 'sconcur socket server listening on');

            self::assertStringContainsString('127.0.0.1:' . $server->port(), $output);
            self::assertStringContainsString('pid=' . $server->pid(), $output);
            self::assertStringContainsString('version=', $output);
        } finally {
            $server->stop();
        }
    }

    public function testGracefulShutdownStepsAreLogged(): void
    {
        $server = TestSocketServer::start();

        try {
            $server->signal(SIGTERM);

            self::assertSame(
                0,
                $server->waitForExit(5.0),
                'server should exit cleanly after a shutdown signal',
            );

            $output = $server->output();

            self::assertStringContainsString('sconcur socket server shutdown: stop accepting (reason=signal)', $output);
            self::assertStringContainsString('sconcur socket server shutdown: drained all in-flight', $output);
            self::assertStringContainsString('sconcur socket server shutdown: stopped', $output);
        } finally {
            $server->stop();
        }
    }

    private function waitForOutput(TestSocketServer $server, string $needle): string
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
