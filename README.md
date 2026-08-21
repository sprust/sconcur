English | [Русский](README.ru.md)

# SConcur

> ⚠️ Experimental project, not for production. Yet another attempt to make
> PHP asynchronous, but without a C extension — with one written in Go.

A concurrency library for PHP on top of a custom Go extension. The PHP side
(a Fiber) suspends while the Go extension runs the task (MongoDB operations,
sleep, and so on) concurrently in goroutines. PHP and Go exchange data over
MessagePack.

## Numbers against RoadRunner and Swoole

The same demo application on the same machine: `wrk` 4 threads / 256 connections
/ 20 s, database data on disk. The worker count per server is given in the row:
the first two endpoints were measured at 12, while `/db` and `/db-rw` come from
the worker-count ladder, at its 8-worker rung (where SConcur peaks and the load
generator does not yet share cores with the servers):

| Request | SConcur | RoadRunner | Swoole |
| --- | ---: | ---: | ---: |
| empty response (12 workers) | ≈133 500 rps, p50 1.8 ms | ≈46 600 rps, p50 5.4 ms | ≈353 000 rps, p50 0.4 ms |
| 6 DB operations: MongoDB + MySQL + PostgreSQL (12 workers) | ≈3 010 rps, p50 76 ms | ≈448 rps, p50 573 ms | ≈3 030 rps, p50 83 ms |
| point SELECT by id, `/db` (8 workers) | 38 617 rps, p50 6.2 ms | 23 665 rps, p50 10.6 ms | 123 359 rps, p50 1.9 ms |
| INSERT + COUNT(*) + SELECT, `/db-rw` (8 workers) | 2 529 rps, p50 89.7 ms | 425 rps, p50 606 ms | 2 654 rps, p50 87 ms |

Swoole is faster on the cheap paths, but its concurrency rests on hooks into the
existing PHP drivers: whatever the hooks do not cover blocks the whole worker (as
`ext-mongodb` does). In SConcur a feature is ordinary blocking Go code on top of a
mature Go driver, which makes new features easier to add and to maintain.

