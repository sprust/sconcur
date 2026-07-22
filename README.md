English | [Русский](README.ru.md)

# SConcur

> ⚠️ Experimental project, not for production. Yet another attempt to make
> PHP asynchronous, but without a C extension — with one written in Go.

A concurrency library for PHP on top of a custom Go extension. The PHP side
(a Fiber) suspends while the Go extension runs the task (MongoDB operations,
sleep, and so on) concurrently in goroutines. PHP and Go exchange data over
MessagePack.

## Contents

- [Idea](#idea)
- [Example](#example)
- [What it replaces](#what-it-replaces)
- [How it works](#how-it-works)
- [Why Go specifically](#why-go-specifically)
- [Use and limitations](#use-and-limitations)
- [Tested versions](#tested-versions)
- [Documentation](#documentation)
- [Build](#build)
- [echo test](#echo-test)
- [Roadmap](#roadmap)

## Idea

Regular PHP is synchronous: `sleep()`, a PDO query, an HTTP call — each one
blocks the process, and they run strictly one after another. Two one-second
operations take two seconds.

SConcur runs such operations at the same time. You swap the blocking calls for
SConcur equivalents (see [What it replaces](#what-it-replaces)), wrap them in
coroutines, and the work moves into Go and runs in parallel goroutines. The total
time is bound by the slowest operation, not by their sum. PHP stays a thin
orchestration layer; all concurrency lives in Go.

## Example

Two coroutines run at the same time, each a one-second operation. Sequentially
this would be about two seconds; concurrently it is about one.

```php
use SConcur\WaitGroup;
use SConcur\Features\Sleeper\Sleeper;

$start = microtime(true);

$waitGroup = WaitGroup::create();

// coroutine 1: a one-second operation (instead of the blocking sleep())
$waitGroup->add(function () {
    Sleeper::sleep(seconds: 1);

    return 1;
});

// coroutine 2: the same operation again
$waitGroup->add(function () {
    Sleeper::sleep(seconds: 1);

    return 2;
});

$waitGroup->waitResults();

$seconds = round(microtime(true) - $start, 2);

echo "done in {$seconds} s" . PHP_EOL;
```

Output:

```
done in 1 s
```

Two one-second operations ran in parallel — about 1 s instead of 2.

## What it replaces

SConcur currently provides equivalents for the tools listed below.

Operations and clients — wrapped in a coroutine (`$waitGroup->add()`), they run
concurrently instead of blocking the process:

| Native PHP | SConcur | What changes |
| --- | --- | --- |
| `sleep()`, `usleep()` | `Sleeper::sleep()`, `Sleeper::usleep()` | pause for seconds or microseconds |
| `PDO` / `mysqli` (MySQL) | `Features\Mysql\Connection` | queries, transactions, SELECT streaming; a connection pool in Go |
| `PDO` (PostgreSQL) | `Features\Pgsql\Connection` | the same SQL feature on the pgx driver |
| `mongodb/mongodb`, `ext-mongodb` | `Features\Mongodb\Connection\*` | CRUD, aggregation, cursors (BSON types stay native `ext-mongodb`) |
| `curl`, `file_get_contents`, Guzzle | `Features\HttpClient\HttpClient` (PSR-18) | response streaming, download straight to a file on the Go side |
| `fsockopen`, `stream_socket_client` | `Features\SocketClient\SocketClient` | TCP with length-prefix framing |
| a WS client library | `Features\WsClient\WsClient` | text/binary messages |

Long-lived servers:

| Native PHP | SConcur | What changes |
| --- | --- | --- |
| PHP-FPM, RoadRunner, Workerman | `Features\HttpServer\HttpServer` (PSR-7) | HTTP server |
| `stream_socket_server` | `Features\SocketServer\SocketServer` | TCP server |
| Ratchet, Workerman (WS) | `Features\WsServer\WsServer` | WebSocket server |

## How it works

In short: `WaitGroup` wraps each closure in a `Fiber`. When an async feature is
called, the coroutine suspends and the task goes to Go and runs in a separate
goroutine. A single process-wide `Scheduler` waits on the extension
(`waitAny`), gets the first ready result of any flow, and resumes the right
coroutine by `taskKey`. Results arrive in task-completion order, not in `add()`
order.

The number of concurrently live coroutines in a group is unlimited by default.
If you need backpressure (memory, a DB connection pool), set a limit:
`WaitGroup::create(maxConcurrency: N)` — excess `add()` calls queue and start as
slots free up.

A detailed walkthrough — with the "PHP Fiber ↔ Go goroutine" diagrams, the
layers, and the task lifecycle — is in
[docs/architecture.md](docs/architecture.md).

## Why Go specifically

- A convenient concurrency model: goroutines and channels give cheap
  parallelism. One task — one goroutine, results collected over a channel. No
  external event loop, thread pool, or callback hell needed.
- Easy to add features: to plug in a new I/O operation you just write an ordinary
  synchronous Go handler, and the runtime runs it concurrently on its own.
- A mature ecosystem: Go drivers and libraries are reused directly.

### Why this approach helps with new features

Adding an I/O feature here is cheaper than in the classic PHP-async stacks
(Swoole, ReactPHP, AMPHP):

- One API for sync and async, no function coloring. The same call
  (`$collection->insertOne(...)`, `$client->sendRequest(...)`) works both inside
  a `WaitGroup` (concurrent) and outside it (an ordinary blocking call).
  Concurrency is chosen by the caller — by wrapping code in a `WaitGroup` — not by
  the feature author. There is no separate async variant of every method to
  maintain.
- No searching for or waiting on async drivers/extensions. Concurrency lives in
  Go, so a mature synchronous Go driver is reused as-is (mongo-driver, pgx,
  go-sql-driver, `net/http`, coder/websocket) and runs in parallel right away.
- A feature is ordinary synchronous Go code. The handler is written as plain
  blocking Go — no promises, callbacks, or event-loop reasoning. The runtime runs
  it on a goroutine and streams the result back. Adding a feature means "write a
  normal Go function".
- A minimal, stable C glue interface. The PHP↔Go boundary is a handful of generic
  cgo functions (`push`, `wait`, `waitAny`, `next`, `stopFlow`, plus the
  `version`/`destroy` lifecycle). Every feature rides that same transport: a
  feature is not a new C symbol but data — a new `MethodEnum`/`CommandEnum`, a
  MessagePack payload DTO, and a Go handler. The fragile C ABI stays frozen;
  adding a feature never touches it, so the export set does not grow and there is
  no per-operation cgo work. (A new long-lived server adds at most one control
  function such as `httpStopAccepting` for graceful drain.)
- One transport and one streaming model for everything. The wire format is
  uniform (MessagePack DTOs), and the streaming states (`next()`) are reused:
  MongoDB cursors, HTTP bodies, socket frames, WS messages all travel over the
  same mechanism with backpressure. A new streaming feature does not reinvent the
  protocol or the backpressure.
- A feature gets the whole runtime for free. The handler plugs into the shared
  `Scheduler` automatically: concurrency, nested coroutines, context
  cancellation, deadline propagation, and graceful shutdown with unwinding — none
  of the lifecycle has to be reimplemented inside the feature.
- Standard PHP contracts, framework-neutral. Client/server features expose PSR
  interfaces (PSR-7/17 for the HTTP server, PSR-18 for the HTTP client), the
  factories are injected, and the coroutine context is framework-agnostic, so a
  feature drops into any application without adapters.

## Use and limitations

- CLI only (the `cli` SAPI) — this is about the SAPI, not about "no web". The
  library targets long-lived CLI processes: workers, daemons, console commands,
  and the HTTP, WebSocket and socket servers themselves, which are ordinary PHP
  scripts started from the console that listen on a port on their own (no FPM in
  the chain; the model of Swoole / ReactPHP). It also drops into any long-lived
  process you already run — including a RoadRunner worker: load the extension in
  the worker and use `WaitGroup` in your request-handling code. It cannot be used
  with PHP-FPM or mod_php: the extension holds the Go runtime and goroutines at
  the process level, which contradicts the FPM model (a short request-response,
  shared pool processes).
- No `pcntl_fork` after the extension is loaded. The Go runtime and its
  goroutines do not survive `fork`: the child process gets a broken runtime
  (hangs, crashes). If you need a worker pool — fork before the first call into
  the extension, or launch separate processes (`exec`) and initialize the
  extension in the child.
- NTS (non-thread-safe) only. A ZTS build of PHP is not supported.
- Linux only. The extension and the library rely on Linux specifics (core-count
  detection, signals/`posix`, `SO_REUSEPORT`, the master's `flock`, and so on).
  Other operating systems are not supported.
- `exit()`/`die()` with active tasks is safe but loses their results. The
  shutdown handler unwinds unfinished coroutines (finally blocks run,
  transactions roll back, cursors and flows are released), after which the
  process exits normally. The results of unfinished tasks are lost in the
  process, so it is better to run them to completion or stop them explicitly
  (`WaitGroup::stop()`).
- Concurrent mode is optional. Any feature that goes into the Go side
  (`Sleeper`, `MongoDB`, and so on) can also be called outside a `WaitGroup` — as
  an ordinary synchronous call. Outside a Fiber, `FeatureExecutor` detects the
  non-async context and simply waits for the result (`Extension::wait`),
  returning it immediately. Handy when you do not need concurrency but want to
  keep a single API.

```php
// synchronous, without WaitGroup — returns the result immediately
$collection->insertOne(['name' => 'example']);
```

## Tested versions

The exact environment versions (Docker images and dependencies) the project is
built and tested against in CI:

| Component | Version |
| --- | --- |
| PHP | 8.4.15 (NTS, cli) |
| Go (extension build) | 1.26.1 |
| MongoDB (server) | 8.0.5 |
| ext-mongodb (PHP extension) | 1.21.5 |
| mongodb/mongodb (composer package) | 1.21.3 |
| ext-msgpack | 3.0.1 |
| MySQL (server) | 8.4 |
| go-sql-driver/mysql | 1.8.1 |
| PostgreSQL (server) | 16 |
| jackc/pgx/v5 | 5.7.2 |
| go.mongodb.org/mongo-driver/v2 | 2.6.0 |

## Documentation

- [Console commands](docs/cli.md) — `bin/sconcur-load` (download the prebuilt
  extension of the required version), `bin/sconcur-status` (check the install and
  version, with `--json`), `bin/sconcur-server` (worker master, brief with a
  link).
- [Architecture](docs/architecture.md) — internals: the Fiber ↔ goroutine link,
  the scheduler, the layers, the task lifecycle.
- [MongoDB](docs/mongodb.md) — collection operations (CRUD, aggregation, indexes,
  bulkWrite), streaming cursors, results, BSON types, concurrency, timeouts, and
  internals.
- [HTTP server](docs/http-server.md) — a long-lived daemon, a request in a
  coroutine: quick start, params, streaming, graceful shutdown, internals, and
  how it differs from typical servers.
- [Socket server (TCP)](docs/socket-server.md) — a long-lived TCP server with
  length-prefix framing, a "message → response" model: quick start, the handler,
  params, concurrency, graceful shutdown / `SO_REUSEPORT`, limitations.
- [Worker master](docs/worker-master.md) — a supervisor for a pool of worker
  processes (CLI `bin/sconcur-server` `start`/`status`/`reload`/`stop`): scaling
  across cores via `SO_REUSEPORT`, restarting crashed workers and ones that
  exhausted `maxRequests`, graceful shutdown, the log and the state file, a single
  instance, self-termination of orphaned workers.
- [Server statistics](docs/admin-stats.md) — workers push snapshots to the master
  over a unix socket, and the master serves the pool aggregate on its own port
  across the `SO_REUSEPORT` pool (HTTP or socket): the `GET /api/stats` endpoint
  (metrics/JSON/HTML), a live panel, SSE, a Bearer token.
- [HTTP client](docs/http-client.md) — an async PSR-18 client with response
  streaming: quick start, fan-out concurrency, params/timeouts, PSR-18 error
  handling, internals.
- [Socket client (TCP)](docs/socket-client.md) — an async TCP client with
  length-prefix framing (a mirror of the socket server): `connect()` →
  `Connection` (read/write/close), fan-out concurrency, params/timeouts, error
  handling, internals.
- [WebSocket server](docs/websocket-server.md) — a long-lived WS server (a hybrid
  of an HTTP-Upgrade listener and the socket server's push model): text/binary
  messages, `Connection` read/write/close, keepalive ping, params, graceful
  shutdown / `SO_REUSEPORT`, limitations.
- [WebSocket client](docs/websocket-client.md) — an async WS client (a mirror of
  the WS server): `connect()` → `Connection` (read/write/close, text/binary),
  fan-out concurrency, params/timeouts, error handling, internals.
- [MySQL (the universal SQL feature)](docs/mysql.md) — queries with bindings,
  SELECT streaming, transactions; the connection pool and internals.
- [PostgreSQL](docs/pgsql.md) — the second driver of the same SQL feature; PG
  specifics (`$1` placeholders, `RETURNING`, `BOOLEAN`).
- [How to add a new top-level feature](docs/adding-a-feature.md) — step by step
  (with and without streaming), with the mandatory requirements: context
  cancellation and passing the execution deadline.
- [How to add a new server](docs/adding-a-server.md) — a long-lived network
  server (like `HttpServer`): the Serve/Respond pattern, the serve loop, graceful
  shutdown and `SO_REUSEPORT`, integration with the worker master.
- [Load testing](docs/load-testing.md) — server behavior under load with all I/O
  features at once (the `/all` route + `bench-http-load-stats`): memory/CPU
  results and conclusions.
- [Feature benchmarks](docs/benchmarks.md) — per-feature measurements
  (native/sync/async): the cost of the PHP↔Go boundary on in-memory DBs and the
  concurrent fan-out win, with metric tables.

## Build
```shell
rm -f build/sconcur.so build/sconcur.h && \
  CGO_CFLAGS=$(php-config --includes) \
  go build -buildmode=c-shared -o build/sconcur.so .
```
## echo test
```shell
php -d extension=./build/sconcur.so -r "echo \SConcur\Extension\ping('hello') . PHP_EOL;"
```
## Roadmap

A short list of development directions.

- Auto-recovery of stuck workers — a master watchdog by heartbeat: `SIGKILL` and
  respawn a worker whose PHP thread has hung (a native block/CPU loop).
- Split the core and the features into separate packages — the core on its own,
  features as plugins on top.
- Stopping a single coroutine from anywhere (not just the whole flow).
- Optimize synchronous-mode execution — a call made outside a coroutine goes to
  Go directly, bypassing the scheduler and the Fiber machinery, to cut the
  overhead of the sync path.
