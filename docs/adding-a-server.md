English | [Русский](adding-a-server.ru.md)

# How to add a new server

A server is a special kind of feature: a long-lived network listener that lives in
the extension, accepts incoming connections and streams every event into PHP,
which handles it in a separate coroutine and sends the response back. It inverts an
ordinary feature: rather than PHP calling the extension and waiting for a result,
the extension hands PHP a stream of incoming requests.

The reference to copy is `HttpServer` (`src/Features/HttpServer/`,
`ext/src/features/httpserver/`). `SocketServer` follows the same pattern, and
the code shared by both is already extracted into a trait; `WsServer` is a hybrid —
the listener and handshake from `HttpServer`, the push model of `SocketServer`
after the upgrade.

Read [how to add an ordinary feature](adding-a-feature.md) first — a server reuses
its mechanics (`Method`, payloads, the state/streaming registry, `next()`) and adds
a network layer and a serving loop on top. See also the
[HTTP server](http-server.md) and [worker master](worker-master.md) docs.

## Model: two `Method`s per server

A server is a pair of methods, both served by one feature (matched on `Method`):

- `<Server>Serve` — open the listener and stream accepted requests into PHP (a
  self-pumping stream: an extension-side runtime task publishes each request as the next
  stream result, no per-request `next()` crossing);
- `<Server>Respond` — deliver one response record (whole, or head/chunk/end of a
  stream) from the PHP handler back to the waiting connection.

Reference: `HttpServe` (`hs`) + `HttpRespond` (`hr`), both → the httpserver
feature. Both values are mirrored in PHP `MethodEnum` and Rust
`ext/src/types/method.rs`, and registered in `ext/src/features/mod.rs` with one arm
for both:

```rust
Method::HttpServe | Method::HttpRespond => Ok(httpserver::get()),
```

```mermaid
flowchart TB
    client["client"]
    serve["connection task (the extension's listener)"]
    sched["Scheduler::serve (PHP)"]
    handler["handler(Request): Response"]
    respond["handle_respond (extension)"]

    client <-->|"connection / response into socket"| serve
    serve -->|"RequestEvent → requests channel → self-pumping Next() → AddResult"| sched
    sched -->|"spawns coroutine"| handler
    handler -->|"RespondPayload (Respond method)"| respond
    respond -->|"finds connection by requestId"| serve
```

## Mandatory requirements

Besides the two general feature requirements (context cancellation and execution
deadline), a server has its own:

1. The server state's context = the server's lifetime. The context of the `Serve`
   task is propagated into `http.Server.BaseContext`, so cancelling the flow or
   `stopFlow` tears down the listener and all waiting connections. **No request may
   outlive the server's stop.**
2. A per-request limit, not only a per-server one. Each handler is bounded by
   `handlerTimeoutMs` inside the extension (a timer in a separate runtime task, firing
   independently of PHP): before the first write the client gets a `504`, after
   the stream has started the response is aborted.
3. Graceful shutdown and orphaned workers. A server must be able to stop
   accepting new connections without touching the ones already accepted (for a
   seamless handover to `SO_REUSEPORT` siblings) and to self-terminate if its
   master died (`--masterPid`).

## Payloads

Written like an ordinary feature's (mirrored, `msgpack` tags = short keys,
cross-references). A server needs at least three:

1. `ServePayload` — the listener address plus tuning (timeouts in ms, limits in
   bytes, `reusePort`).
2. `RespondPayload` — one response record. The `op` field selects the record kind;
   for `HttpServer` these are `OP_FULL`(0) / `OP_HEAD`(1) / `OP_CHUNK`(2) /
   `OP_END`(3), built by the factories `RespondPayload::full()/head()/chunk()/end()`.
   Headers are normalized to `array<string, list<string>>`.
