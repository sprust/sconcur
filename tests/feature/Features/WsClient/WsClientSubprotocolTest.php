<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\WsClient;

use SConcur\Features\WsClient\WsClient;
use SConcur\Features\WsClient\WsClientOptions;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\WsServer\TestWsServer;

/**
 * End-to-end subprotocol negotiation through WsClient against a real WsServer that
 * offers subprotocols (set in code, since the array option cannot be passed through
 * argv). Covers the gap left by the per-feature suites: the protocol/concurrency tests
 * never negotiate a subprotocol, so Connection::$subprotocol was only ever asserted empty.
 */
class WsClientSubprotocolTest extends BaseTestCase
{
    private static ?TestWsServer $server = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$server = TestWsServer::start(
            serverScript: dirname(__DIR__, 4) . '/tests/servers/ws/ws-subprotocol-server.php',
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        parent::tearDownAfterClass();
    }

    public function testNegotiatesTheClientPreferredSubprotocol(): void
    {
        // The server supports ['sconcur.echo', 'sconcur.chat']; the client offers only
        // 'sconcur.chat', so that is the negotiated one.
        $connection = $this->client(['sconcur.chat'])->connect($this->url());

        self::assertSame('sconcur.chat', $connection->subprotocol);

        $connection->write('hello');

        self::assertSame('hello', $connection->read());

        $connection->close();
    }

    public function testServerPreferenceOrderWinsWhenBothOffered(): void
    {
        // The client offers both, listing 'sconcur.chat' first; the server still picks
        // 'sconcur.echo' because negotiation follows the server's preference order.
        $connection = $this->client(['sconcur.chat', 'sconcur.echo'])->connect($this->url());

        self::assertSame('sconcur.echo', $connection->subprotocol);

        $connection->close();
    }

    public function testNoSubprotocolWhenClientOffersNone(): void
    {
        $connection = $this->client([])->connect($this->url());

        self::assertSame('', $connection->subprotocol);

        $connection->close();
    }

    public function testNoSubprotocolWhenClientOffersOnlyUnknownOnes(): void
    {
        $connection = $this->client(['unknown.proto'])->connect($this->url());

        self::assertSame('', $connection->subprotocol);

        $connection->close();
    }

    /**
     * @param list<string> $subprotocols
     */
    private function client(array $subprotocols): WsClient
    {
        return new WsClient(new WsClientOptions(subprotocols: $subprotocols));
    }

    private function url(): string
    {
        return 'ws://' . self::server()->host() . ':' . self::server()->port() . '/';
    }

    private static function server(): TestWsServer
    {
        if (self::$server === null) {
            self::fail('Test ws server is not started.');
        }

        return self::$server;
    }
}
