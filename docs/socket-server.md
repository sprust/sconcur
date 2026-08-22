English | [Русский](socket-server.ru.md)

# Socket server (TCP)

A long-lived TCP server: the network lives in the Go extension, each accepted
connection is streamed into PHP and handled in its own coroutine. The model is
push — the handler receives a connection object and drives the dialogue itself,
reading inbound frames and pushing frames to the client at any time, rather than
"one response per message".

The design reference is the [HTTP server](http-server.md): the socket server reuses
its machinery (the self-pumping accept stream, `Scheduler::serve`) and runs under the same
[worker master](worker-master.md).

## Framing

The byte stream is sliced into frames by a length-prefix scheme: a `uint32`
big-endian payload length, then the payload — `[len=5]hello[len=3]bye`. The same
format in both directions: binary-safe, no escaping, with a natural
`maxMessageBytes` limit on inbound frames. The client frames the same way:
`fwrite($connection, pack('N', strlen($data)) . $data)`.

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
manages its lifecycle itself; when it returns, the connection is closed
automatically.

## Connection: read / write / close

`Connection` (`src/Features/SocketServer/Dto/Connection.php`):

| Member | Description |
| --- | --- |
| `read(): ?string` | the next inbound frame; `null` — the client closed its side (EOF) or the connection ended. Cooperatively suspends the coroutine until a frame arrives |
| `write(string $data): void` | send a frame to the client; waits until the bytes are actually flushed, so a fast handler cannot outrun the client. Throws `SocketServerConnectionClosedException` if the connection is broken |
| `close(): void` | close the connection (idempotent, best-effort) |
| `isClosed(): bool` | whether the connection is closed |
| `id`, `remoteAddr`, `localAddr` | identifier and addresses |

Inside the handler you can make async calls (Sleeper, Mongodb, SQL, HTTP client)
between reads and writes — the coroutine suspends cooperatively and other
connections keep being served.

## Server push

The handler is not required to answer every inbound frame and may push any number
of frames, including without any inbound ones:

```php
$server->serve(static function (Connection $connection): void {
    $request = $connection->read();

    for ($i = 0; $i < 10; $i++) {
        $connection->write("update-$i");

        Sleeper::sleep(seconds: 1); // async work runs between pushes
    }
});
```

Push to other connections (broadcast, chat, pub-sub) is not built in — the
application can keep references to `Connection` and write to them itself
(`write` is routed by `id` on the Go side through the global `pendingConnections`
map).

## Parameters

The `SocketServer` constructor; the PHP defaults mirror Go.

| Parameter | Default | Purpose |
| --- | --- | --- |
| `address` | `0.0.0.0:9100` | listener address `host:port` |
| `readTimeoutMs` | `0` (off) | idle timeout while waiting for an inbound frame in `read()`. A push-only handler that never reads is unaffected |
| `writeTimeoutMs` | `30000` | max time to write one frame to the client |
| `maxMessageBytes` | `1048576` (1 MiB) | length limit of one inbound frame; exceeding it ends the connection's input |
| `maxConcurrency` | `0` (unlimited) | max connections served at once; excess ones wait for a free slot |
| `handlerTimeoutMs` | `0` (unlimited) | how long one connection handler may run before it is unwound where it stands — see [coroutine timeout](coroutine-timeout.md) |
| `maxConnections` | `0` (unlimited) | stop the server after N served connections (a guard against leaks) |
| `shutdownTimeoutMs` | `10000` | how long to wait for the active connections to finish on shutdown |
| `reusePort` | `false` | `SO_REUSEPORT` — a process pool on one port (Linux) |
| `onError` | `null` | handler-error hook |
| `masterPid` | `null` | orphan check under the master |
| `telemetrySocket` | `''` (off) | unix socket for stats snapshots, injected by the master ([stats](admin-stats.md)) |
| `serverName` | `'sconcur-server'` | worker name in the stats snapshots |
| `telemetryIntervalMs` | `0` | stats cadence (`0` — pusher default, 1000 ms) |
| `preemptionQuantumMs` | `5` | automatic-preemption quantum (`0` — off), see [coroutine switching](coroutine-switching.md) |

## Concurrency

Concurrency is between connections: each lives in its own coroutine, and every
`read()`/`write()` suspends it cooperatively without blocking the others.
`maxConcurrency` bounds the number of connections served at once (the slot is held
for the connection's whole lifetime); excess connections are accepted on the socket
but not handled until a slot frees up.

> A handler stuck in a native call (`sleep`, synchronous PDO/`curl`) freezes the
> single PHP thread — nothing preempts a native call. A userland CPU loop is
> preempted by default ([automatic preemption](coroutine-switching.md)), so it only
> delays the neighbours by the quantum. The push model has no per-message timeout
> (there is no notion of a "request"); the boundaries are the idle `readTimeoutMs`,
> `writeTimeoutMs` and graceful shutdown.

## Error handling

If the handler throws, the exception is caught, the connection is closed, and the
`onError: Closure(Throwable, Connection): void` hook may observe it and push a
final frame before the close. In ordinary code `write` throws
`SocketServerConnectionClosedException` once the client has disconnected — catch it
to stop the push loop, or let it unwind the coroutine.

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

## Graceful shutdown and SO_REUSEPORT

On a signal (SIGTERM/SIGINT), on reaching `maxConnections`, or on being orphaned
(`masterPid`), the server closes the listener and half-closes the active
connections for reading (`CloseRead`): a handler reading in a loop gets EOF (its
current write still goes through) and returns. A push-only handler that never
reads does not notice the EOF and is finished off by a forced close after the
grace period (`drainGrace`, 2 s), after which the wait is bounded by
`shutdownTimeoutMs`. In a `SO_REUSEPORT` pool the kernel immediately hands new
connections to siblings, and the process exits on its own. `reusePort: true` —
several processes on one port, one per core — is the basis for scaling under the
worker master.

Lifecycle lines go to `STDOUT`, alongside the per-connection access log that the Go
side writes when each connection closes:

```
2026-06-28T12:00:00.000000 sconcur socket server listening on 0.0.0.0:9100 pid=12345 version=0.9.0 maxConcurrency=0 maxConnections=0 reusePort=0
2026-06-28T12:00:01.000000 sconcur socket server shutdown: stop accepting (reason=signal), draining 2 in-flight
2026-06-28T12:00:01.050000 sconcur socket server shutdown: drained all in-flight
2026-06-28T12:00:01.060000 sconcur socket server shutdown: stopped
```

`reason=signal` — `SIGTERM`/`SIGINT` or the loss of the master; `reason=limit` —
the `maxConnections` limit. Under the [worker master](worker-master.md) these land
in the shared log.

## Running under the worker master

The server is a server-agnostic worker for `bin/sconcur-server`; an example config
is the `socket` group of `config/sconcur.servers.config.json`. The master expands the `server`
block of that config into `--key=value` argv (which `fromArgs` parses) and passes
its own pid as `--masterPid` for the orphan check — details in the
[worker master](worker-master.md).

```php
$server = SocketServer::fromArgs($_SERVER['argv']);

$server->serve(static function (Connection $connection): void {
    while (($frame = $connection->read()) !== null) {
        $connection->write($frame);
    }
});
```

## Limits

- TCP only. Unix sockets are not supported (`SO_REUSEPORT` does not apply to
  `AF_UNIX`; multi-worker for unix requires fd inheritance).
- Broadcast is not built in — push to other connections is up to the application.
- No per-message timeout: the push model is connection-oriented.
- The library's general limits (CLI only, Linux only, NTS only, no `pcntl_fork`
  after the extension is loaded) — see the [README](../README.md).
