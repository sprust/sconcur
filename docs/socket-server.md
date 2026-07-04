English | [Русский](socket-server.ru.md)

# Socket server (TCP)

Long-lived TCP server: the network lives in the Go extension, each accepted connection
is streamed into PHP and handled in its own coroutine. The model is push: the handler
receives a connection object and drives the dialogue itself — it reads inbound frames
and pushes frames to the client at any time (server push), rather than "one response
per message".

The reference for the design is the [HTTP server](http-server.md); the socket server
reuses its machinery (streaming state, `Scheduler::serve`) and runs under the same
[worker master](worker-master.md).

## Contents

- [Framing](#framing)
- [Quick start](#quick-start)
- [Connection: read / write / close](#connection-read--write--close)
- [Server push](#server-push)
- [Parameters](#parameters)
- [Concurrency](#concurrency)
- [Error handling](#error-handling)
- [Graceful shutdown and SO_REUSEPORT](#graceful-shutdown-and-so_reuseport)
- [Startup and shutdown log](#startup-and-shutdown-log)
- [Running under the worker master](#running-under-the-worker-master)
- [Limits](#limits)

## Framing

The connection's byte stream is sliced into frames by a length-prefix scheme: `uint32`
big-endian payload length (4 bytes), then the payload itself. The same format in both
directions. Binary-safe, no escaping, with a natural `maxMessageBytes` limit (on
inbound frames).

```
[len=5]hello[len=3]bye
```

The client frames the same way. PHP example:

```php
fwrite($connection, pack('N', strlen($data)) . $data); // send a frame
```

## Quick start

```php
use SConcur\Features\SocketServer\Dto\Connection;
use SConcur\Features\SocketServer\SocketServer;

$server = new SocketServer(address: '0.0.0.0:9100');

$server->serve(static function (Connection $connection): void {
    // echo: read frames and send them back while the connection is alive
    while (($frame = $connection->read()) !== null) {
        $connection->write($frame);
    }
});
```

The handler — `Closure(Connection): void` — runs in the connection's coroutine and
manages its lifecycle itself. When the handler returns, the connection is closed
automatically.

## Connection: read / write / close

`Connection` (`src/Features/SocketServer/Dto/Connection.php`):

| Member | Description |
| --- | --- |
| `read(): ?string` | the next inbound frame; `null` — the client closed its side (EOF) or the connection ended. Cooperatively suspends the coroutine until a frame arrives |
| `write(string $data): void` | push a frame to the client (with backpressure: waits until the bytes are flushed to the socket). Throws `SocketServerConnectionClosedException` if the connection is broken |
| `close(): void` | close the connection (idempotent, best-effort) |
| `isClosed(): bool` | whether the connection is closed |
| `id`, `remoteAddr`, `localAddr` | the connection's identifier and addresses |

Inside the handler you can make async calls (Sleeper, Mongodb, SQL, HTTP client)
between reads/writes — the coroutine cooperatively suspends, and other connections
keep being served.

## Server push

The main difference from "request-response": the handler is not required to answer
every inbound frame and may push any number of frames, including without any inbound
ones:

```php
$server->serve(static function (Connection $connection): void {
    // one inbound frame -> a stream of response frames
    $request = $connection->read();

    for ($i = 0; $i < 10; $i++) {
        $connection->write("update-$i");

        Sleeper::sleep(seconds: 1); // async work runs between pushes
    }
});
```

Push to other connections (broadcast/chat/pub-sub) is not built in in this version —
the application can keep references to `Connection` and write to them itself
(`Connection::write` is routed by `id` on the Go side through the global
`pendingConnections` map).

## Parameters

The `SocketServer` constructor (defaults mirror Go):

| Parameter | Default | Purpose |
| --- | --- | --- |
| `address` | `0.0.0.0:9100` | listener address `host:port` |
| `readTimeoutMs` | `0` (off) | idle timeout while waiting for an inbound frame in `read()`. A push-only handler that never reads is unaffected |
| `writeTimeoutMs` | `30000` | max time to write one frame to the client |
| `maxMessageBytes` | `1048576` (1 MiB) | length limit of one inbound frame; exceeding it ends the connection's input |
| `maxConcurrency` | `0` (unlimited) | max connections served at once; excess ones wait for a free slot |
| `maxConnections` | `0` (unlimited) | stop the server after N served connections (a guard against leaks) |
| `shutdownTimeoutMs` | `5000` | timeout for draining in-flight connections on shutdown |
| `reusePort` | `false` | `SO_REUSEPORT` — a process pool on one port (Linux) |
| `onError` | `null` | handler-error hook |
| `masterPid` | `null` | orphan check under the master |

## Concurrency

Concurrency is between connections: each connection is in its own coroutine, so dozens
of connections run in parallel. Each `read()`/`write()` cooperatively suspends the
coroutine without blocking the others.

`maxConcurrency` bounds the number of connections served at once (the slot is held for
the connection's whole lifetime); excess connections are accepted on the socket but
not handled until a slot frees up.

> **CPU-bound / native block.** A heavy synchronous handler (native `sleep`, a CPU
> loop) freezes the single PHP thread — the cooperative model does not preempt it. In
> the push model there is no per-message timeout (there is no notion of a "request");
> the boundaries are the idle `readTimeoutMs`, `writeTimeoutMs`, and graceful shutdown.

## Error handling

If the handler throws, the exception is caught, the connection is closed, and the
`onError: Closure(Throwable, Connection): void` hook may observe it (logging) and, if
needed, push a final frame before the close:

```php
$server = new SocketServer(
    onError: function (Throwable $exception, Connection $connection): void {
        error_log($exception->getMessage());

        try {
            $connection->write("error\n");
        } catch (Throwable) {
        }
    },
);
```

In ordinary code `Connection::write` throws `ConnectionClosedException` once the client
has already disconnected — the handler can catch it and stop the push loop, or let it
unwind the coroutine.

## Graceful shutdown and SO_REUSEPORT

On a signal (SIGTERM/SIGINT), on reaching `maxConnections`, or on being orphaned
(`masterPid`), the server stops accepting new connections (closes the listener) and
half-closes in-flight connections for reading (`CloseRead`): a handler reading in a
loop gets EOF (its current write still goes through) and returns. A push-only handler
that never reads does not notice the EOF and is finished off by a forced close after
the grace period (`drainGrace`, 2 s). Then the in-flight drain is bounded by
`shutdownTimeoutMs`. On a `SO_REUSEPORT` pool the kernel immediately hands new
connections to siblings, after which the process exits on its own.

`reusePort: true` lets several processes listen on one port (one process per core) —
the basis for scaling under the worker master.

Each shutdown step is written as a line to `STDOUT` — see [Startup and shutdown log](#startup-and-shutdown-log).

## Startup and shutdown log

The server writes lifecycle lines to `STDOUT` (alongside the per-connection access log,
which the Go side writes when each connection closes). On startup — one line, as soon
as the listener is up:

```
2026-06-28T12:00:00.000000 sconcur socket server listening on 0.0.0.0:8090 pid=12345 version=0.5.1 maxConcurrency=0 maxConnections=0 reusePort=0
```

It carries the address, the process pid, the extension version, and the key limits. On
graceful shutdown — one line per step:

```
2026-06-28T12:00:01.000000 sconcur socket server shutdown: stop accepting (reason=signal), draining 2 in-flight
2026-06-28T12:00:01.050000 sconcur socket server shutdown: drained all in-flight
2026-06-28T12:00:01.060000 sconcur socket server shutdown: stopped
```

`reason=signal` — shutdown on `SIGTERM`/`SIGINT` (or loss of the master); `reason=limit`
— on reaching the `maxConnections` limit. The lines are written by the PHP side and
flushed immediately. Under the [worker master](worker-master.md) they land in the shared
log.

## Running under the worker master

The server is a "server-agnostic" worker for `bin/sconcur-server`. An example config is
`config/sconcur.socket-server.config.json`; the worker script builds the server from
argv:

```php
use SConcur\Features\SocketServer\Dto\Connection;
use SConcur\Features\SocketServer\SocketServer;

$server = SocketServer::fromArgs($_SERVER['argv']);

$server->serve(static function (Connection $connection): void {
    while (($frame = $connection->read()) !== null) {
        $connection->write($frame);
    }
});
```

The master expands the parameters from the `server` block of the JSON config into
`--key=value` argv (which `fromArgs` parses), and passes its own pid as the
`--masterPid` flag (orphan check). `reusePort: true` enables a pool of processes across
cores. Details are in the [worker master](worker-master.md).

## Limits

- TCP only. Unix sockets are not supported (`SO_REUSEPORT` does not apply to
  `AF_UNIX`; multi-worker for unix requires fd inheritance — a separate task).
- Broadcast is not built in. Push to other connections is up to the application (keep
  `Connection` handles).
- No per-message timeout. The push model is connection-oriented; the boundaries are
  `readTimeoutMs`/`writeTimeoutMs` and graceful shutdown.
- The library's general limits (CLI only, Linux only, NTS only, no `pcntl_fork` after
  the extension is loaded) — see [README](../README.md).