3. `RequestEvent` — what the extension streams into PHP per request (an
   extension-side struct only; PHP
   decodes it straight into the handler's request object). It carries `requestId`,
   the method/path/headers, the inline first body chunk and a streaming key for the
   rest of the body (`BodyKey`).

> `requestId` is the end-to-end identifier: the extension generates it on accept
> (`flowKey:r:<n>`), puts it into `RequestEvent`, PHP returns it in every
> `RespondPayload`, and the extension uses it to find the waiting connection. Make it unique
> within a flow.

## PHP side

The shape of the request/response is up to you. The HTTP server exposes PSR-7
outward (the request is assembled from `RequestEvent` in
`HttpServer::decodeRequest()` via an injected PSR-17 factory; the body is
`Dto/RequestBodyStream` over `Dto/RequestBody`), while the socket and WS servers
use their own `readonly` DTOs — `Dto/Connection` with
`read()`/`write()`/`close()` for the push model. In both cases the response is
encoded into `RespondPayload`. The records of a streamed response
(head/chunk/end) are acknowledged back, which is what keeps a handler from
outrunning the client; the one-shot full response of the HTTP server is
fire-and-forget — `RespondPayload::full()` sets the no-result flag (`nr`) and
goes through `FeatureExecutor::execNoResult()`, so the coroutine finishes
without waiting for the write (the socket and WS servers acknowledge every
command).

Argv parsing, signal handlers, arming automatic preemption, the orphan check and the
lifecycle log are already extracted into the stateless
`SConcur\Features\Server\ServerRuntimeSupportTrait`:

- `parseArgs(array $argv): array` — collect the scalar (`int`/`bool`/`float`/
  `string`) constructor parameters by reflection, coerce each `--name=value` string
  to the type and throw `InvalidServerArgumentException` on an unknown argument;
- `installSignalHandlers(bool &$stopRequested): Closure` — install SIGTERM/SIGINT
  (via `pcntl_async_signals`; without ext-pcntl it is a no-op) and return a
  restorer to call in `finally`;
- `isOrphaned(int $masterPid): bool` — `posix_getppid() !== $masterPid`, immune to
  PID reuse because the kernel reparents the process once the master dies;
- `applyTelemetryEnvironment(array $overrides): array` — read the telemetry env;
- `withPreemption(int $quantumMs, Closure $callback): mixed` — run the callback with
  automatic preemption armed and disarm it afterwards. A server passes its quantum to
  `Scheduler::serve()` instead and needs this only if it has no serve loop of its own;
- `logServerEvent(string $message): void` — one timestamped line about the worker's own
  lifecycle, on stdout.

A new server just uses the trait, and to be launchable under `bin/sconcur-server`
it adds a static `fromArgs()` modelled on `HttpServer::fromArgs()`: call
`self::parseArgs($argv)`, add `onError` if present, unpack into the constructor.

The public `serve(Closure $handler)` then generates a `flowKey`, installs signal
handlers (restoring them in `finally`), starts the listener with
`Extension::get()->push($flowKey, new ServePayload(...))` — a streaming task whose
batches are the incoming requests — and hands control to the shared
`Scheduler::get()->serve(...)`, passing:

- `serverFlowKey`/`serverTaskKey` — the listener stream keys;
- `maxRequests` — finish cleanly after N requests;
- `onRequest(string $payload)` — spawn-on-request: decode, call the handler, send
  the response (`HttpServer::handle()` in the reference);
- `shouldStop(): bool` — a signal arrived or the worker is orphaned;
- `onDrainStart()` — called once when the shutdown begins: stop accepting early
  via `Extension::get()->httpStopAccepting($flowKey)`, so new connections go to
  `SO_REUSEPORT` siblings;
- `onShutdownStep(string $step)` — each graceful-shutdown step in words, for the worker
  to log;
- `preemptionQuantumMs` — arm automatic preemption for as long as the loop serves, so a
  CPU-bound handler cannot starve the others (`0` disables), see
  [coroutine switching](coroutine-switching.md);
- `handlerTimeoutMs` — how long one handler coroutine may run before it is unwound where
  it stands (`0` disables), see [coroutine timeout](coroutine-timeout.md). Preemption is
  what lets that deadline reach a handler that never waits.

`Scheduler::serve` itself multiplexes the incoming requests and the async work
of their handlers in a single wait loop (`waitAnyTimeoutBatch`) and on shutdown
closes the flow down cleanly (`stopFlow`). This mechanic is shared and does not
need rewriting.

## extension side

`ext/src/features/<server>/mod.rs` implements the `Feature` trait, and its
`handle` dispatches on the message's `Method` into `handle_serve`/`handle_respond`.
The feature itself is a unit struct behind a `OnceLock`; its state lives in a
`Registries` struct owned by the core (`ext/src/core.rs`), so a fork discards it
with everything else. Two maps: `pending_requests` (`requestId` → the channel its
connection task reads write commands from — global, because a respond arrives on a
different flow than the request) and `server_states` (`flowKey` → `ServerState`,
so stop-accepting can find the listener).

`handle_serve` parses `ServePayload`, opens the listener (`listen.rs`, which sets
`SO_REUSEPORT` when asked), registers the `ServerState` and starts two things: an
accept loop task and the self-pumping stream that publishes every accepted request
as the next result of the serve task, until the flow is cancelled. The accept
stream bypasses the `states` registry — that one serves only the secondary streams
(the request body, the inbound message streams). Backpressure is layered:
publishing a result waits on the shared results buffer, the requests channel
buffers accepts, and beyond that the connection task itself waits.

The accept loop (`server.rs`) takes a `maxConcurrency` permit before reading the
body — so a request waiting for a slot does not hold a body buffer — registers the
request in `pending_requests`, sends a `RequestEvent` into the requests channel and
waits for write commands from PHP, applying them to the socket. On the handler
timeout or a disconnect it drops the pending entry, so a late response does not
hang forever, and it writes the access-log line on the extension side. Cancelling
the flow token unblocks every running connection task at once, which is what the
full teardown does.

`handle_respond` decodes the `requestId` first (with a separate mini-struct, so
routing works even when the rest of the payload is corrupt), finds the pending
request and hands over the write command, waiting for it to be applied — the
handler coroutine continues only once the bytes have gone into the socket, or the
request is gone. A payload with the no-result flag (`nr`, the full write) is handed
over fire-and-forget instead: no result is published, and the handover does not
wait on the flow token, because the coroutine may already be gone.

If the body is larger than the inline first chunk, the extension puts the remainder
into a separate streaming state (registered under `<requestId>:body`) and yields
that key in the request event's `bk`; PHP reads the pieces through the same shared
`next()` mechanism, with a fixed 64 KiB transport granularity. The inline
first-chunk buffer is sized by the declared `Content-Length` when that is smaller
(a per-request allocation hot spot); chunked or unknown lengths use the full
64 KiB.

## The C export `StopAccepting`

The shared exports (`push`, `next`, `stopFlow`, `waitAnyBatch`,
`waitAnyTimeoutBatch`) are reused as they are. A server additionally needs its own
export for the early stop of accepting — each server's `serverStates` is its own
map, so another server's `httpStopAccepting` cannot be reused (cf.
`socketStopAccepting`). Add `<server>StopAccepting` along the same chain:

