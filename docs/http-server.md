English | [Русский](http-server.ru.md)

# HTTP server

A long-lived PHP daemon that accepts HTTP requests and handles each one in its own
coroutine (Fiber), concurrently with the rest. The network I/O lives in the
extension; PHP stays a thin orchestration layer. Implementation:
`src/Features/HttpServer/` (PHP) and `ext/src/features/httpserver/` (Rust).

> ⚠️ Read [What's missing compared to typical servers](#whats-missing-compared-to-typical-servers)
> first — the model is cooperative and single-threaded, which constrains handler code.

## Table of contents

- [Model](#model)
- [Quick start](#quick-start)
- [Server parameters](#server-parameters)
- [Request and response (PSR-7)](#request-and-response-psr-7)
- [Response streaming (chunked / SSE)](#response-streaming-chunked--sse)
- [Files](#files)
- [Error handling](#error-handling)
- [Logs](#logs)
- [Concurrency and limits](#concurrency-and-limits)
- [Scaling across cores (SO_REUSEPORT)](#scaling-across-cores-so_reuseport)
  - [One socket for the whole pool](#one-socket-for-the-whole-pool)
  - [A proxy in front of the pool](#a-proxy-in-front-of-the-pool)
- [OPcache and JIT](#opcache-and-jit)
- [Stopping after N requests](#stopping-after-n-requests)
- [Graceful shutdown](#graceful-shutdown)
- [Internals](#internals)
- [What's missing compared to typical servers](#whats-missing-compared-to-typical-servers)
- [Running in Docker and testing](#running-in-docker-and-testing)

## Model

The network stack (accepting connections, HTTP parsing, keep-alive, the handler
timeout, writing the response) runs inside the extension, on hyper. Each accepted
request becomes an ordinary result and reaches PHP through the same shared result
channel as every other task, so the server reuses the existing `Scheduler` and
introduces no second event loop.

The base model is spawn-on-request: each request event creates a new handler
coroutine, and ordinary async SConcur calls inside it run concurrently with the
handling of other requests.

The worker is a long-lived process: everything built before `serve()` — framework
bootstrap, DI container, config, connections — is reused by every request.
State survives between requests, so keep
request-scoped state in the [coroutine context](coroutine-context.md) or local to
the handler, and let `maxRequests` recycle the process.

```mermaid
flowchart TB
    Client["client"]
    EXT["Extension (hyper)"]
    Sched["PHP Scheduler::serve()"]
    Handler["spawn(coroutine) — your handler"]

    Client <-->|"request / response"| EXT
    EXT -->|"request event (waitAnyTimeoutBatch)"| Sched
    Sched -->|"spawn"| Handler
    Handler -->|"execNoResult(RespondPayload::full) — HttpRespond"| EXT
```

The handler contract is PSR-7: in `ServerRequestInterface`, out
`ResponseInterface`. The library depends on no concrete PSR-7 implementation — the
objects come from the PSR-17 factory you pass to the constructor, mirroring the
[HTTP client (PSR-18)](http-client.md).

## Quick start

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SConcur\Features\HttpServer\HttpServer;

require __DIR__ . '/vendor/autoload.php';

$factory = new Psr17Factory(); // one factory plays both required PSR-17 roles

$server = new HttpServer(
    serverRequestFactory: $factory,
    responseFactory:      $factory,
    address:              '0.0.0.0:8080',
);

$server->serve(static function (ServerRequestInterface $request) use ($factory): ResponseInterface {
    return match ($request->getUri()->getPath()) {
        '/'      => $factory->createResponse(200)->withBody($factory->createStream('ok')),
        '/ping'  => $factory->createResponse(200)->withBody($factory->createStream('pong')),
        default  => $factory->createResponse(404)->withBody($factory->createStream('not found')),
    };
});
```

Any PSR-7/PSR-17 implementation works (`nyholm/psr7`, `guzzlehttp/psr7`,
`laminas/laminas-diactoros`, …). The constructor needs
`ServerRequestFactoryInterface` (to build the request) and `ResponseFactoryInterface`
(for the fallback `413`/`500` responses); `Psr17Factory` implements both.

```shell
php -d extension=./ext/build/sconcur.so server.php
```

`serve()` blocks until `SIGTERM`/`SIGINT` or until its flow (the group of tasks the
server runs in) is stopped. The handler runs in its own coroutine, so async features
inside it (`Sleeper::usleep()`, MongoDB, SQL, the HTTP client) do not block other
requests.

## Server parameters

The `HttpServer` constructor (`src/Features/HttpServer/HttpServer.php`). All
timeouts are in milliseconds.

| Parameter | Default | Purpose |
|---|---|---|
| `serverRequestFactory` | — (required) | PSR-17 `ServerRequestFactoryInterface` — builds the handler's request. |
| `responseFactory` | — (required) | PSR-17 `ResponseFactoryInterface` — builds the fallback `413`/`500` responses. |
| `address` | `0.0.0.0:7832` | Listen address, e.g. `0.0.0.0:8080`. |
| `readHeaderTimeoutMs` | `10000` | Accepted and not applied — see [Connection timeouts](#connection-timeouts). |
| `readTimeoutMs` | `30000` | Accepted and not applied. |
| `writeTimeoutMs` | `30000` | Accepted and not applied. |
| `idleTimeoutMs` | `60000` | Accepted and not applied. |
| `shutdownTimeoutMs` | `10000` | How long a stopping server waits for its connections to finish their last responses and close, see [graceful shutdown](#graceful-shutdown). `0` — as long as they take. |
| `maxRequestBody` | `10485760` (10 MiB) | Request body limit; exceeding it → `413`. |
| `maxConcurrency` | `0` (no limit) | Requests handled at once, see [limits](#concurrency-and-limits). |
| `handlerTimeoutMs` | `60000` | Max total handling time including streaming, otherwise `504`/abort. `0` — the PHP handler is not unwound; the extension still answers `504` after 60 s. |
| `maxRequests` | `0` (no limit) | Stop the server after this many requests, see [below](#stopping-after-n-requests). |
| `reusePort` | `false` | `SO_REUSEPORT` — several processes on one port. |
| `listenFd` | `null` | A listening socket bound elsewhere to accept on instead of binding `address` (`address` and `reusePort` are then not used) — systemd socket activation, say; see [one socket for the whole pool](#one-socket-for-the-whole-pool). |
| `onError` | `null` | `Closure(Throwable, ServerRequestInterface): ?ResponseInterface`. |
| `masterPid` | `null` | Stop gracefully once this pid is no longer the parent. `null` — the master that forked this worker (`WorkerContext`, see the [worker master](worker-master.md#self-termination-of-orphaned-workers)), or no check outside one. |
| `telemetrySocket` | `''` (off) | Unix socket for stats snapshots ([stats](admin-stats.md)). Empty — `SCONCUR_TELEMETRY_SOCKET` from the environment, which the master sets for its workers. |
| `serverName` | `'sconcur-server'` | Worker name in the stats snapshots. Left at the default — `SCONCUR_SERVER_NAME` from the environment, which the master sets as `<group>:<slot>`. |
| `telemetryIntervalMs` | `0` | Snapshot cadence; `0` — `SCONCUR_TELEMETRY_INTERVAL_MS` from the environment, or the default of the component that sends them (1000 ms). |
| `preemptionQuantumMs` | `0` | Automatic-preemption quantum while serving; `0` — off. Read [process-wide state](coroutine-switching.md#process-wide-state) before turning it on. |

### Building the server from argv

`HttpServer::fromArgs()` assembles the server from `argv`: every `--name=value` is
matched to the constructor's scalar parameter of the same name (type-checked), an
unknown flag throws. PSR-17 factories cannot travel on argv, so they stay
arguments. It is for a script that runs one server on its own — a
[worker master](worker-master.md)'s role builds its server with the constructor:

```php
$server = HttpServer::fromArgs(
    argv:                 $_SERVER['argv'],
    serverRequestFactory: $factory,
    responseFactory:      $factory,
);
```

### Connection timeouts

The four connection deadlines cross the boundary and are not acted on: the extension
bounds a request by `handlerTimeoutMs` and nothing else. A slow client therefore holds
its connection for as long as it likes while the server runs. A stopping server waits
for its connections up to `shutdownTimeoutMs` (see [graceful shutdown](#graceful-shutdown))
and then leaves; a handler still running then is unwound. Terminate slow clients in the
reverse proxy in front.

## Request and response (PSR-7)

The handler receives a `ServerRequestInterface` assembled from the event by your
factory:

| What you need | PSR-7 method |
|---|---|
| Method | `$request->getMethod()` |
| Path | `$request->getUri()->getPath()` — percent-decoded by the extension (`%2F` arrives as `/`), without query |
| Raw query string | `$request->getUri()->getQuery()` |
| Parsed query | `$request->getQueryParams()` — filled via `parse_str()` |
| All headers | `$request->getHeaders()` — `array<string, array<int, string>>` |
| One header | `$request->getHeaderLine('X-Echo')` / `getHeader()` |
| Protocol version | `$request->getProtocolVersion()` — `"1.1"`, without the `HTTP/` prefix |
| Client address, etc. | `$request->getServerParams()` — `REMOTE_ADDR`, `REMOTE_PORT`, `REQUEST_METHOD`, `REQUEST_URI`, `QUERY_STRING`, `SERVER_PROTOCOL`, `HTTP_HOST` |
| Body | `$request->getBody()` — `StreamInterface`, see below |

Cookies, the parsed body and uploaded files (`getCookieParams()`,
`getParsedBody()`, `getUploadedFiles()`) are not populated — by PSR-7 convention
that is your middleware's job.

### The request is lazy about its headers

The object handed to the handler is `Dto/LazyHeadersRequest`, a
`ServerRequestInterface` wrapping the one your factory built. The headers and the
query string are held aside and applied on first use, because setting seven
headers costs 4.17 us of the 7.75 one decode takes and parsing a query string
another 1.9, while a router reads the method and the path — 0.12 us worth
(`make bench-request`). The deferral survives `withAttribute` and the other
withers, so filing route parameters does not force it.

Nothing about the PSR-7 surface changes: every method answers what the eager
request would have answered, and `getHeaders()` returns the same array in the
same order. Two things do change, and only for code that looks past the
interface:

- a `instanceof Nyholm\Psr7\ServerRequest` (or any other concrete class) is
  false now, because the concrete object is one level in. Type against
  `ServerRequestInterface`, as PSR-7 intends;
- `$request->materialize()` returns the plain request with everything applied,
  for the rare caller that has to hand a concrete object to something else.

A handler that reads one header pays 0.36 us more than with headers applied up front.
That is the trade: the deferral is worth it unless more than 91% of requests read a
header.

The body is `Dto/RequestBodyStream` over `Dto/RequestBody`. The extension reads
the whole body before the handler runs and hands it over with the request, so
the body is held in memory in full, up to `maxRequestBody`. The stream is
one-shot and not rewindable (`isSeekable()` → `false`; `seek`/`rewind`/`write`
throw), so read it one way per request:

```php
// 1) Fully (small bodies — JSON, a form). Memoized.
$data = json_decode($request->getBody()->getContents(), true);

// 2) In pieces (the body is already in memory; this only avoids a second copy):
$body = $request->getBody();
while (($chunk = $body->read(8192)) !== '') { // '' at end of stream (PSR-7)
    hash_update($hash, $chunk);
}
```

- While the body is still arriving, the request has not reached PHP yet, so a
  slow uploader does not block other requests.
- `read($length)` slices the body that already arrived and never suspends.
- `getSize()` → `null`.
- A body over `maxRequestBody` never reaches the handler: the extension answers
  `413 Payload Too Large` itself, first on the declared `Content-Length` and
  again on the bytes that actually arrived, since a chunked body declares
  nothing.

The handler returns any `ResponseInterface`:

```php
return $factory->createResponse(200)
    ->withHeader('Content-Type', 'text/plain')            // a string or a list of strings
    ->withHeader('Set-Cookie', ['a=1; Path=/', 'b=2'])    // each value — its own header line
    ->withBody($factory->createStream('hello'));
```

- A body of known size goes out in a single write; `getSize() === null` means a
  stream, see below.
- Without `Content-Type` a non-empty one-shot body goes out as
  `text/plain; charset=utf-8`; a streamed response gets none.
- 204/304 responses have their body discarded.
- Returning a non-`ResponseInterface` is a contract error
  (`InvalidHandlerResponseException`): the client gets `500`, `onError` is called.

## Response streaming (chunked / SSE)

There is no separate stream DTO — PSR-7 covers it: return a response whose body is
a lazy `StreamInterface` of unknown size (`getSize()` → `null`). The framework then
reads it chunk by chunk (`read()`, 64 KiB at a time), sending each to the client and
waiting until the connection has taken it (chunked transfer, SSE). The reading happens in the request coroutine, so
your `read()` can suspend on async features and lazily produce the next chunk.

```php
use SConcur\Tests\Impl\HttpServer\GeneratorStream; // StreamInterface over a Generator

$chunks = (static function (): Generator {
    foreach (range(1, 5) as $i) {
        yield "data: event $i\n\n"; // one yield — one chunk sent to the client
        Sleeper::sleep(seconds: 1); // async work between chunks
    }
})();

return $factory->createResponse(200)
    ->withHeader('Content-Type', 'text/event-stream')
    ->withBody(new GeneratorStream($chunks));
```

- The client sets the pace: each chunk is acknowledged only after the connection has
  taken it, so a fast producer cannot outrun a slow client.
- No `Content-Length` — a header without length, then chunked transfer encoding.
- The status cannot change after the first chunk (headers are on the wire), so an
  exception while reading the body is not turned into a `500` — it is only
  reported to `onError`, after which the stream is closed cleanly.

## Files

An upload is written to disk in pieces from the body already in memory. A file
response built from `createStreamFromFile()` has a known size, so the whole file is
read into memory and sent in one write, with `Content-Length` set automatically. For
a file too large to hold in memory, return a stream of unknown size (see
[streaming](#response-streaming-chunked--sse)).

```php
// Upload: write the request body into a file.
$handle = fopen($target, 'wb');
$body   = $request->getBody();

while (($chunk = $body->read(8192)) !== '') {
    fwrite($handle, $chunk);
}

fclose($handle);

// Serve a file: the body is a file stream, length known.
$stream = $factory->createStreamFromFile($path, 'rb');

return $factory->createResponse(200)
    ->withHeader('Content-Type', 'image/png')      // image/* → the browser shows it inline
    ->withHeader('Content-Disposition', 'inline')  // attachment; filename="..." — to download
    ->withBody($stream);
```

Ready-made routes are in the demo server (`tests/servers/http/http-server.php`):
`POST /files/upload?name=`, `GET /files/download?name=`, `GET /image?name=`.

## Error handling

An exception in the handler, or a wrong return type, gives the client `500` — the
`serve()` loop does not crash. By default the error is swallowed; pass `onError`
to observe it or to return your own response:

```php
onError: static function (\Throwable $e, ServerRequestInterface $request) use ($factory): ?ResponseInterface {
    error_log((string) $e);

    return $factory->createResponse(500)->withBody($factory->createStream('oops'));
}
```

An `onError` that itself throws is safely swallowed — the client still gets `500`.

## Logs

Access log — one line per request to `STDOUT` (under a worker master, wherever its
[`accessLog`](worker-master.md#access-lines) says), including the answers the
extension gives on its own (`504` on handler timeout, `413` on body overflow, `503` to a
request that reaches a server whose stop has run past `shutdownTimeoutMs`). A request whose client disconnects before the answer leaves no line:

```
2026-06-14T17:36:26.123456 GET / 200 2.59ms
2026-06-14T17:36:26.456789 GET /msleep/30 200 34.77ms
```

The time is the moment the request was accepted; the last field is the time until the
response was handed to the connection — for a stream, until its head, not its whole
duration. The line is formatted by the same runtime task that writes the response, so
the log costs no crossing per request (that crossing is the most expensive part of a
tiny request — moving the log out of PHP nearly doubles the throughput of one core on
hello-world). Output is asynchronous: a background thread writes from a buffer,
flushed every ~100 ms; on overflow the extra lines are discarded and counted. The
method and path are escaped (control bytes, including `CR`/`LF` from a URL-encoded
path, become `\xNN`), so a request cannot forge a second log line.

Lifecycle lines are written by the PHP side and flushed immediately — one at
startup, one per shutdown step:

```
2026-06-28T12:00:00.000000 sconcur http server listening on 0.0.0.0:8080 pid=12345 version=X.Y.Z maxConcurrency=0 maxRequests=0 reusePort=0
2026-06-28T12:00:01.000000 sconcur http server shutdown: stop accepting (reason=signal), draining 2 in-flight
2026-06-28T12:00:01.050000 sconcur http server shutdown: drained all in-flight
2026-06-28T12:00:01.060000 sconcur http server shutdown: stopped
```

A stop that takes longer reports every 5 s what it is still waiting for.
`reason=signal` — `SIGTERM`/`SIGINT` or a lost master; `reason=limit` —
`maxRequests` reached, logged as `reason=limit, limit=N` with the number the worker
stopped at. Under a [worker master](worker-master.md) the worker's
`STDOUT` is captured and rewritten into the shared log; the access lines can go to a
file of the day each worker writes itself instead — see
[access lines](worker-master.md#access-lines).

## Concurrency and limits

The PHP part is single-threaded and cooperative: one `Scheduler` runs the wait
loop and resumes coroutines, and control passes to another coroutine only when the
current one suspends on an SConcur feature (`Fiber::suspend()`).

> **Handlers must be I/O-bound through SConcur features.** A native blocking call
> (`sleep()`, synchronous PDO/`curl`, reading a file) freezes the whole server.
> CPU-heavy PHP code freezes it too, unless preemption is on: then the server
> preempts it every `preemptionQuantumMs` (see
> [coroutine switching](coroutine-switching.md)), so it delays neighbours by at most
> the quantum — but a single monolithic internal call (a huge `preg_match`,
> `json_decode`) is still not interruptible.

`maxConcurrency` limits requests handled at once. It is a semaphore in the extension
acquired before the body is read, so it bounds runtime tasks, memory (bodies are
read only for requests that got a slot) and PHP coroutines at once. Excess
connections wait for a free slot, so the load throttles itself. `0` is a risk of
OOM under a flood of large bodies; set a limit on public servers.

It has a second use — the latency tail. CPU-heavy handlers share the single PHP
thread, so each one's latency grows with how many are being served at once, and
`maxConcurrency` is what bounds that number. On a profile of 90% empty requests
and 10% handlers worth ~43 ms of CPU, a limit of `4` takes p99 from 2.75 s down
to 620 ms, at the cost of p50 rising from 17 ms to 266 ms. The trade and both
settings are covered in
[coroutine switching](coroutine-switching.md#the-cost-under-load).

`handlerTimeoutMs` bounds the total handling time, streamed response included, and
it bounds two things at once.

The client is answered by the extension: the connection task waits for the
handler's first write with that deadline and answers `504` when it does not
arrive, or aborts a stream that already started. It fires independently of PHP,
so the client is answered even when the handler is stuck in a native call.

The PHP handler is unwound at the same deadline — the request coroutine is given it,
and past it a [coroutine timeout](coroutine-timeout.md) is thrown into the handler
where it stands. Its `finally` blocks run, its transaction rolls back, its
connections go back to their pools, and it stops producing a response nobody will
read. With preemption armed (`preemptionQuantumMs` above `0`) that reaches a handler
busy with pure computation as well.

What neither of them reaches is a handler already inside a native call: no PHP runs
there, so nothing can be delivered until it returns. Those are bounded by the
feature's own timeouts (a query timeout, an HTTP client deadline) and, at process
level, by a worker pool (`SO_REUSEPORT`) plus `maxRequests` recycling.

## Scaling across cores (SO_REUSEPORT)

One process effectively uses one core for the PHP logic, so all cores are loaded
by running several independent processes. Normally only one process can `bind()` a
given `ip:port`; `SO_REUSEPORT` (Linux, kernel 3.9+) lets several processes
`bind()`+`listen()` on the same address, and the kernel load-balances incoming
connections between them — process-per-core without an external balancer, like
nginx workers.

```mermaid
flowchart TB
    Port[":8080 (SO_REUSEPORT) — the kernel spreads connections by the 4-tuple hash"]
    P1["process 1 — Scheduler"]
    P2["process 2 — Scheduler"]
    P3["process 3 — Scheduler"]
    P4["process 4 — Scheduler"]

    Port --> P1
    Port --> P2
    Port --> P3
    Port --> P4
```

Pass `reusePort: true` to every process on the shared port (inside the extension
it sets `SO_REUSEPORT` on the socket before the bind,
`ext/src/features/httpserver/listen.rs`). Run them as separate processes — a
supervisor (systemd, supervisord, docker `--scale`), the
[worker master](worker-master.md), or a plain loop.

```bash
# one process per core
for i in $(seq 1 "$(nproc)"); do
    php -d extension=./ext/build/sconcur.so server.php &
done
wait
```

Caveats:

- Processes are independent: no shared memory, each has its own runtime,
  scheduler and coroutines. Shared state (sessions, cache, counters) goes to
  external storage.
- Every process must set `reusePort: true` — if one did not and started first, the
  rest get `EADDRINUSE`.
- Balancing is by connections, not requests (4-tuple hash), so with keep-alive all
  requests of one connection land in the same process. Few long-lived connections
  distribute unevenly.
- Limits are per process: the total is value × number of processes.
- Graceful shutdown is per process — see below.
- `SO_REUSEPORT` lets another process with the same UID bind the port and
  intercept part of the connections; keep that in mind in a multi-tenant
  environment.

### One socket for the whole pool

Instead of binding `address`, a server can accept on a listening socket bound elsewhere:
`listenFd` takes its descriptor — the one systemd's socket activation hands over, say.
The server takes over a copy of the descriptor, so stopping it closes the copy and
leaves the socket to whoever else holds it, and a stopping server leaves what is queued
on the socket to them.

Several processes accepting on one socket all wake for every connection, and the first
to run takes what is queued: across 12 workers the busiest served 7–9 times as many
requests as the idlest. A pool of processes on one port is better served by
`SO_REUSEPORT`.

### A proxy in front of the pool

The kernel spreads connections, not requests. With many connections that evens
out: across 8 workers the request-count spread was 1.13–1.33× at 256
connections. With a few long-lived keep-alive connections it does not: at 32
connections the busiest worker served 2.8–5.1× the requests of the idlest,
because some workers held one connection and others six. The per-worker tails
stayed alike in both cases. One shared socket does not change that: at 32
connections across 12 workers it left 2.9–6.7× between them as well.

A proxy hands out requests rather than connections, which is what a pool fed by
a handful of keep-alive clients needs. With many connections it does not pay.
Empty endpoint, 8 workers on their own cores, nginx on four more, 256
connections:

| Setup | rps | p99 | CPU per request, nginx included |
| --- | ---: | ---: | ---: |
| `SO_REUSEPORT` | 200 211 | 4.0 ms | 40 µs |
| nginx round-robin | 167 035 | 7.2 ms | 52 µs |
| nginx `least_conn` | 194 281 | 4.8 ms | 44 µs |
| nginx `random two least_conn` | 186 825 | 5.0 ms | 46 µs |

If a proxy goes in front anyway, two conditions. Do not take the default
round-robin: it hands out blindly and is the worst of the three; take a
load-aware strategy. And the workers run with `reusePort: false` on separate
ports, listed in the upstream:

```nginx
upstream workers {
    random two least_conn;
    server 127.0.0.1:8081;
    server 127.0.0.1:8082;
    keepalive 64;
}

server {
    listen 8080;

    location / {
        proxy_pass http://workers;
        proxy_http_version 1.1;   # 1.0 by default — upstream keepalive would not work
        proxy_set_header Connection "";
    }
}
```

On heterogeneous load, where the heavy requests hit the pool's CPU, the proxy
does not help either: on the profile above with preemption off, p99 was 563 ms
behind `SO_REUSEPORT` and 568 ms behind nginx `random two least_conn`. The limit
there is capacity, not request placement.

## OPcache and JIT

A CLI process runs without OPcache by default (`opcache.enable_cli=0`), so a
worker interprets every opcode on every request. Enabling OPcache with the
tracing JIT is worth it for a long-lived worker:

```ini
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=128M
```

(the same three values work as `php -d` flags). Measured on the demo server: ~8%
less CPU per request on the empty endpoint, 4–9% on the DB endpoints — the JIT
compiles exactly the per-request orchestration code (scheduler loop, PSR-7
building) that dominates the PHP side. Applies equally to the socket and
WebSocket servers and to any long-lived SConcur process.

## Stopping after N requests

`maxRequests` makes the server gracefully stop itself and exit with code `0` after
serving the given number of requests — a guard against memory leaks in a
long-lived daemon. A supervisor (systemd, docker `restart: unless-stopped`) or the
[worker master](worker-master.md) brings up a replacement; together with
`SO_REUSEPORT` the rest of the pool keeps accepting traffic meanwhile.

```php
$server = new HttpServer(
    serverRequestFactory: $factory,
    responseFactory:      $factory,
    maxRequests:          10_000, // then graceful stop and exit
);
```

The limit is per process (the total budget until restart is `maxRequests` × the
number of workers). Dispatched requests are counted — the limit request itself
is not aborted, and a request that arrives on an open connection while the server
stops is still answered, past the limit.
Under the worker master each worker stops somewhere between `maxRequests` and half
again as many, so the pool does not restart all at once, and its replacement is started
before it finishes — see [restart policy and
`maxRequests`](worker-master.md#restart-policy-and-maxrequests).

## Graceful shutdown

On `SIGTERM`/`SIGINT` (or a lost master, or the `maxRequests` limit) the server stops
accepting and closes its listening socket, so with `SO_REUSEPORT` the kernel sends new
connections to its neighbours. Then:

1. The connections the kernel had already queued on the socket, and the server had not
   accepted yet, are taken and served rather than reset with the socket. (Not for a
   socket handed over as `listenFd`: whoever else holds it goes on taking from its
   queue.)
2. Every response from now on goes out with `Connection: close`, and the connection
   closes after it: the client sends its next request to a neighbour. A request already
   on its way on a keep-alive connection is answered, not dropped.
3. A connection still open a second after the stop is shut down: an idle one — a
   keep-alive one between requests, or a client that connected and never sent a
   request — closes at once, one in the middle of a request after its response.
4. The server leaves once every connection has finished its last response and closed —
   including the requests that arrived meanwhile — or once `shutdownTimeoutMs` has
   passed since it stopped accepting; a handler still running then is unwound.

Each step is logged, see [Logs](#logs). A connection that arrives between the last
accept from the queue and the close of the socket is still reset; Linux 5.14+ hands
those to a sibling `SO_REUSEPORT` socket when `net.ipv4.tcp_migrate_req` is `1` — an
administrator's setting, off by default.

Measured with a pool of 12 workers under a [master](worker-master.md) on the empty
endpoint, each worker leaving at its `maxRequests` and replaced, single 10 s runs of
`wrk`: with 256 keep-alive connections (`maxRequests` 50 000), 48 restarts cost 3 failed
requests, against 708 when idle connections were closed at once; with a connection per
request (`maxRequests` 10 000), about 60 restarts cost 6–7, against 23–29 when the queue
was left to be reset.

- Signal handlers are installed before the listener starts and restored on exit.
- The signals are taken through the extension: neither `ext-pcntl` nor
  `ext-posix` is needed.
- On an idle server shutdown fires within ~250 ms — the `serve()` loop polls
  `waitAnyTimeoutBatch` at that interval and notices the signal without traffic.

## Internals

```mermaid
sequenceDiagram
    participant PHP as PHP (HttpServer + Scheduler)
    participant EXT as Extension (httpserver)
    participant Client as client

    PHP->>EXT: push(ServePayload, HttpServe)
    Note over EXT: handle_serve — bind the listener + serve it with hyper
    Note over EXT: handle_serve's own body pumps the request stream
    Note over PHP: Scheduler::serve() — waitAnyTimeoutBatch(250ms) loop
    Client->>EXT: HTTP request
    Note over EXT: accept_loop / serve_request — acquire slot, read body, request event into the requests channel
    EXT-->>PHP: request event (batch, HasNext=true; the stream pumps itself)
    Note over PHP: spawn(coroutine) — handle($handler)
    PHP->>EXT: execNoResult(RespondPayload::full, HttpRespond)
    Note over EXT: respond_detached — hand the write command to the connection, which writes status+headers+body
    EXT->>Client: response
    Note over PHP: coroutine finished right after the push, the flow is cleaned up
```

PHP: `HttpServer::serve()` generates a `flowKey`, installs signal handlers,
pushes the listener task and starts `Scheduler::serve()` — the server loop over
`waitAnyTimeoutBatch()`, which dispatches request events (→ `spawn` a handler in
its own per-request flow), task results (→ resume by `taskKey`) and the
completion of the server flow, plus finishing the requests already accepted and
`stopFlow`. A spawned coroutine lives outside any `WaitGroup` and must handle
its own errors, which is what `HttpServer::handle` does by turning them into a
`500`.

Rust (`ext/src/features/httpserver/`): `mod.rs` serves both methods. `server.rs`
holds the registries `pending_requests` (`requestId →` the channel the
connection reads the handler's writes from) and `server_states` (`flowKey →`
server state, for `StopAccepting`). It is also the accept loop and the
per-request service over `hyper`, handling the concurrency semaphore, the
handler timeout, 503/504 and the graceful stop of the connections. `listen.rs` is the
TCP listener, `SO_REUSEPORT`, a socket handed over as `listenFd`, and taking what is
queued on the socket when the server stops.

The listener is a self-pumping stream: an extension-side runtime task publishes every
accepted request as the next batch result (`HasNext=true`), so PHP pays no
`next()` crossing per request. The throttling is layered — the shared results
buffer, the requests channel, and beyond that the connection task waits on the
send. Each
request is handled in its own flow (the group of that request's tasks), so a
handler's sub-tasks are isolated and stopping one request does not take down the
server.

The response is a sequence of write commands over `HttpRespond`: `full` (a
one-shot response), or `head` → `chunk`* → `end` for a stream. Streaming
commands are acknowledged only after they are applied — that is what keeps a
handler from outrunning the client. The one-shot `full` write is
fire-and-forget: the coroutine ends right after handing it over (a failure of
the final write is not observable to the handler), and the handover itself is
bounded by the connection-side guards (`abandoned`, handler timeout). A stopping
server still waits for the write: it leaves once its connections have closed. On the
streaming path, if the connection dropped or the timeout fired, the handler gets
an `abandoned` error and unwinds cleanly instead of hanging.

## What's missing compared to typical servers

The general limits of the library (FPM, `fork`, NTS only, `exit()` with active
tasks) are in the [README](../README.md#use-and-limitations); the table holds what
is specific to the server.

| What | Status | Comment |
|---|---|---|
| PHP-FPM | ⚠️ not for this | The library runs there, but a server listening on a port needs a long-lived process. |
| TLS / HTTPS | ❌ no | Plain TCP; terminate TLS in front (nginx/HAProxy). |
| HTTP/2, WebSocket | ❌ no | The server is built on HTTP/1.1 only. WebSocket is a [separate server](websocket-server.md). |
| Multi-core parallelism in one process | ❌ no | One process = one PHP thread; scale with [`SO_REUSEPORT`](#scaling-across-cores-so_reuseport). |
| CPU-bound handlers | ⚠️ limited | Neighbours' latency is bounded by [preemption](coroutine-switching.md); throughput comes from the per-core pool. |
| Synchronous I/O in a handler | ⚠️ dangerous | Native `sleep`/PDO/`curl`/files freeze the loop. |
| Router / middleware | ❌ no | A low-level PSR-7 contract; layer a PSR-15 stack on top yourself. |
| Streaming the request body | ❌ no | The extension reads the whole body, up to `maxRequestBody`, before the handler runs. |

What does work: keep-alive, the handler timeout, chunked/SSE streaming, multiple
values of one header, binary bodies, the concurrency limit, `413`/`503`/`504`,
graceful shutdown.

## Running in Docker and testing

`docker-compose.yml` has a `servers` service: under supervisor it brings up one master
(`tests/servers/worker/master.php`, built from `config/sconcur.servers.config.json`),
holding the HTTP, socket and WebSocket pools and the RabbitMQ consumers as a group each.
Ports are hard-coded in compose (HTTP — `28080:8080`). `make servers-restart` rebuilds
the extension and recreates the container; `make servers-status` and
`make servers-stop` reach the master through `MasterControl`.

The tests do not depend on that service: they start the server as a separate
process via `SConcur\Tests\Impl\HttpServer\TestHttpServer`, whose launch options
are named exactly like the constructor parameters and passed as `--name=value`.

```php
$server = TestHttpServer::start(['maxConcurrency' => 2, 'handlerTimeoutMs' => 200]);

// $server->baseUrl(), $server->signal(SIGTERM), $server->waitForExit(3.0), $server->stop()
```

`BaseHttpServerTestCase` brings up one server per test class (override
`serverOptions()` as needed), and the demo server
(`tests/servers/http/http-server.php`) has routes for every scenario. Coverage
(`tests/feature/Features/HttpServer/`): routing and methods, query and headers,
binary bodies, multi-value response headers, streaming, the concurrency limit,
`413`, the handler timeout, a graceful shutdown, `maxRequests`.

---

See also: [Server statistics](admin-stats.md),
[Worker master](worker-master.md),
[How to add a new server](adding-a-server.md).
