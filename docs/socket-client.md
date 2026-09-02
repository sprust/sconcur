English | [Русский](socket-client.ru.md)

# Socket client (TCP)

An asynchronous TCP client with length-prefix framing — the dial-side mirror of
the [socket server](socket-server.md), just as the [HTTP client](http-client.md)
is the pair to the HTTP server. All network I/O (DNS, dial, read, write) lives
in the Go extension: `connect()` goes into a goroutine, the coroutine suspends,
so dozens of connections can be dialled at the same time. Outside a `WaitGroup`
the same API works synchronously.

The model is a long-lived bidirectional connection, not request-response: the
application dials, gets a `Connection` and drives the dialogue itself. The framing
codec is shared with the socket server (`ext-go-legacy/internal/socket`), so a SConcur client
and a SConcur server are compatible out of the box.

## Quick start

```php
use SConcur\Features\SocketClient\SocketClient;

$client = new SocketClient();

$connection = $client->connect('127.0.0.1:9100');

$connection->write('ping');
$reply = $connection->read();          // ?string

$connection->close();
```

Best to run the whole dialogue inside the same coroutine as `connect()`: when the
coroutine finishes, its flow is stopped and an unfinished connection on the Go side
is closed (the same caveat as with `HttpClient`/`SocketServer`).

## Connection: read / write / close

`Connection` (`src/Features/SocketClient/Dto/Connection.php`, shared base class —
`src/Features/Socket/Dto/AbstractConnection.php`):

| Member | Description |
| --- | --- |
| `read(): ?string` | the next inbound frame; `null` — the peer closed its side (EOF), the connection ended, or the input limit was exceeded. Cooperatively suspends the coroutine |
| `write(string $data): void` | send a frame to the peer; waits until it has actually been flushed, so a fast writer cannot outrun the peer. Throws `SocketClientConnectionClosedException` if the connection is broken |
| `close(): void` | close the connection (idempotent, best-effort) |
| `isClosed(): bool` | whether the connection is closed |
| `id`, `remoteAddr`, `localAddr` | identifier and addresses |

Between reads and writes you can make asynchronous calls (Sleeper, Mongodb, SQL,
HTTP client) — the coroutine suspends cooperatively, other connections keep
running.

## Running connections concurrently

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

## Options and timeouts

`SConcur\Features\SocketClient\SocketClientOptions` (`readonly`), all timeouts in
ms; the PHP defaults mirror Go. A long-lived connection has no single "operation
time" — that role is played by the dial/read/write timeouts.

| Option | Default | Purpose |
| --- | --- | --- |
| `connectTimeoutMs` | `10000` | limit on establishing the TCP connection (dial) |
| `readTimeoutMs` | `0` (off) | idle timeout waiting for an inbound frame in `read()` |
| `writeTimeoutMs` | `30000` | max time to write one frame |
| `maxMessageBytes` | `1048576` (1 MiB) | length limit of one inbound frame; exceeding it ends the input (`read()` → `null`) |

```php
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
| Failed to dial (refused / DNS-fail / connect-timeout) | `SConcur\Exceptions\SocketClient\SocketClientConnectException`, thrown by `connect()` |
| `write()` to a broken connection | `SConcur\Exceptions\SocketClient\SocketClientConnectionClosedException` |
| The peer closed the connection / EOF / idle-timeout / `maxMessageBytes` exceeded | not an exception — `read()` returns `null` |

The Go side tags network failures with a `net:` marker, preserved in the exception
message (handy for logging and retries).

## Internals

PHP (`src/Features/SocketClient/`): `SocketClient::connect()` assembles a
`ConnectPayload`, dials via `FeatureExecutor::exec()`, decodes `ConnectionMeta`
(`cid`/`ra`/`la`) and builds a `Dto\Connection` whose inbound stream key is the
connect result key. `Dto\Connection` is a thin subclass of
`Features\Socket\Dto\AbstractConnection` (shared with the socket server) plugging
in `SendPayload`/`ClosePayload` and the matching exception;
`SocketClientCommandEnum` and `Payloads/` are the `Connect`/`Send`/`Close`
envelope, a mirror of the Go structs.

Go (`ext-go-legacy/internal/features/socketclient/`): `connect.go` dials with
`connectTimeout` (cancellable by the flow context) and registers the streaming
`connectionState` — the first `Next` is the metadata, then the inbound frames —
plus the write loop, cleaned up on flow stop; `feature.go` dispatches the
commands, routing `Send`/`Close` by `cid` into that write loop. The frame codec,
`MessageState` and the write loop that waits for each flush live in the neutral
`ext-go-legacy/internal/socket/`, shared with the socket server.

So reading inbound frames is `next()` over the connect streaming state (like
`HttpClient`'s response body), and write/close is `exec(Send/Close)` routed by
`cid` (like `Respond` on the server).

## Not in v1

TLS (later, as an option), unix sockets (TCP only), a connection pool / keep-alive
(every `connect()` is a new connection) and auto-reconnect (application side). The
library's general limits — see the [README](../README.md).

## Testing

PHP feature tests are in `tests/feature/Features/SocketClient/` — edge and error
cases plus the concurrency contract on `BaseAsyncTestCase`, run against a real
SConcur `SocketServer` brought up via `TestSocketServer`. Go tests cover the
shared `ext-go-legacy/internal/socket/` package and `connect_test.go`. The benchmark
(`make bench-socket-client c=20`) runs N round-trips to the demo server's
`msleep` endpoint, concurrent async against sequential native (raw PHP sockets)
and sync.

Run: `make test c="--filter=SocketClient"`, `make ext-test`.