- `ext/src/lib.rs` — the `#[unsafe(no_mangle)] pub extern "C"` function that
  calls into the feature;
- `ext/include/sconcur_core.h` — its declaration, which is what the glue links
  against;
- `ext/sconcur.c` — `PHP_FUNCTION`, `arginfo`, the `ZEND_NS_FE` registration and the
  header line;
- `ext/sconcur.stub.php` — the function declaration;
- `src/Connection/Extension.php` — `use function` plus a PHP wrapper.

`StopAccepting(flowKey)` finds the `serverState` and calls its
`stopAccepting()`, which closes only the listener (`http.Server.Shutdown` in a
separate runtime task on a background context) without cancelling the requests
already accepted. In a `SO_REUSEPORT` pool the kernel immediately hands new
connections to siblings while this process finishes its own.

This is a protocol change, so the extension-version rule applies (bump at most once
per branch, see [.ai/README.md](../.ai/README.md)).

> A minimal server can do without `StopAccepting` and tear everything down via
> `stopFlow`, but then it loses the seamless `SO_REUSEPORT` handover — a
> production server under a master needs it.

## Integration with the worker master

A server becomes a server-agnostic worker for `bin/sconcur-server` for free if it
honours the contract: the worker script builds the server from argv
(`MyServer::fromArgs($_SERVER['argv'])`) and serves; the master expands the `server`
block of the JSON config into `--key=value` argv and passes its own pid via
`--masterPid`; `reusePort: true` enables `SO_REUSEPORT`, so the master brings up a
pool of processes and `reload` performs a rolling restart with no downtime. Details
in [Worker master](worker-master.md).

