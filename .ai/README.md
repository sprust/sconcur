# SConcur — Agent & Contributor Guide

This is the single source of truth for AI agents (Claude Code, etc.) and human
contributors working in this repository. `CLAUDE.md` and `AGENTS.md` both point
here.

## Further Reading

- [README.md](../README.md) — project overview and usage
- [docs/cli.ru.md](../docs/cli.ru.md) — console commands: `bin/sconcur-load` (download the matching extension .so), `bin/sconcur-status` (check install/version, `--json`), `bin/sconcur-server` (worker master, brief + link)
- [docs/architecture.ru.md](../docs/architecture.ru.md) — internals: Fiber ↔ goroutine, scheduler, layers, task lifecycle (with Mermaid diagrams)
- [docs/adding-a-feature.ru.md](../docs/adding-a-feature.ru.md) — guide for adding a new feature
- [docs/adding-a-server.ru.md](../docs/adding-a-server.ru.md) — guide for adding a new server (long-lived streaming feature: listener + serve loop + worker-master integration)
- [docs/load-testing.ru.md](../docs/load-testing.ru.md) — load behaviour under all I/O features at once (the `/all` route + `bench-http-load-stats`): memory/CPU results and conclusions
- [docs/benchmarks.ru.md](../docs/benchmarks.ru.md) — per-feature benchmarks (native/sync/async): PHP↔Go boundary cost on in-memory DBs and the concurrent-fan-out win, with metric tables (`make bench-*`)
- [docs/mongodb.ru.md](../docs/mongodb.ru.md) — MongoDB feature: collection operations, cursors, BSON types, concurrency, internals
- [docs/http-server.ru.md](../docs/http-server.ru.md) — HTTP-server feature: usage, params, internals, limits
- [docs/socket-server.ru.md](../docs/socket-server.ru.md) — TCP socket-server feature: length-prefix framing, message handler, params, internals, limits
- [docs/worker-master.ru.md](../docs/worker-master.ru.md) — worker master: CLI start/status/stop, restart policy, logging, single-instance, orphan self-termination
- [docs/admin-stats.ru.md](../docs/admin-stats.ru.md) — server statistics: each worker pushes JSON snapshots over a unix socket to a collector embedded in the PHP master, which serves the pool aggregate at GET /api/stats (Prometheus/JSON/HTML), a live panel and SSE, Bearer token, metrics reference
- [docs/http-client.ru.md](../docs/http-client.ru.md) — HTTP-client feature (PSR-18): usage, options, streaming, internals
- [docs/socket-client.ru.md](../docs/socket-client.ru.md) — TCP socket-client feature (dial-side mirror of the socket server): length-prefix framing, Connection read/write/close, params, internals, limits
- [docs/websocket-server.ru.md](../docs/websocket-server.ru.md) — WebSocket-server feature (HTTP-Upgrade listener + socket-server push model): text/binary messages, Connection read/write/close, keepalive ping, params, internals, limits
- [docs/websocket-client.ru.md](../docs/websocket-client.ru.md) — WebSocket-client feature (dial-side mirror of the WebSocket server): connect/read/write/close, text/binary, params, internals, limits
- [docs/mysql.ru.md](../docs/mysql.ru.md) — MySQL / universal SQL feature: usage, bindings, transactions, streaming, internals
- [docs/pgsql.ru.md](../docs/pgsql.ru.md) — PostgreSQL: the SQL feature's second driver; PG-specific differences
- [docs/coroutine-context.ru.md](../docs/coroutine-context.ru.md) — per-coroutine context: framework-neutral key-value store bound to the current fiber, isolated between concurrent coroutines, read-through inherited by children
- [.ai/plans/](plans/) — detailed designs for roadmap items

## Plans

The README keeps only a short, one-line-per-item roadmap. Detailed designs for
roadmap items live in `.ai/plans/` — one Markdown file per plan. When a roadmap
item grows beyond a sentence (mechanics, API sketch, trade-offs, open
questions), put the detail in a `.ai/plans/<kebab-name>.md` file.

