<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\WsClient;

use SConcur\Features\WsClient\WsClient;
use SConcur\Features\WsClient\WsClientOptions;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\WsServer\TestWsServer;

/**
 * Base for ws-client tests. The client runs in-process (it loads the extension); its
 * target is a real SConcur WsServer spawned as its own process for the whole class (the
 * echo/command demo server). Extends BaseTestCase so the extension lifecycle and the
 * no-hanging-tasks teardown check apply.
 */
abstract class BaseWsClientTestCase extends BaseTestCase
{
    private static ?TestWsServer $server = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$server = TestWsServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        parent::tearDownAfterClass();
    }

    protected static function server(): TestWsServer
    {
        if (self::$server === null) {
            self::fail('Test ws server is not started.');
        }

        return self::$server;
    }

    /**
     * The "ws://host:port/" URL of the running demo server.
     */
    protected function url(): string
    {
        return 'ws://' . self::server()->host() . ':' . self::server()->port() . '/';
    }

    protected function client(WsClientOptions $options = new WsClientOptions()): WsClient
    {
        return new WsClient(options: $options);
    }
}
