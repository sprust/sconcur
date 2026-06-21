<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketClient;

use SConcur\Features\SocketClient\SocketClient;
use SConcur\Features\SocketClient\SocketClientOptions;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\SocketServer\TestSocketServer;

/**
 * Base for socket-client tests. The client runs in-process (it loads the extension);
 * its target is a real SConcur SocketServer spawned as its own process for the whole
 * class (the echo/command demo server). Extends BaseTestCase so the extension
 * lifecycle and the no-hanging-tasks teardown check apply.
 */
abstract class BaseSocketClientTestCase extends BaseTestCase
{
    private static ?TestSocketServer $server = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$server = TestSocketServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        parent::tearDownAfterClass();
    }

    protected static function server(): TestSocketServer
    {
        if (self::$server === null) {
            self::fail('Test socket server is not started.');
        }

        return self::$server;
    }

    /**
     * The "host:port" of the running demo server.
     */
    protected function address(): string
    {
        return self::server()->host() . ':' . self::server()->port();
    }

    protected function client(SocketClientOptions $options = new SocketClientOptions()): SocketClient
    {
        return new SocketClient(options: $options);
    }
}
