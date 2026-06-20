<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SConcur\Tests\Impl\SocketServer\TestSocketServer;

/**
 * Base for socket-server tests. Each test class spawns its own real server process
 * (via TestSocketServer) for the whole class, with the launch options it needs, and
 * provides length-prefix framing helpers (4-byte big-endian length + payload) to
 * talk to it over a raw TCP connection.
 */
abstract class BaseSocketServerTestCase extends TestCase
{
    private static ?TestSocketServer $server = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$server = TestSocketServer::start(
            options: static::serverOptions(),
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        parent::tearDownAfterClass();
    }

    /**
     * Launch options overriding the server defaults for this whole test class.
     * Override to tune the server, e.g. ['maxConcurrency' => 2].
     *
     * @return array<string, int|bool>
     */
    protected static function serverOptions(): array
    {
        return [];
    }

    protected static function server(): TestSocketServer
    {
        if (self::$server === null) {
            throw new RuntimeException('Test socket server is not started.');
        }

        return self::$server;
    }

    /**
     * Opens a raw TCP connection to the server, with a read timeout so a test never
     * hangs forever waiting for a frame.
     *
     * @return resource
     */
    protected function connect(float $timeoutSeconds = 5.0): mixed
    {
        return self::server()->connect($timeoutSeconds);
    }

    /**
     * Sends one length-prefixed frame.
     *
     * @param resource $connection
     */
    protected function sendFrame(mixed $connection, string $data): void
    {
        TestSocketServer::sendFrame($connection, $data);
    }

    /**
     * Reads one length-prefixed frame, or null on a clean connection close (EOF).
     *
     * @param resource $connection
     */
    protected function receiveFrame(mixed $connection): ?string
    {
        return TestSocketServer::receiveFrame($connection);
    }

    /**
     * Sends one frame and reads one frame back over a fresh connection.
     */
    protected function roundtrip(string $data): ?string
    {
        return self::server()->roundtrip($data);
    }
}
