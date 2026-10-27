English | [Русский](socket-server.ru.md)

# Socket server (TCP)

A long-lived TCP server: the network lives in the extension, each accepted
connection is streamed into PHP and handled in its own coroutine (a PHP Fiber the
scheduler runs). The model is
push — the handler receives a connection object and drives the dialogue itself,
reading inbound frames and pushing frames to the client at any time, rather than
"one response per message".

The design reference is the [HTTP server](http-server.md): the socket server reuses
its machinery (the accept stream the extension feeds into PHP by itself,
`Scheduler::serve`) and runs under the same
[worker master](worker-master.md).

## Framing

The byte stream is sliced into frames by a length-prefix scheme: a `uint32`
big-endian payload length, then the payload — `[len=5]hello[len=3]bye`. The same
format in both directions: binary-safe, no escaping, with the
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
| `close(): void` | close the connection (idempotent; does nothing if the connection is already gone) |
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
(`write` is routed by `id` inside the extension through the process-wide
connection registry in `ext/src/features/socketserver/mod.rs`).

## Parameters

The `SocketServer` constructor; the PHP defaults mirror the extension's.

| Parameter | Default | Purpose |
| --- | --- | --- |
| `address` | `0.0.0.0:9100` | listener address `host:port`, or `fd://N` — a listening socket bound elsewhere (systemd socket activation, say; `reusePort` must then stay off), see [one socket for the whole pool](http-server.md#one-socket-for-the-whole-pool) |
| `readTimeoutMs` | `0` (off) | idle timeout while waiting for an inbound frame in `read()`; on expiry `read()` returns `null`. A push-only handler that never reads is unaffected |
| `writeTimeoutMs` | `30000` | max time to write one frame to the client |
| `maxMessageBytes` | `1048576` (1 MiB) | length limit of one inbound frame; exceeding it ends the connection's input |
| `maxConcurrency` | `0` (unlimited) | max connections served at once; excess ones wait for a free slot |
| `handlerTimeoutMs` | `0` (unlimited) | how long one connection handler may run before it is unwound where it stands — see [coroutine timeout](coroutine-timeout.md) |
| `maxConnections` | `0` (unlimited) | stop the server after N served connections (a guard against leaks); spread under the master, see [restart policy](worker-master.md#restart-policy-and-maxrequests) |
| `shutdownTimeoutMs` | `10000` | accepted and not applied: finishing the connections already accepted is bounded by the grace period below, not by a clock of its own |
| `reusePort` | `false` | `SO_REUSEPORT` — a process pool on one port (Linux) |
| `onError` | `null` | handler-error hook |
| `masterPid` | `null` | orphan check: `null` — the master that forked this worker (`WorkerContext`), or none outside one |
| `telemetrySocket` | `''` (off) | unix socket for stats snapshots ([stats](admin-stats.md)); empty — `SCONCUR_TELEMETRY_SOCKET`, which the master sets for its workers |
| `serverName` | `'sconcur-server'` | worker name in the stats snapshots; left at the default — `SCONCUR_SERVER_NAME`, which the master sets |
| `telemetryIntervalMs` | `0` | snapshot cadence (`0` — `SCONCUR_TELEMETRY_INTERVAL_MS` from the environment, or the default of the component that sends them, 1000 ms) |
| `preemptionQuantumMs` | `0` | automatic-preemption quantum (`0` — off); read [process-wide state](coroutine-switching.md#process-wide-state) before turning it on |

## Concurrency

Concurrency is between connections: each lives in its own coroutine, and every
`read()`/`write()` suspends it cooperatively without blocking the others.
`maxConcurrency` bounds the number of connections served at once (the slot is held
for the connection's whole lifetime); excess connections are accepted on the socket
but not handled until a slot frees up.

> A handler stuck in a native call (`sleep`, synchronous PDO/`curl`) freezes the
> single PHP thread — nothing preempts a native call. A CPU loop in PHP code is
> preempted only with [automatic preemption](coroutine-switching.md) on, and then
> it delays the neighbours by the quantum.

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
(`masterPid`), the server closes the listener and stops reading on the active
connections (the peer gets no FIN until the connection closes for good): a handler
reading in a loop gets EOF (its current write still goes through) and returns. A
connection that has not sent its first frame yet gets 250 ms more for it. A push-only
handler that never reads does not notice the EOF and is finished off by a forced close
after the grace period (2 s). In a pool on one port the kernel immediately hands new
connections to the siblings, and the process exits on its own. The pool shares the
port with `reusePort: true`, as the HTTP server does — see
[scaling across cores](http-server.md#scaling-across-cores-so_reuseport).

Lifecycle lines go to `STDOUT`, alongside the per-connection access log that the
extension writes when each connection closes — the start time, the client address,
the frames written to it, the outcome (`ok`, `shutdown`, `write_error`) and the
duration:

```
2026-06-28T12:00:00.500000 127.0.0.1:51234 frames=3 ok 812.40ms
```

```
2026-06-28T12:00:00.000000 sconcur socket server listening on 0.0.0.0:9100 pid=12345 version=X.Y.Z maxConcurrency=0 maxConnections=0 reusePort=0
2026-06-28T12:00:01.000000 sconcur socket server shutdown: stop accepting (reason=signal), draining 2 in-flight
2026-06-28T12:00:01.050000 sconcur socket server shutdown: drained all in-flight
2026-06-28T12:00:01.060000 sconcur socket server shutdown: stopped
```

`reason=signal` — `SIGTERM`/`SIGINT` or the loss of the master; `reason=limit` —
the `maxConnections` limit (`reason=limit, limit=N`; under the master it is spread, see
[restart policy and `maxRequests`](worker-master.md#restart-policy-and-maxrequests)).
Under the [worker master](worker-master.md) these land in the shared log, and the
access lines can go to a file of the day instead — see
[access lines](worker-master.md#access-lines).

## Running under the worker master

A group's role builds the server and serves; the master needs to know nothing about it.
Under a master the server watches it on its own (`WorkerContext`) and reports to its
panel — details in the [worker master](worker-master.md).

```php
new WorkerGroupConfig(
    name: 'socket',
    role: static function (): void {
        new SocketServer(address: '0.0.0.0:9100', reusePort: true)
            ->serve(static function (Connection $connection): void {
                while (($frame = $connection->read()) !== null) {
                    $connection->write($frame);
                }
            });
    },
    workerCount: 4,
);
```

`SocketServer::fromArgs($_SERVER['argv'])` builds the same server from `--key=value`
flags, for a script that runs one on its own.

## Limits

- TCP only: `address` is `host:port`, and Unix sockets are not supported.
- Broadcast is not built in — push to other connections is up to the application.
- No per-message timeout (the push model has no request): the bounds are the idle
  `readTimeoutMs`, `writeTimeoutMs`, `handlerTimeoutMs` for the whole connection,
  and the graceful stop.
- The library's general limits are in the
  [README](../README.md#use-and-limitations).
