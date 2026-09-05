English | [Русский](websocket-client.ru.md)

# WebSocket client

An asynchronous WebSocket client — the dial-side mirror of the
[WebSocket server](websocket-server.md), just as the
[socket client](socket-client.md) is the pair of the socket server. All network
I/O (dial, upgrade handshake, read, write) lives in the extension:
`connect()` goes into a runtime task, the coroutine suspends, so dozens of
connections can be dialled at the same time. Outside a `WaitGroup` the same API
works synchronously.

The model is a long-lived bidirectional connection: the application dials, gets a
`Connection` and drives the conversation itself.

## Quick start

```php
use SConcur\Features\WsClient\WsClient;

$client = new WsClient();

$connection = $client->connect('ws://127.0.0.1:9200/');

$connection->write('ping');
$reply = $connection->read();          // ?string

$connection->close();
```

`connect()` takes a full `ws://host:port/path` URL. Best to drive the whole
conversation inside the same coroutine as `connect()`: when the coroutine finishes,
its flow is stopped and the unread connection inside the extension is closed (the same
caveat as with `HttpClient`/`SocketClient`).

## Connection: read / write / close

`Connection` (`src/Features/WsClient/Dto/Connection.php`, shared base class —
`src/Features/Socket/Dto/AbstractConnection.php`):

| Member | Description |
| --- | --- |
| `read(): ?string` | the next inbound message; `null` — the peer closed its side, the connection ended, or `maxMessageBytes` was exceeded. Cooperatively suspends the coroutine |
| `write(string $data, bool $binary = false): void` | send a message; waits until it has actually been flushed, so a fast writer cannot outrun the peer. Text by default. Throws `WsClientConnectionClosedException` if the connection is gone |
| `lastMessageWasBinary(): bool` | whether the last `read()` was binary |
| `close(): void` | close the connection (idempotent, best-effort) |
| `isClosed(): bool` | whether the connection is closed |
| `id`, `remoteAddr`, `localAddr`, `subprotocol` | identifier, addresses and the negotiated subprotocol. `remoteAddr` is the host from the connection URL (may be without a port); `localAddr` on the dial side is currently always empty |

Between reads and writes you can make asynchronous calls (Sleeper, Mongodb, SQL,
HTTP client) — the coroutine suspends cooperatively, other connections keep
working.

## Running connections concurrently

```php
use SConcur\WaitGroup;

$client    = new WsClient();
$waitGroup = WaitGroup::create();

foreach ($urls as $url) {
    $waitGroup->add(function () use ($client, $url) {
        $connection = $client->connect($url);

        $connection->write('hello');
        $reply = $connection->read();

        $connection->close();

        return $reply;
    });
}

/** @var array<int|string, ?string> $replies */
$replies = $waitGroup->waitResults(); // total time ≈ the slowest connection
```

## Parameters and timeouts

`SConcur\Features\WsClient\WsClientOptions` (`readonly`), all timeouts in ms; the
PHP defaults mirror the extension's. A long-lived connection has no single "operation time" —
that role is played by the dial/read/write timeouts.

| Parameter | Default | Purpose |
| --- | --- | --- |
| `connectTimeoutMs` | `10000` | connection establishment limit (dial + handshake) |
| `readTimeoutMs` | `0` (off) | idle timeout for waiting for an inbound message in `read()` |
| `writeTimeoutMs` | `30000` | max time to send one message |
| `maxMessageBytes` | `1048576` (1 MiB) | size limit of a single inbound message; exceeding it ends the input (`read()` → `null`) |
| `subprotocols` | `[]` | WebSocket subprotocols offered in the handshake |

```php
$client = new WsClient(new WsClientOptions(
    connectTimeoutMs: 5_000,
    readTimeoutMs:    30_000,
    writeTimeoutMs:   10_000,
    maxMessageBytes:  4 * 1024 * 1024,
    subprotocols:     ['chat'],
));
```

## Error handling

| Case | Exception |
| --- | --- |
| Failed to dial (refused / DNS-fail / connect-timeout / upgrade rejection) | `SConcur\Exceptions\WsClient\WsClientConnectException`, thrown by `connect()` |
| `write()` to a broken connection | `SConcur\Exceptions\WsClient\WsClientConnectionClosedException` |
| Peer closed the connection / idle timeout / `maxMessageBytes` exceeded | not an exception — `read()` returns `null` |

The extension marks network failures with a `net:` marker, preserved in the exception
message.

## Internals

PHP (`src/Features/WsClient/`): `WsClient::connect()` assembles a `ConnectPayload`,
dials via `FeatureExecutor::exec()`, decodes `ConnectionMeta`
(`cid`/`ra`/`la`/`su`) and builds a `Dto\Connection` whose inbound stream key is
the connect result key. That `Dto\Connection` descends from
`Features\Socket\Dto\AbstractConnection`: `read()` strips the one-byte type marker
(text/binary) and `write()` carries the message type through `SendPayload`.
`WsClientCommandEnum` and `Payloads/` are the `Connect`/`Send`/`Close` envelope,
a mirror of the extension's structs.

Rust (`ext/src/features/wsclient/`): the connect path performs the upgrade with
`connectTimeout` (cancellable by the flow context) and registers a streaming
`connectionState` — the first `Next` is the metadata, then inbound messages from a
read runtime task — plus the write loop, cleaned up on flow stop; `mod.rs`
dispatches the commands by `cid`. The write loop that waits for each flush and the
message-type codec live in the neutral `ext/src/ws/`, shared with the WS
server (like `ext/src/socket/` for the raw TCP pair).

## Not in v1

TLS (`wss://`), `permessage-deflate` (the library can do it, not enabled yet), a
connection pool / keep-alive (each `connect()` is a new connection) and
auto-reconnect (application side). The library's general limits — see the
[README](../README.md).

## Testing

PHP feature tests are in `tests/feature/Features/WsClient/` — edge and error
cases plus the concurrency contract on `BaseAsyncTestCase`, against a real
SConcur `WsServer` spawned via `TestWsServer`; the extension is covered by
the core's own unit tests. The benchmark (`make bench-ws-client c=20`) runs N
round-trips to the demo server's `msleep` endpoint, concurrent async against
sequential native (raw WS framing in PHP) and sync; server-side pool benches are
`make bench-ws-server-io` / `bench-ws-server-cpu` / `bench-ws-throughput`.

Run: `make test c="--filter=WsClient"`, `make ext-test`.