**Plans are a development-only artifact.** Never link to `.ai/plans/*` (or
reference the `.ai/plans/` directory) from the main `README.md` or from anything
under `docs/` — those are user-facing. Plan links belong only here, in `.ai/`,
and in other `.ai/plans/` files. The README's `## Планы` bullets stay one-liners
with no plan link; once a plan ships, point the README/docs at the feature's
`docs/*.md` instead.

## Project

SConcur is a PHP concurrency library backed by a custom Go-based PHP extension.
PHP Fibers suspend while the Go extension executes tasks (MongoDB operations,
sleep) concurrently via goroutines. Communication between PHP and Go is
MessagePack-based (msgpack-tagged DTOs, `Transport/MessagePackTransport`).

## Project Structure

Core PHP code lives in `src/` under the `SConcur\` namespace. Main entry points
are `WaitGroup`, `State`, `Connection/Extension`, and feature modules in
`src/Features/` such as `Mongodb` and `Sleeper`. The custom Go-backed PHP
extension lives in `ext/`. Feature and integration coverage lives in
`tests/feature/`, shared test helpers in `tests/impl/`, benchmarks in
`tests/benchmarks/`, and stress checks in `tests/mem-leak/`. Container and
release build assets are under `docker/` and `docker-compose.yml`.

## Build & Run

Requires Docker. All commands via `make`:

```bash
make env-copy           # copy .env.example → .env (first time)
make build              # build Docker images
make up / make down     # start / stop containers
make ext-build          # compile Go extension → ext/build/sconcur.so
make ext-test           # run Go tests
```

## Testing & Quality

```bash
make test               # run all PHPUnit tests (loads sconcur extension)
make test c="--filter=SleeperTest"  # run a specific test
make php-stan           # PHPStan level 6
make cs-fixer-check     # check code style (PSR-12 + custom rules)
make cs-fixer-fix       # auto-fix code style
make check              # run all: cs-fixer, phpstan, tests, ext-test
make bench-all          # run all benchmarks
```

Rebuild the extension with `make ext-build` before running tests that depend on
`ext/build/sconcur.so`. Use `make ext-test` when changing Go extension behavior.

## Architecture

**Execution flow:**
`WaitGroup::add(closure)` → `Fiber::start()` → Fiber suspends on
`FeatureExecutor::exec()` → `Extension::push()` sends task to Go → Go goroutine
executes → result sent via shared channel → `Scheduler` retrieves it with
`Extension::waitAny()` and resumes the owning Fiber → `WaitGroup::iterate()`
yields result.

**Concurrency / nested coroutines:** a single process-wide `Scheduler` is the
only place that waits on the extension (`waitAny`, the first ready result of any
flow) and resumes fibers, so coroutines never nest on each other's call stack.
A nested `WaitGroup` inside a coroutine cooperatively suspends (`awaitGroup`)
instead of blocking, so nested coroutines run concurrently with each other and
with the outer flow. (`Extension::wait(flowKey)` remains for the synchronous,
non-fiber path.)

**PHP layer** (`src/`):
- `WaitGroup` — main API: `add()`, `iterate()`, `waitAll()`, `waitResults()`
- `Scheduler/Scheduler` — process-wide cooperative scheduler (single `waitAny` loop, resumes coroutines, wakes nested-group waiters)
- `Scheduler/Coroutine` — a tracked fiber: id, fiber, owning group, callback key
- `State` — static registry mapping Fibers ↔ flows ↔ tasks, and the per-coroutine context store (own key-value map + parent link per fiber id, read-through to the process root; released in `unRegisterFiber`)
- `Context/Context` — static entry point `Context::current(): CoroutineContext` to the current coroutine's context (root outside any fiber); `Context/CoroutineContext` is the framework-neutral `find`/`has`/`set`/`forget` contract. Parent links are recorded in `Scheduler::spawn` / `WaitGroup::add`. See [docs/coroutine-context.ru.md](../docs/coroutine-context.ru.md)
- `Connection/Extension` — singleton wrapping Go extension's exported C functions (`push`, `wait`, `waitAny`, `next`, `stopFlow`, etc.)
- `Features/FeatureExecutor` — coordinates feature execution, detects async context via `Fiber::getCurrent()`
- `Features/Mongodb/Connection/{Client,Database,Collection}` — MongoDB operations (insert, update, delete, find, aggregate, indexes, bulk write)
- `Features/Sleeper/Sleeper` — async sleep
- `Features/Mongodb/Serialization/DocumentSerializer` — encodes/decodes raw BSON via `ext-mongodb` (`MongoDB\BSON\Document`); values are native `MongoDB\BSON\*` types
- `Features/HttpServer/` — long-lived HTTP server with a PSR-7 surface (mirror of the PSR-18 HttpClient): `HttpServer::serve(Closure(ServerRequestInterface): ResponseInterface)`, `HttpServer::fromArgs()` (build from argv; both take injected PSR-17 `ServerRequestFactoryInterface` + `ResponseFactoryInterface`, so the library is implementation-agnostic), `Scheduler::serve()`. The request is built from the Go event via the factory; its body is `Dto/RequestBodyStream` (a lazy `StreamInterface` over `Dto/RequestBody`). A response whose body has unknown size (`getSize() === null`) is streamed chunk by chunk (chunked/SSE) with write backpressure. Payloads `ServePayload`/`RespondPayload`. A built-in access log line per request goes to STDOUT. See [docs/http-server.ru.md](../docs/http-server.ru.md).
- `Features/SocketServer/` — long-lived TCP server, **push model** over length-prefix framing: `SocketServer::serve(Closure(Connection): void)`, `SocketServer::fromArgs()`, `Dto/Connection` (`read()`/`write()`/`close()` — the handler drives the connection and pushes frames at will), payloads (`ServePayload`/`RespondPayload` with ops frame/close). One coroutine per connection; an access log line per connection goes to STDOUT. Shares `Scheduler::serve()` with HttpServer. See [docs/socket-server.ru.md](../docs/socket-server.ru.md).
- `Features/Server/ServerRuntimeSupportTrait` — shared server runtime glue used by both `HttpServer` and `SocketServer`: argv→constructor-override parsing (`fromArgs`), SIGTERM/SIGINT handlers, and the orphaned-worker check.
- `Features/HttpClient/` — async PSR-18 HTTP client with response streaming: `HttpClient` (`ClientInterface`), `HttpClientOptions`, `Payloads/RequestPayload`, `Dto/ResponseBodyStream` (`StreamInterface`). `HttpClient::download()` writes the response body straight to a file on the Go side (`DownloadFileMode`, `Dto/DownloadResult`, `DownloadException`) — never crossing into PHP. See [docs/http-client.ru.md](../docs/http-client.ru.md).
- `Features/SocketClient/` — async TCP client (dial-side mirror of `SocketServer`): `SocketClient::connect(string $address): Dto/Connection`, `SocketClientOptions`, command-envelope payloads (`Connect`/`Send`/`Close` via `SocketClientCommandEnum`). `connect()` returns a streaming result (first = `ConnectionMeta`, then inbound frames), so it works on the sync path too (the flow stays alive like HttpClient's body stream). `Dto/Connection` is a thin subclass of the shared `Features/Socket/Dto/AbstractConnection` (also the parent of `SocketServer`'s `Connection`): `read()` pulls inbound frames via `next()`, `write()`/`close()` route by id. See [docs/socket-client.ru.md](../docs/socket-client.ru.md).
- `Features/Socket/Dto/AbstractConnection` — shared base for the socket and WebSocket `Connection` DTOs (server accept-side and client dial-side): `read()`/`write()`/`close()`/`isClosed()`; subclasses supply the frame/close payloads and the feature's connection-closed exception. Keeps the features decoupled (all depend on the neutral base, not each other).
- `Features/WsServer/` — long-lived WebSocket server, hybrid of HttpServer (the `net/http.Server` listener + upgrade handshake) and SocketServer (the push-model connection): `WsServer::serve(Closure(Connection): void)`, `WsServer::fromArgs()`, `Dto/Connection` (`read(): ?string` + `lastMessageWasBinary()`, `write(string, bool $binary = false)`, `close()`), payloads (`ServePayload`/`RespondPayload` with op frame/close + text/binary message type). Non-WS request → 426; server keepalive ping. Shares `Scheduler::serve()` with the other servers. See [docs/websocket-server.ru.md](../docs/websocket-server.ru.md).
- `Features/WsClient/` — async WebSocket client (dial-side mirror of `WsServer`): `WsClient::connect(string $url): Dto/Connection`, `WsClientOptions`, command-envelope payloads (`Connect`/`Send`/`Close` via `WsClientCommandEnum`). `connect()` returns a streaming result (first = `ConnectionMeta`, then inbound messages); `Dto/Connection` subclasses `AbstractConnection` with the text/binary read/write. See [docs/websocket-client.ru.md](../docs/websocket-client.ru.md).
- `Features/Sql/` — universal SQL feature (driver-agnostic core on Go `database/sql`): `Connection` (`query`/`fetchAll`/`exec`/`begin`), `Transaction`, `Results/{RowsResult,ExecResult}`, command-envelope payloads. `Features/Mysql/Connection` and `Features/Pgsql/Connection` are thin driver facades supplying `MethodEnum::Mysql` / `MethodEnum::Pgsql`. See [docs/mysql.ru.md](../docs/mysql.ru.md) and [docs/pgsql.ru.md](../docs/pgsql.ru.md).
- `Worker/` — worker master (a process supervisor; does NOT load the extension): `WorkerMaster` (`run()`: spawn/supervise/restart/graceful), `MasterConfig` (loads the `--configPath` JSON, expands the `server` block into worker argv), `MasterCli` (`start`/`status`/`stop` behind `bin/sconcur-server`), `WorkerProcess` (proc_open + output capture), `Cpu`, `MasterLock` (flock single-instance), `MasterState`/`MasterStateFile`, `MasterLogger` (daily rotation), `RestartPolicy`. The master injects its pid as `--masterPid`, which `HttpServer::fromArgs()` wires into the orphan check. With `panelPort`+`adminToken` it also embeds the telemetry plane (`src/Telemetry`) in its supervision loop (`usleep`→`stream_select`). See [docs/worker-master.ru.md](../docs/worker-master.ru.md).
- `Telemetry/` — the master-side stats collector and live panel (pure PHP, no extension): `TelemetryRuntime` (`poll()` orchestrator driven by the master loop), `Collector` (unix-socket listener decoding pushed frames into `Store`), `PanelServer` (non-blocking HTTP/SSE serving `GET /api/stats`, `/`, `/events` with Bearer auth), `FrameCodec`, `Aggregator`, `Dto/*` (`Snapshot`/`Aggregate`/...), `Render/*` (`Json`/`Prometheus`/`Html`). Consumes the `internal/stats` push protocol. See [docs/admin-stats.ru.md](../docs/admin-stats.ru.md).

**Go extension** (`ext/`):
- `main.go` — cgo exports (`push`, `wait`, `next`, `waitAny`, `waitAnyTimeout`, `tasksCount`, `stopFlow`, `httpStopAccepting`, `socketStopAccepting`, `destroy`, `version`)
- `internal/handler/` — singleton orchestrator routing messages to flows
- `internal/logger/` — fire-and-forget async log sink: a background goroutine writes pre-formatted lines to stdout (buffered, timer-flushed, drops on overflow), so the loop never blocks on log I/O. The HttpServer access log feeds it directly from the Go response goroutine (no PHP↔Go crossing per request)
- `internal/flows/` — `Flows` manages concurrent `Flow` instances; each `Flow` holds tasks and a result channel
- `internal/tasks/` — individual task unit with context cancellation
- `internal/states/` — registry of streaming states (cursor batches, HTTP requests, request-body chunks) driven by `next()`
- `internal/features/sleeper/` — goroutine-based sleep
- `internal/features/mongodb/` — MongoDB operations via Go driver, with aggregation cursor state management
- `internal/features/httpserver/` — `net/http.Server` as an http.Handler streaming each request to PHP; response write-commands, request-body streaming, concurrency limit, timeouts, graceful shutdown, SO_REUSEPORT. `requeststats.go` is the HTTP workload counter (a `stats.WorkloadProvider`) folded into each snapshot.
- `internal/stats/` — neutral worker-side telemetry package shared by the HTTP and socket servers: process metrics (`metrics.go`: /proc + runtime) plus `Pusher` (`pusher.go`), which samples a `Snapshot` (`snapshot.go`) on two cadences (workload every interval, the STW `ReadMemStats` sub-sampled) and pushes it best-effort as a length-prefixed JSON frame (`{"t":"snapshot","s":...}`, via `internal/socket.WriteFrame`) over the collector's unix socket. The feature-specific counters come through a `WorkloadProvider`. Aggregation, the `/api/stats` panel and SSE live on the PHP master side (`src/Telemetry`), not here. See [docs/admin-stats.ru.md](../docs/admin-stats.ru.md).
- `internal/features/sql/` — driver-agnostic SQL on `database/sql`: one handler dispatches Query/Exec/Begin/Commit/Rollback by the envelope's command; `pools.go` is the `*sql.DB` pool registry (mirrors MongoDB clients), `rows_state.go` streams a SELECT cursor, `transactions.go` pins a `*sql.Tx` to a held begin task (auto-rollback on context cancel). The driver is selected per `Method`: `GetMysql()` registers go-sql-driver/mysql, `GetPgsql()` registers jackc/pgx (error label "pgsql").
- `internal/features/socketserver/` — raw TCP listener as a streaming state: each accepted connection is one batch streamed to PHP (`ConnectionEvent`); `message_state.go` streams inbound length-prefixed frames (one per `next()` → `Connection::read()`), `server.go` runs the per-connection write loop applying frame/close commands with write-backpressure, `frame.go` is the length-prefix codec, `listen.go` is TCP + `SO_REUSEPORT`. `StopAccepting` closes the listener and half-closes in-flight connections (force-closing push-only ones after a grace) for graceful drain. Push model: no per-message timeout. Two methods, one feature (like httpserver). `connectionstats.go` is the socket workload counter (active/total connections, a `stats.WorkloadProvider`) fed into each snapshot the `stats.Pusher` sends
- `internal/features/httpclient/` — `net/http.Client` sending one request as a streaming state: first result carries response metadata + inline first chunk, subsequent results are raw body chunks; reusable transports (keep-alive pool), per-request deadline; optional streamed request body (upload) via an `io.Pipe` fed by `UploadChunk`/`UploadEnd` commands. Sub-operations are selected by a command in the payload envelope (`HttpClientCommand`), like MongoDB — not by separate `MethodEnum` values. `download.go` is the sink path: when the request carries `SinkPath`, the response body is `io.CopyBuffer`'d straight into a file (mode→`os.O_*` via `downloadModeToFlags`) and only status+headers return to PHP — the body never crosses the boundary
- `internal/features/socketclient/` — outbound TCP dialer (dial-side mirror of socketserver): `connect.go` dials with `connectTimeout` and registers a `connectionState` (first `Next()` returns `ConnectionMeta`, subsequent `Next()` stream inbound frames); `feature.go` routes `Connect`/`Send`/`Close` sub-operations (one method, command envelope `SocketClientCommand`) — `Send`/`Close` dispatch to the connection's write loop by id. Dial failures carry the `net:` marker → `SocketClientConnectException`
- `internal/features/wsserver/` — WebSocket server: a `net/http.Server` whose `serverState` is the `http.Handler`; `ServeHTTP` acquires the `maxConcurrency` slot, `websocket.Accept`s (coder/websocket) the upgrade (non-WS → 426, wrong path → 404), streams each connection to PHP as a `ConnectionEvent`, runs a read goroutine pumping `conn.Read` (so control frames stay serviced) into `message_state.go`, and a write loop applying frame/close with a server keepalive ping. `StopAccepting` drains for SO_REUSEPORT handover; `connectionstats.go` feeds the shared `connections` workload; `listen.go` is TCP + `SO_REUSEPORT`
- `internal/features/wsclient/` — outbound WebSocket dialer (dial-side mirror of wsserver): `connect.go` `websocket.Dial`s with `connectTimeout` and registers a `connectionState` (first `Next()` returns `ConnectionMeta`, subsequent `Next()` stream inbound messages from a read goroutine); `feature.go` routes `Connect`/`Send`/`Close` (command envelope `WsClientCommand`). Dial/handshake failures carry the `net:` marker → `WsClientConnectException`
- `internal/ws/` — neutral WebSocket plumbing shared by wsserver and wsclient (not by each other, like `internal/socket` for the raw TCP pair): the per-connection write loop with backpressure (`PendingConnection`/`ConsumeCommands`/`Dispatch`, with an optional server ping) and the inbound message-type codec (`EncodeInbound`/`MessageTypeFromCode`, the one-byte text/binary marker)
- `internal/socket/` — neutral shared TCP code used by both socketserver and socketclient (not by each other): `frame.go` (length-prefix codec `ReadFrame`/`WriteFrame`), `message_state.go` (`MessageState` — inbound frame stream), `connection.go` (`PendingConnection`, write-loop `ConsumeCommands`, `Dispatch` with backpressure, `NextConnectionId`)
- `internal/helpers/` — small shared helpers: `CalcExecutionMs`, and `ReadChunk` (fixed-granularity body chunk reader used by both the HTTP server and client)

**Key enums:**
- `MethodEnum`: Sleep (1), MongodbCollection (2), HttpServe (3), HttpRespond (4), HttpClient (5), Mysql (6), Pgsql (7), SocketServe (8), SocketRespond (9), SocketClient (10), WsServe (11), WsRespond (12), WsClient (13)
- `SocketClientCommand` (sub-operations under SocketClient): Connect (1), Send (2), Close (3) — selected via the payload envelope's `cm`, like HttpClient
- `WsClientCommand` (sub-operations under WsClient): Connect (1), Send (2), Close (3) — selected via the payload envelope's `cm`, like SocketClient
- `SqlCommandEnum` (sub-operations under a SQL method, selected via the envelope's `cm`): Query (1), Exec (2), Begin (3), Commit (4), Rollback (5)
- `HttpClientCommand` (sub-operations under HttpClient): Request (1), UploadChunk (2), UploadEnd (3) — selected via the payload envelope's `cm`, like MongoDB's `CommandEnum`
- `CommandEnum`: InsertOne (1), BulkWrite (2), Aggregate (3), InsertMany (4), CountDocuments (5), UpdateOne (6), FindOne (7), CreateIndex (8), DeleteOne (9), DeleteMany (10), UpdateMany (11), Drop (12), DropIndex (13), Find (14), Distinct (15), FindOneAndUpdate (16), FindOneAndDelete (17), FindOneAndReplace (18), ReplaceOne (19), EstimatedDocumentCount (20), CreateIndexes (21), ListIndexes (22), ListCollections (23), ListDatabases (24), RenameCollection (25), RunCommand (26)

## Test Structure

- `tests/feature/` — PHPUnit feature tests with `BaseTestCase` (extension lifecycle) and `BaseAsyncTestCase` (async event ordering framework)
- `tests/impl/` — test helpers (MongoDB resolver, app bootstrap)
- `tests/benchmarks/` — performance benchmarks comparing async vs native
- `tests/mem-leak/` — memory leak stress tests

Tests use PHPUnit 11. Add feature tests in `tests/feature/...` with `*Test.php`
suffixes. Async flow tests commonly extend `BaseAsyncTestCase`;
lifecycle-sensitive tests extend `BaseTestCase`.

## Code Style

- PHP 8.4, PSR-12 plus repository-specific `php-cs-fixer` rules from `cs-fixer.dist.php`
- Aligned assignments; 4 spaces, LF line endings, ~120 column guide from `.editorconfig`
- PHPStan level 6
- `readonly` classes for DTOs
- Classes use PascalCase; methods and properties use camelCase; namespaces mirror directory paths (e.g. `SConcur\Features\Sleeper\Sleeper`)
- All traits must carry the `*Trait` postfix (e.g. `ServerRuntimeSupportTrait`), so a `use` line is recognizable as a trait at a glance
- Namespace: `SConcur\` → `src/`, test namespaces: `SConcur\Tests\Feature\`, `SConcur\Tests\Impl\`
- Code must be maximally typed (type hints for parameters, return types, properties)
- Never abbreviate variable names — use full, descriptive names
- Prefer short arrays (`[]`)
- Do **not** use `final` on classes
- Class properties (including promoted constructor properties) must be `protected`, never `private` (use `public` only for DTO fields read externally)

### Naming

- Never abbreviate variable names — use full, descriptive names (e.g. `$exception`, not `$e`; `$request`, not `$req`).
- A variable holding a class instance is named exactly after that class, in lowerCamelCase: `CreateBookingHotelAction` → `$createBookingHotelAction`, `RequestPayload` → `$requestPayload`, `Client` → `$client`.
- **A property, parameter or constant holding a measured quantity must carry its unit in the name**, so the unit is unambiguous at every call site: `filesizeBytes`, `bufferSizeBytes`, `maxResponseBodyBytes`, `timeoutMs`, `executionMs`, `intervalSeconds`. Applies to new and changed code (do not mass-retrofit existing fields in unrelated areas). Codes/identifiers that are not a measured quantity (e.g. `statusCode`, `sinkMode`) are exempt.

### Blank lines & block separation

- Separate every `{}` block with blank lines (an empty line before and after method/closure/control-structure bodies where it aids readability; blocks never butt directly against unrelated code).
- Separate logical blocks inside a method with a blank line — group variable declarations, then method calls, then the return, etc., with one empty line between groups.

### Method parameters & call arguments

- Always name method parameters meaningfully, especially when a method has more than one.
- Use **named arguments** when calling a project method or constructor that has more than one parameter, or that has at least one optional parameter. Built-in PHP functions (`str_starts_with`, `array_values`, `sprintf`, …) are exempt — the rule is for project methods/constructors only. A call to a single required-only parameter may stay positional and inline.
- When a call uses named arguments, lay them out **vertically** — one argument per line — with a trailing comma:
  ```php
  $response = new NetworkException(
      request: $request,
      message: $message,
      previous: $exception,
  );
  ```
- A function/constructor call is formatted **uniformly**: either all arguments on one line, or every argument on its own line. Mixed style is forbidden — e.g. `new RuntimeException(sprintf(` followed by vertical arguments is not allowed. If a nested call has its arguments expanded vertically, the outer call's first argument must also start on a new line (the nested call becomes its own vertical argument).

### Method signatures

- A signature need not be vertical if the line does not exceed 120 characters; otherwise format it vertically (one parameter per line).
- If any single parameter name is longer than ~20 characters, format the signature vertically even when there is only one parameter.

### Arrays

- Place array keys and values on their own lines (one element per line, trailing comma) for arrays with two or more elements. Empty `[]` and a trivial single-element array may stay inline.

### Conditions

- In conditions that mix `&&` and `||` in one expression, and in ternary operators, wrap condition groups in parentheses to remove operator-precedence ambiguity. Simple same-operator conditions need no extra parentheses: `if ($value !== null && $value > 0)` is fine; `if ($a && $b || $c)` needs them.

## Documentation Style

User-facing docs are `README.md` and `docs/*.ru.md` (written in Russian). They
were deliberately reworked to not read as AI-generated (a contributor flagged the
old format). When writing or editing docs, hold to these rules:

- **Verify every technical claim against the code before writing it** — class and
  method names/signatures, option names and defaults, enum cases, CLI flags, file
  paths, behavioral claims. Fix inaccuracies; never guess.
- **Minimal bold.** Use `**bold**` only for a genuinely critical warning or a
  couple of key terms — not one highlight per paragraph (heavy bolding is the top
  "AI-generated" tell).
- **Dry, factual tone.** Short sentences; drop gratuitous «ёлочки» around terms
  and marketing metaphors.
- **Diagrams in Mermaid** (GitHub renders them). Rules to keep them rendering
  everywhere, including PhpStorm:
  - No `<br/>` anywhere — some renderers print it literally. Use single-line node
    labels (combine ideas with ` — ` or `(...)`); in `sequenceDiagram` use
    separate `Note over` lines.
  - For a request+response between two components use one bidirectional edge
    `A <-->|"..."| B`, never two opposing edges — a 2-cycle makes the layout
    engine place the blocks side-by-side / reversed.
  - In `flowchart TB` declare the caller first so it renders on top.
  - Label edges with the real call/method names from the code.
- **README is a short "visitor card"**: what it is, for whom, key limits, a quick
  example, links to `docs/`. Deep internals live under `docs/` (e.g.
  `docs/architecture.ru.md`), not inline in the README.

## Extension versioning

The Go extension version lives in `ext/main.go` (`version()`) and the minimum
required version in `src/Connection/Extension.php`
(`REQUIRED_EXTENSION_VERSION`); they are bumped together on any PHP↔Go protocol
change. **Never bump the major version without the maintainer's approval**; bump
the minor only when warranted, otherwise the patch. **Bump the version at most
once per git branch** — the first protocol change on a branch bumps it, later
commits on the same branch reuse that version (do not move it again). Current:
`0.5.0`.

**All three version sources must be equal** — bump them together, in the same
commit:

1. `ext/main.go` → `version()` (the Go extension's reported version)
2. `src/Connection/Extension.php` → `REQUIRED_EXTENSION_VERSION`
3. `composer.json` → `"version"`

The release CI derives the release tag from the extension version (via
`bin/sconcur-status`), so a drift between these would ship a mislabeled release.
`tests/feature/Connection/VersionConsistencyTest.php` enforces the equality —
it fails the build if any of the three diverges.

## Exceptions

Concept: callable signatures stay clean of `@throws` noise, so the public API
does not advertise concrete throwables — any caught `Throwable` is wrapped before
re-throwing. Rules:

- **Never `throw` a built-in exception directly** (`RuntimeException`,
  `LogicException`, `DomainException`, etc.). Always throw a custom exception
  from `SConcur\Exceptions\` named for the case.
- Custom exceptions extend a built-in base by nature: **`RuntimeException`** for
  runtime failures (e.g. `TaskExecutionException`, `CallbackExecutionException`,
  `ExtensionNotLoadedException`, `Mongodb\InvalidCountResultException`),
  **`LogicException`** for invariant/usage bugs (e.g. `OutsideFiberException`,
  `UnexpectedTaskKeyException`, `UnexpectedResultTypeException`,
  `FiberStateException`). So `catch (RuntimeException)` still works while the
  concrete type stays catchable.
- When wrapping a caught `Throwable`, keep it as `previous` (preserve message +
  chain). A `Throwable` from `Fiber::suspend()`/`Fiber::resume()` is wrapped the
  same way (see `FeatureExecutor::suspend`, `Scheduler::awaitGroup`); a
  deliberate unwind signal (`FlowStoppedException`) is re-thrown as-is.
- A task error from Go surfaces as `TaskErrorException`; on the async path it is
  wrapped in `CallbackExecutionException` (original reachable via
  `getPrevious()`).

## Workflow Rules

- Always wait for explicit user approval before committing or pushing
- Always propose a commit message before committing
- Never create a git branch without an explicit, direct instruction from the user. Work on and commit to the current branch (normally `master`); creating any branch on your own is forbidden
- Before implementing any task, propose a plan and wait for explicit user approval before starting
- After any PHP changes, run analyzers (`make php-stan`, `make cs-fixer-check`) and tests (`make test`). Fix any errors automatically without asking

## Answering & Code References

When referring to any class, method, or code fragment in a reply, always give
the full path from the project root plus the line number, so the reference is
clickable and jumps straight to the spot in the IDE.

- Whole file: `app/.../MasterWorkerManager.php`
- Specific spot: `app/.../MasterWorkerManager.php:16`

The line number is required when pointing at concrete logic; it may be omitted
only when referring to a file as a whole.

## Commit & Pull Request Guidelines

Use short, imperative subjects (e.g. `update mongodb serializer`,
`remove obsolete handler tests`); keep commit titles concise and descriptive.
Pull requests should explain the behavioral change, list validation performed
(`make check`, targeted tests, benchmarks if relevant), and link the related
issue or task. Screenshots are usually unnecessary unless documentation or
tooling output changed materially.

When an AI agent creates a git commit itself, it must add a sign-off trailer
identifying the agent:

```
Co-Authored-By: <agent name> <email>
```

For example, Claude Code uses
`Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`; OpenAI
Codex uses `Co-Authored-By: OpenAI Codex <noreply@openai.com>`.
