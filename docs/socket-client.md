English | [Русский](socket-client.ru.md)

# Socket client (TCP)

An asynchronous TCP client with length-prefix framing — the mirror pair to the
[socket server](socket-server.md), just as the [HTTP client](http-client.md) is the pair
to the HTTP server. All network I/O (DNS, dial, read, write) lives in the Go extension:
`connect()` goes into a goroutine, the coroutine (Fiber) suspends, so dozens of
connections fan out. Outside a `WaitGroup` the same API works synchronously
(see [README → Usage](../README.md)).

The model is a long-lived bidirectional connection (not "request-response"): the
application dials a connection, gets a `Connection` object and drives the dialogue
itself — `read()` pulls inbound frames, `write()` pushes outbound ones, `close()`
closes it.

## Contents

- [Framing](#framing)
- [Quick start](#quick-start)
- [Connection: read / write / close](#connection-read--write--close)
- [Fan-out concurrency](#fan-out-concurrency)
- [Options and timeouts](#options-and-timeouts)
- [Error handling](#error-handling)
- [Internals](#internals)
- [Not in v1](#not-in-v1)
- [Testing](#testing)

## Framing

The connection byte stream is cut into frames by a length-prefix scheme: a `uint32`
big-endian payload length, then the payload itself. The same format in both directions,
binary-safe, with a natural `maxMessageBytes` limit. This is exactly the codec used by
the socket server (shared Go code — the `ext/internal/socket` package), so a SConcur
client and a SConcur server are compatible out of the box.

## Quick start

```php
use SConcur\Features\SocketClient\SocketClient;

$client = new SocketClient();

$connection = $client->connect('127.0.0.1:9100');

$connection->write('ping');
$reply = $connection->read();          // ?string

$connection->close();
```

`connect()` returns an open `Connection`. It is best to run the whole dialogue inside
the same coroutine as `connect()`: when the coroutine finishes, its flow is stopped and
an unfinished connection on the Go side is closed (the same caveat as with
`HttpClient`/`SocketServer`).

## Connection: read / write / close

`Connection` (`src/Features/SocketClient/Dto/Connection.php`, shared base class —
`src/Features/Socket/Dto/AbstractConnection.php`):

| Member | Description |
| --- | --- |
| `read(): ?string` | the next inbound frame; `null` — the peer closed its side (EOF), the connection ended, or the input limit was exceeded. Cooperatively suspends the coroutine until a frame arrives |
| `write(string $data): void` | push a frame to the peer (with backpressure: waits for the flush). Throws `SocketClientConnectionClosedException` if the connection is broken |
| `close(): void` | close the connection (idempotent, best-effort) |
| `isClosed(): bool` | whether the connection is closed |
| `id`, `remoteAddr`, `localAddr` | the connection's identifier and addresses |

Inside the dialogue you can make asynchronous calls (Sleeper, Mongodb, SQL, HTTP client)
between reads/writes — the coroutine cooperatively suspends, other connections keep
running.

## Fan-out concurrency

```php
use SConcur\WaitGroup;

$client    = new SocketClient();
$waitGroup = WaitGroup::create();

foreach ($addresses as $address) {
    $waitGroup->add(function () use ($client, $address) {
        $connection = $client->connect($address);

        $connection->write('hello');
        $reply = $connection->read();

        $connection->close();

        return $reply;
    });
}

/** @var array<int|string, ?string> $replies */
$replies = $waitGroup->waitResults(); // total time ≈ the slowest connection
```

Each connection lives in its own coroutine: `connect/read/write` cooperatively suspend
it, the remaining connections keep being served.

## Options and timeouts

`SConcur\Features\SocketClient\SocketClientOptions` (`readonly`), all timeouts in ms.
The PHP defaults mirror Go. A long-lived connection has no single "operation time" —
that role is played by the dial/read/write timeouts (as with `SocketServer`, which also
has no per-message timeout).

| Option | Default | Purpose |
| --- | --- | --- |
| `connectTimeoutMs` | `10000` | limit on establishing the TCP connection (dial). |
| `readTimeoutMs` | `0` (off) | idle timeout waiting for an inbound frame in `read()`. |
| `writeTimeoutMs` | `30000` | max time to write one frame. |
| `maxMessageBytes` | `1048576` (1 MiB) | length limit of one inbound frame; exceeding it ends the input (`read()` → `null`). |

```php
use SConcur\Features\SocketClient\SocketClientOptions;

$client = new SocketClient(new SocketClientOptions(
    connectTimeoutMs: 5_000,
    readTimeoutMs:    30_000,
    writeTimeoutMs:   10_000,
    maxMessageBytes:  4 * 1024 * 1024,
));
```

## Error handling

| Case | Exception |
| --- | --- |
| Failed to dial the connection (refused / DNS-fail / connect-timeout) | `SConcur\Exceptions\SocketClient\SocketClientConnectException` (thrown by `connect()`) |
| `write()` to a broken connection | `SConcur\Exceptions\SocketClient\SocketClientConnectionClosedException` |
| The peer closed the connection / EOF / idle-timeout / `maxMessageBytes` exceeded | not an exception — `read()` returns `null` |

The Go side tags network failures with a `net:` marker, and it is preserved in the
exception message (handy for logging/retries).

```php
use SConcur\Exceptions\SocketClient\SocketClientConnectException;

try {
    $connection = $client->connect('127.0.0.1:9100');
} catch (SocketClientConnectException $exception) {
    // retry / logging; $exception->getMessage() contains the "net:" marker
}
```

## Internals

PHP (`src/Features/SocketClient/`):

- `SocketClient` — the public API: `connect()` assembles a `ConnectPayload`, dials the
  connection via `FeatureExecutor::exec()`, decodes `ConnectionMeta` (`cid`/`ra`/`la`)
  and builds a `Dto\Connection` with the inbound stream key = the connect result key.
- `SocketClientOptions` — a `readonly` options DTO.
- `SocketClientCommandEnum` — the envelope's sub-operations: `Connect`/`Send`/`Close`.
- `Dto\Connection` — a thin subclass of `Features\Socket\Dto\AbstractConnection`
  (shared with the socket server): plugs in `SendPayload`/`ClosePayload` and the matching
  exception.
- `Payloads/` — the envelope `Base\BaseSocketClientPayload` (`cm`/`p`) + `Connect`/`Send`/
  `Close` payloads, mirrors of the Go structs.

Go (`ext/internal/features/socketclient/`):

- `payloads/payloads.go` — `Envelope`, `ConnectParams`, `SendParams`, `CloseParams`,
  `ConnectionMeta` (1:1 with PHP).
- `feature.go` — `SocketClientFeature` (singleton): the command dispatcher;
  `handleSend`/`handleClose` route `Send`/`Close` by `cid` via `dispatch` into the
  connection's write loop.
- `connect.go` — `handleConnect`: dial with `connectTimeout` (cancellable by the flow
  context), registration of the streaming `connectionState` (the first `Next` is the
  metadata, then the inbound frames) and the write loop; cleanup on flow stop.

Shared code (`ext/internal/socket/`, neutral, not tied to server/client): the frame
codec (`frame.go`), the inbound frame stream (`MessageState`) and the write loop with
backpressure (`PendingConnection`/`ConsumeCommands`/`Dispatch`). Both the socket server
and the socket client use them — but not each other.

Reading inbound frames is `next()` over the connect streaming state (like the response
body of `HttpClient`); write/close is `exec(Send/Close)` routed by `cid` into the write
loop (like `Respond` on the socket server).

## Not in v1

| What | Comment |
| --- | --- |
| TLS | later, as an option (as with `HttpClient`). |
| Unix sockets | TCP only (as with `SocketServer`). |
| Connection pool / keep-alive | every `connect()` is a new connection. |
| Auto-reconnect | on the application side. |

The library's general limits (CLI only, Linux only, NTS only, no `pcntl_fork` after the
extension is loaded) — see [README](../README.md).

## Testing

- PHP feature tests — `tests/feature/Features/SocketClient/`: edge/error cases
  (`SocketClientTest`) and the concurrency contract on `BaseAsyncTestCase`
  (`SocketClientConcurrencyTest`). The target is a real SConcur `SocketServer`
  (`tests/servers/socket/socket-server.php`), brought up via
  `SConcur\Tests\Impl\SocketServer\TestSocketServer`.
- Go tests — `ext/internal/socket/` (codec, `MessageState`, write loop) and
  `ext/internal/features/socketclient/connect_test.go` (`connectionState`:
  metadata → inbound frames → clean end).

- Benchmark — `tests/benchmarks/socket-client.php` (`make bench-socket-client`):
  N round-trips to the I/O endpoint (`msleep:<ms>`) of the demo server; the async run via
  `WaitGroup` shows the fan-out (total time ≈ one round-trip), against sequential native
  (raw PHP sockets) and sync.

Run: `make test c="--filter=SocketClient"`, `make ext-test`,
`make bench-socket-client c=20`.

```
make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test
```