## Statistics

To collect and report statistics out of the box, plug in the neutral
`ext/src/stats/` module — a process-metrics sampler plus a best-effort `Pusher`
that sends snapshots to the master's collector; aggregation and the panel are the
master's job (`src/Telemetry`), see [Server statistics](admin-stats.md).

PHP side: `ServePayload` += `telemetrySocket`/`serverName`/`telemetryIntervalMs`
(keys `ts`/`sn`/`ti`), the constructor += the same three parameters, and
`fromArgs()` calls `self::applyTelemetryEnvironment($overrides)`, which reads the
env the master injects when telemetry is enabled.

extension side: add a workload counter implementing `stats.WorkloadProvider`
(`requestStats` for HTTP, `connectionStats` for socket) and increment it in the
connection/request handler; thread the three telemetry fields through
`serverConfig`; in `newServerState` create
`pusher := stats.NewPusher(name, telemetrySocket, intervalMs, startTime, provider)`
and `pusher.Start()`; call `pusher.Stop()` in `Close()` (safe on a disabled
configuration).

## Tests (mandatory)

Bring up a real server process on loopback and hit it with `curl` — the
infrastructure reference is `tests/impl/HttpServer/TestHttpServer.php` (spawn via
`proc_open`, a free port, reading the access log) plus
`BaseHttpServerTestCase.php`. Cover a basic request/response, streaming,
`maxConcurrency`, `handlerTimeoutMs` (including a natively-blocking handler),
graceful shutdown, `SO_REUSEPORT` (two servers on one port), `maxRequests` and
orphan self-termination. Logic the PHP suites can only catch statistically — a
race whose window is microseconds wide — goes into the core's own unit tests
(`#[cfg(test)] mod tests` beside the code, run by `make ext-test`), and the
end-to-end scenario under the master is `tests/feature/Worker/WorkerMasterTest.php`.

## Checklist

PHP:

- [ ] `MethodEnum` — two values (`<Server>Serve`, `<Server>Respond`).
- [ ] Payloads: `ServePayload`, `RespondPayload` (+ cross-references
      `Rust: payloads::<Type>`).
- [ ] Request/response shape: your own `readonly` DTOs or PSR-7 outward.
- [ ] `use ServerRuntimeSupportTrait;` —
      `parseArgs`/`installSignalHandlers`/`isOrphaned`/`logServerEvent`.
- [ ] `fromArgs()` via `self::parseArgs($argv)`, accepting `--masterPid`.
- [ ] `serve()`: start the listener via `push(ServePayload)` plus
      `Scheduler::serve(...)` with `onRequest`/`shouldStop`/`onDrainStart`/
      `onShutdownStep`, and the `preemptionQuantumMs`/`handlerTimeoutMs` the
      constructor took.
- [ ] Statistics: `ServePayload` += `ts`/`sn`/`ti`, constructor += 3 parameters,
      `self::applyTelemetryEnvironment()` in `fromArgs()`.
- [ ] Tests from a `BaseHttpServerTestCase` analogue (a real process + `curl`).

Rust:

- [ ] The same two constants in `ext/src/types/method.rs`.
- [ ] Payload structs in `payloads.rs` plus `RequestEvent`; mirror PHP 1:1.
- [ ] The feature: `handle` matches on the method → `handle_serve` (bind → the
  server state → the self-pumping stream task) and `handle_respond` (hand-over by
  `requestId`, waiting for the write to be applied; `nr` — fire-and-forget).
- [ ] The server-state and pending-request registries; `stop_accepting(flow_key)`;
      `SO_REUSEPORT` in the listener.
- [ ] the flow's cancellation token honoured; `handlerTimeout`; the access log
      written on the extension side.
- [ ] Statistics: a workload counter, a `stats::Pusher` started with the server
      state and dropped with it.
- [ ] Registration in `ext/src/features/mod.rs` (one arm for both methods).

Boundary / protocol:

- [ ] `<server>StopAccepting` along the chain `lib.rs` → `sconcur.c` →
      `sconcur.stub.php` → `Extension.php`; account for the extension version (bump
      once per branch).

Check:
`make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test`.
