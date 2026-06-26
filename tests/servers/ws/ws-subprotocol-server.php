<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use SConcur\Features\WsServer\Dto\Connection;
use SConcur\Features\WsServer\WsServer;

/**
 * Minimal echo WebSocket server that negotiates subprotocols. subprotocols is an
 * array option, which (by design) cannot be passed through the worker-master argv, so
 * it is set here in code. Only the listener address is read from argv. Used by
 * WsClientSubprotocolTest to cover subprotocol negotiation end-to-end through WsClient.
 *
 * Usage: php -d extension=ext/build/sconcur.so tests/servers/ws/ws-subprotocol-server.php --address=host:port
 */

$address = '0.0.0.0:9200';

foreach ($_SERVER['argv'] as $argument) {
    if (str_starts_with($argument, '--address=')) {
        $address = substr($argument, strlen('--address='));
    }
}

$server = new WsServer(
    address: $address,
    subprotocols: ['sconcur.echo', 'sconcur.chat'],
);

$server->serve(static function (Connection $connection): void {
    while (($message = $connection->read()) !== null) {
        $connection->write($message, binary: $connection->lastMessageWasBinary());
    }
});
