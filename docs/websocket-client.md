English | [Русский](websocket-client.ru.md)

# WebSocket client

An asynchronous WebSocket client — the mirror pair of the [WebSocket server](websocket-server.md),
just as the [socket client](socket-client.md) is the pair of the socket server. All network I/O (dial,
upgrade handshake, read, write) lives in the Go extension: `connect()` goes into a
goroutine, the coroutine (Fiber) suspends, so dozens of connections are dialed
"fanned out". Outside a `WaitGroup` the same API works synchronously (see
[README → Usage](../README.md)).

The model is a long-lived bidirectional connection: the application dials a connection,
gets a `Connection` object and drives the conversation itself — `read()` pulls inbound messages,
`write()` sends outbound ones (text or binary), `close()` closes.

## Contents

- [Quick start](#quick-start)
- [Connection: read / write / close](#connection-read--write--close)
- [Concurrency ("fan-out")](#concurrency-fan-out)
- [Parameters and timeouts](#parameters-and-timeouts)
- [Error handling](#error-handling)
- [Internals](#internals)
- [Not in v1](#not-in-v1)
- [Testing](#testing)

## Quick start

```php
use SConcur\Features\WsClient\WsClient;

$client = new WsClient();

$connection = $client->connect('ws://127.0.0.1:9200/');

$connection->write('ping');
$reply = $connection->read();          // ?string

$connection->close();
```

`connect()` takes a full `ws://host:port/path` URL and returns an open
`Connection`. It is best to drive the whole conversation inside the same coroutine as
`connect()`: when the coroutine finishes its flow is stopped and the unread connection on the
Go side is closed (the same caveat as with `HttpClient`/`SocketClient`).

## Connection: read / write / close

`Connection` (`src/Features/WsClient/Dto/Connection.php`, shared base class —
`src/Features/Socket/Dto/AbstractConnection.php`):

| Member | Description |
| --- | --- |
| `read(): ?string` | the next inbound message; `null` — the peer closed its side, the connection ended, or `maxMessageBytes` was exceeded. Cooperatively suspends the coroutine until a message arrives |
| `write(string $data, bool $binary = false): void` | send a message to the peer (with backpressure: waits for the flush). Text by default, `binary: true` — binary. Throws `WsClientConnectionClosedException` if the connection is gone |
| `lastMessageWasBinary(): bool` | whether the last `read()` was binary (otherwise text) |
| `close(): void` | close the connection (idempotent, best-effort) |
| `isClosed(): bool` | whether the connection is closed |
| `id`, `remoteAddr`, `localAddr`, `subprotocol` | identifier, addresses and the negotiated subprotocol. `remoteAddr` is the host from the connection URL (may be without a port), `localAddr` on the dial side is currently always empty |

Inside the conversation you can make asynchronous calls (Sleeper, Mongodb, SQL, HTTP client)
between reads/writes — the coroutine cooperatively suspends, other connections
keep working.

## Concurrency ("fan-out")

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

Each connection lives in its own coroutine: `connect/read/write` cooperatively
suspend it, the other connections keep being served.

## Parameters and timeouts

`SConcur\Features\WsClient\WsClientOptions` (`readonly`), all timeouts in ms. The PHP defaults
mirror Go. A long-lived connection has no single "operation time" — its role is played by
the dial/read/write timeouts (as with `WsServer`, which also has no per-message timeout).

| Parameter | Default | Purpose |
| --- | --- | --- |
| `connectTimeoutMs` | `10000` | connection establishment limit (dial + handshake) |
| `readTimeoutMs` | `0` (off) | idle timeout for waiting for an inbound message in `read()` |
| `writeTimeoutMs` | `30000` | max time to send one message |
| `maxMessageBytes` | `1048576` (1 MiB) | size limit of a single inbound message; exceeding it ends the input (`read()` → `null`) |
| `subprotocols` | `[]` | WebSocket subprotocols offered in the handshake |

```php
use SConcur\Features\WsClient\WsClientOptions;

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
| Failed to dial the connection (refused / DNS-fail / connect-timeout / upgrade rejection) | `SConcur\Exceptions\WsClient\WsClientConnectException` (thrown by `connect()`) |
| `write()` to a broken connection | `SConcur\Exceptions\WsClient\WsClientConnectionClosedException` |
| Peer closed the connection / idle timeout / `maxMessageBytes` exceeded | not an exception — `read()` returns `null` |

The Go side marks network failures with a `net:` marker, and it is preserved in the exception
message (handy for logging/retries).

```php
use SConcur\Exceptions\WsClient\WsClientConnectException;

try {
    $connection = $client->connect('ws://127.0.0.1:9200/');
} catch (WsClientConnectException $exception) {
    // retry / logging; $exception->getMessage() contains the "net:" marker
}
```

## Internals

PHP (`src/Features/WsClient/`):

- `WsClient` — the public API: `connect()` assembles a `ConnectPayload`, dials the connection
  via `FeatureExecutor::exec()`, decodes `ConnectionMeta`
  (`cid`/`ra`/`la`/`su`) and builds `Dto\Connection` with the inbound stream key = the connect
  result key.
- `WsClientOptions` — a `readonly` options DTO.
- `WsClientCommandEnum` — envelope sub-operations: `Connect`/`Send`/`Close`.
- `Dto\Connection` — a descendant of `Features\Socket\Dto\AbstractConnection`: `read()` strips
  the one-byte type marker (text/binary), `write()` carries the message type through `SendPayload`,
  plus the paired exception.
- `Payloads/` — the `Base\BaseWsClientPayload` (`cm`/`p`) envelope + `Connect`/`Send`/`Close`
  payloads, mirrors of the Go structs.

Go (`ext/internal/features/wsclient/`):

- `payloads/payloads.go` — `Envelope`, `ConnectParams`, `SendParams`, `CloseParams`,
  `ConnectionMeta` (1:1 with PHP).
- `feature.go` — `WsClientFeature` (singleton): a command dispatcher; `Send`/`Close`
  are routed by `cid` to the connection's write loop.
- `connect.go` — `handleConnect`: `websocket.Dial` with `connectTimeout` (cancellable
  by the flow context), registration of a streaming `connectionState` (the first `Next` —
  metadata, then — inbound messages) and the write loop; cleanup on flow stop.

Shared code (`ext/internal/ws/`, neutral, not tied to server/client): the write loop
with backpressure (`PendingConnection`/`ConsumeCommands`/`Dispatch`) and the message-type codec
(`EncodeInbound`/`MessageTypeFromCode`). Both the WS server and the WS client use them — but not
each other (like `ext/internal/socket` for the socket-server/client pair).

## Not in v1

| What | Comment |
| --- | --- |
| TLS (`wss://`) | later as an option (like `HttpClient`) |
| `permessage-deflate` | the library can do it, not enabled yet |
| Connection pool / keep-alive | each `connect()` — a new connection |
| Auto-reconnect | on the application side |

The library's general limits (CLI only, Linux only, NTS only, no
`pcntl_fork` after the extension is loaded) — see [README](../README.md).

## Testing

- PHP feature tests — `tests/feature/Features/WsClient/`: edge/error cases
  (`WsClientTest`) and the concurrency contract on `BaseAsyncTestCase`
  (`WsClientConcurrencyTest`). The target is a real SConcur `WsServer`
  (`tests/servers/ws/ws-server.php`), spawned via
  `SConcur\Tests\Impl\WsServer\TestWsServer`.
- Go tests — `ext/internal/features/wsclient/connect_test.go` (`connectionState`:
  metadata → inbound messages → clean end).
- Benchmark — `tests/benchmarks/ws-client.php` (`make bench-ws-client`): N round-trips
  to the I/O endpoint (`msleep:<ms>`) of the demo server; the async run via `WaitGroup` shows
  the "fan-out" (total time ≈ one round-trip), against sequential native (raw
  WS framing in PHP) and sync. The server-side pool benches — `make bench-ws-server-io` /
  `bench-ws-server-cpu` / `bench-ws-throughput`.

Run: `make test c="--filter=WsClient"`, `make ext-test`, `make bench-ws-client c=20`.

```
make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test
```
