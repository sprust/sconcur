<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\WsServer;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SConcur\Tests\Impl\WsServer\TestWsServer;

/**
 * Base for WebSocket-server tests. Each test class spawns its own real server process
 * (via TestWsServer) for the whole class, with the launch options it needs, and
 * provides minimal raw WebSocket helpers (the upgrade handshake plus masked client
 * frames / unmasked server frames) to talk to it over a raw TCP connection.
 */
abstract class BaseWsServerTestCase extends TestCase
{
    private static ?TestWsServer $server = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$server = TestWsServer::start(
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
     * Launch options overriding the server defaults for this whole test class. Override
     * to tune the server, e.g. ['maxConcurrency' => 2].
     *
     * @return array<string, int|bool|string>
     */
    protected static function serverOptions(): array
    {
        return [];
    }

    protected static function server(): TestWsServer
    {
        if (self::$server === null) {
            throw new RuntimeException('Test ws server is not started.');
        }

        return self::$server;
    }

    /**
     * Opens a raw connection (TCP + WebSocket upgrade), with a read timeout so a test
     * never hangs forever waiting for a message.
     *
     * @return resource
     */
    protected function connect(string $path = '/', float $timeoutSeconds = 5.0): mixed
    {
        return self::server()->connect($path, $timeoutSeconds);
    }

    /**
     * Sends one WebSocket message (text by default).
     *
     * @param resource $connection
     */
    protected function sendMessage(mixed $connection, string $data, bool $binary = false): void
    {
        TestWsServer::sendMessage($connection, $data, $binary);
    }

    /**
     * Reads one WebSocket message, or null on a close frame / EOF. Returns
     * ['data' => string, 'binary' => bool].
     *
     * @param resource $connection
     *
     * @return array{data: string, binary: bool}|null
     */
    protected function receiveMessage(mixed $connection): ?array
    {
        return TestWsServer::receiveMessage($connection);
    }

    /**
     * Sends one message and reads the response payload back over a fresh connection.
     */
    protected function roundtrip(string $data, bool $binary = false): ?string
    {
        return self::server()->roundtrip($data, $binary);
    }
}