Where SConcur does not win — single cheap queries, megabyte payloads, CPU-bound
handlers — is listed just as plainly in
["Is SConcur for you?"](docs/positioning.md#is-sconcur-for-you), next to the
[feature benchmarks](docs/benchmarks.md) and the
[behaviour under load](docs/load-testing.md).

## Contents

- [Numbers against RoadRunner and Swoole](#numbers-against-roadrunner-and-swoole)
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

Output: `done in 1 s` — two one-second operations ran in parallel.

## What it replaces

Operations and clients — wrapped in a coroutine (`$waitGroup->add()` +
`$waitGroup->wait*()`), they run concurrently instead of blocking the process:

| Native PHP | SConcur | What changes |
| --- | --- | --- |
| `sleep()`, `usleep()` | `Sleeper::sleep()`, `Sleeper::usleep()` | pause for seconds or microseconds |
| `PDO` / `mysqli` (MySQL) | `Features\Mysql\Connection` | queries, transactions, SELECT streaming; a connection pool in Go |
| `PDO` (PostgreSQL) | `Features\Pgsql\Connection` | the same SQL feature on the pgx driver |
| `mongodb/mongodb`, `ext-mongodb` | `Features\Mongodb\Connection\*` | CRUD, aggregation, cursors; BSON values are `SConcur\Bson\*` |
| `curl`, `file_get_contents`, Guzzle | `Features\HttpClient\HttpClient` (PSR-18) | response streaming, download straight to a file on the Go side |
| `fsockopen`, `stream_socket_client` | `Features\SocketClient\SocketClient` | TCP with length-prefix framing |
| a WS client library | `Features\WsClient\WsClient` | text/binary messages |

Long-lived servers:

| Native PHP | SConcur | What changes |
| --- | --- | --- |
| PHP-FPM, RoadRunner, Swoole, Workerman | `Features\HttpServer\HttpServer` (PSR-7) | HTTP server |
| `stream_socket_server` | `Features\SocketServer\SocketServer` | TCP server |
| Ratchet, Workerman (WS) | `Features\WsServer\WsServer` | WebSocket server |

## How it works

`WaitGroup` wraps each closure in a `Fiber`. When an async feature is called,
the coroutine suspends and the task goes to Go, into its own goroutine. Every
`WaitGroup` owns one flow — the group of tasks that belong to it on the Go side.
A single process-wide `Scheduler` waits on the extension (`waitAnyBatch`): it
blocks until the first result of any flow is ready, picks up the results that
are already ready together with it in one crossing of the boundary, and resumes
the matching coroutines by `taskKey`. Results arrive in task-completion order,
not in `add()` order.

The number of concurrently live coroutines in a group is unlimited by default.
To bound memory or a DB connection pool, set a limit:
`WaitGroup::create(maxConcurrency: N)` — excess `add()` calls queue and start as
slots free up.

Details — the "PHP Fiber ↔ Go goroutine" diagrams, the layers and the task
lifecycle — in [docs/architecture.md](docs/architecture.md).

## Why Go specifically

The whole concurrency model reduces to one primitive: a channel. Every task runs
in its own goroutine, and the results of all goroutines of all flows land in one
shared buffered channel. PHP runs no event loop and polls nothing — the single
process-wide `Scheduler` blocks reading a message from that channel
(`waitAnyBatch`) and wakes on the first ready result, taking the results that
are already ready with it in the same crossing. Which task finished first is
decided by the channel; PHP only resumes the matching coroutine by `taskKey`.

Everything else follows from that:

- A feature is ordinary synchronous Go code. You write a blocking handler; the
  runtime runs it on a goroutine and puts the result into the channel. No
  promises, no callbacks, no event-loop reasoning.
- Mature drivers are reused as-is — mongo-driver, pgx, go-sql-driver, `net/http`,
  coder/websocket — and run concurrently right away. No waiting for async drivers
  or extensions.
- The C glue is frozen: `push`, `wait`, `waitAny`/`waitAnyBatch`, `next`,
  `stopFlow` plus the `version`/`destroy` lifecycle. A new feature is data (a
  `MethodEnum` value, a MessagePack payload DTO, a Go handler), not a new C
  symbol, so the export set never grows. A new long-lived server adds at most
  one control function, like `httpStopAccepting`, which stops accepting new
  connections while the ones in progress are finished.
- One transport and one streaming model for everything: MessagePack DTOs plus
  the streaming states behind `next()` — MongoDB cursors, HTTP bodies, socket
  frames, WS messages all travel the same mechanism, and the sender waits when
  the reader falls behind.
- One API for sync and async: no separate "async" and "sync" variants of the
  same function. The same call works inside a `WaitGroup` (concurrent) and
  outside it (an ordinary blocking call); concurrency is chosen by the caller,
  not by the feature author.
- A feature gets the runtime for free: nested coroutines, context cancellation,
  deadline propagation, graceful shutdown with unwinding.
- Client and server features expose PSR interfaces (PSR-7/17, PSR-18) with
  injected factories, so they drop into any application without adapters.

## Use and limitations

- CLI only (the `cli` SAPI) — about the SAPI, not about "no web". The target is
  long-lived CLI processes: workers, daemons, console commands, and the HTTP,
  WebSocket and socket servers themselves, which are ordinary PHP scripts that
  listen on a port on their own (the Swoole / ReactPHP model). It also drops into
  a long-lived process you already run, including a RoadRunner worker. PHP-FPM
  and mod_php are impossible: the extension holds the Go runtime at process
  level, which contradicts the FPM model.
- No `pcntl_fork` after the extension is loaded. The Go runtime does not survive
  a `fork` (the child hangs or crashes). Fork before the first call into the
  extension, or launch separate processes (`exec`).
- NTS (non-thread-safe) only; a ZTS build is not supported.
- Linux only — core-count detection, signals/`posix`, `SO_REUSEPORT`, the
  master's `flock`.
- `exit()`/`die()` with active tasks is safe but loses their results. The
  shutdown handler unwinds unfinished coroutines (finally blocks run,
  transactions roll back, cursors and flows are released), then the process exits
  normally. Better to run tasks to completion or stop them explicitly
  (`WaitGroup::stop()`).
- Concurrent mode is optional. Any feature can be called outside a `WaitGroup` as
  an ordinary synchronous call: outside a Fiber `FeatureExecutor` detects the
  non-async context and simply waits for the result (`Extension::wait`).

```php
// synchronous, without WaitGroup — returns the result immediately
$collection->insertOne(['name' => 'example']);
```

## Tested versions

The environment the project is built and tested against in CI:

| Component | Version |
| --- | --- |
| PHP | 8.4.15 (NTS, cli) |
| Go (extension build) | 1.26.1 |
| MongoDB (server) | 8.0.5 |
| ext-mongodb (PHP extension, tests and benchmarks only) | 1.21.5 |
| mongodb/mongodb (composer package, tests and benchmarks only) | 1.21.3 |
| ext-msgpack | 3.0.1 |
| MySQL (server) | 8.4 |
| go-sql-driver/mysql | 1.8.1 |
| PostgreSQL (server) | 16 |
| jackc/pgx/v5 | 5.7.2 |
| go.mongodb.org/mongo-driver/v2 | 2.6.0 |

## Documentation

- [Console commands](docs/cli.md) — `sconcur-load`, `sconcur-status`,
  `sconcur-server`.
- [Architecture](docs/architecture.md) — Fiber ↔ goroutine, the scheduler, the
  layers, the task lifecycle.
- [Coroutine switching](docs/coroutine-switching.md) — `Scheduler::switch()` and
  the servers' automatic preemption for CPU-bound code.
- [Coroutine context](docs/coroutine-context.md) — per-coroutine key-value store.
- [MongoDB](docs/mongodb.md) — collection operations, cursors, BSON types.
- [MySQL](docs/mysql.md) — the universal SQL feature: bindings, streaming,
  transactions, the pool.
- [PostgreSQL](docs/pgsql.md) — the same feature's second driver; PG specifics.
- [HTTP server](docs/http-server.md) — PSR-7 daemon, a request per coroutine.
- [Socket server (TCP)](docs/socket-server.md) — length-prefix framing, push
  model.
- [WebSocket server](docs/websocket-server.md) — HTTP-Upgrade listener + push
  model.
- [HTTP client](docs/http-client.md) — async PSR-18 client with response
  streaming.
- [Socket client (TCP)](docs/socket-client.md) — the socket server's dial-side
  mirror.
- [WebSocket client](docs/websocket-client.md) — the WS server's dial-side
  mirror.
- [Worker master](docs/worker-master.md) — a supervisor for a pool of workers
  (`bin/sconcur-server`).
- [Server statistics](docs/admin-stats.md) — `GET /api/stats`, live panel, SSE,
  Prometheus.
- [How to add a new feature](docs/adding-a-feature.md) — step by step, with and
  without streaming.
- [How to add a new server](docs/adding-a-server.md) — the Serve/Respond pattern
  and the serve loop.
- [Objects over MessagePack](docs/msgpack-objects.md) — how a PHP object crosses
  to Go and back, and how to add a type.
- [Feature benchmarks](docs/benchmarks.md) — per-feature measurements
  (native/sync/async).
- [Load testing](docs/load-testing.md) — server behaviour under load with all I/O
  features at once.
- [Positioning](docs/positioning.md) — SConcur vs php-fpm, RoadRunner and Swoole.

## Build
```shell
cd ext && \
  rm -f build/sconcur.so build/sconcur.h && \
  CGO_CFLAGS=$(php-config --includes) \
  go build -buildmode=c-shared -o build/sconcur.so .
```
## echo test
```shell
php -d extension=./ext/build/sconcur.so -r "echo \SConcur\Extension\ping('hello') . PHP_EOL;"
```
## Roadmap

- The `Std` feature — SConcur equivalents of standard PHP functions that block
  the worker or are CPU-bound non-preemptible monoliths (sleep, json, hash, gzip,
  password hashing, file I/O), executed in Go; absorbs `Sleeper`.
- The `Queue` feature — deferred background jobs: a job is published to a broker
  and picked up by workers in coroutines, with the broker client on the Go side.
- Auto-recovery of stuck workers — a master watchdog by heartbeat: `SIGKILL` and
  respawn a worker whose PHP thread has hung.
- Split the core and the features into separate packages.
- Stopping a single coroutine from anywhere, not just the whole flow.
- Optimize the synchronous path — a call outside a coroutine goes to Go
  directly, bypassing the scheduler and the Fiber machinery.
- Explore a cross-process concurrency mode, so concurrent operations can use
  several processes (and cores) instead of the goroutines of one process.
