# SConcur — Agent & Contributor Guide

The single source of truth for AI agents (Claude Code, etc.) and human
contributors working in this repository. `CLAUDE.md` and `AGENTS.md` both point
here.

## Project

SConcur is a PHP concurrency library backed by a custom Go-based PHP extension.
PHP Fibers suspend while the Go extension executes tasks concurrently via
goroutines; PHP and Go exchange msgpack-tagged DTOs
(`Transport/MessagePackTransport`).

Core PHP code lives in `src/` under the `SConcur\` namespace — main entry points
are `WaitGroup`, `Scheduler`, `State`, `Connection/Extension` and the feature
modules in `src/Features/`. The extension core lives in `ext/` (Rust), with the
Go core it was ported from in `ext-go-legacy/`. Tests: feature and
integration coverage in `tests/feature/`, shared helpers in `tests/impl/`,
benchmarks in `tests/benchmarks/`, stress checks in `tests/mem-leak/`. Container
and release build assets are under `docker/` and `docker-compose.yml`.

## Further reading

User-facing documentation (each doc also exists in Russian as `*.ru.md`):

- [README.md](../README.md) — overview and usage
- [docs/architecture.md](../docs/architecture.md) — Fiber ↔ goroutine, the
  scheduler, the layers, the task lifecycle
- [docs/cli.md](../docs/cli.md) — `sconcur-load`, `sconcur-status`,
  `sconcur-server`
- [docs/coroutine-context.md](../docs/coroutine-context.md),
  [docs/coroutine-switching.md](../docs/coroutine-switching.md),
  [docs/coroutine-timeout.md](../docs/coroutine-timeout.md) — per-coroutine context;
  `Scheduler::switch()` and automatic preemption; `Deadline::run()` and the deadline a
  coroutine is unwound at
- Features: [mongodb](../docs/mongodb.md), [mysql](../docs/mysql.md),
  [pgsql](../docs/pgsql.md), [http-server](../docs/http-server.md),
  [http-client](../docs/http-client.md),
  [socket-server](../docs/socket-server.md),
  [socket-client](../docs/socket-client.md),
  [websocket-server](../docs/websocket-server.md),
  [websocket-client](../docs/websocket-client.md), [amqp](../docs/amqp.md)
- Operations: [worker-master](../docs/worker-master.md),
  [admin-stats](../docs/admin-stats.md)
- Guides: [adding-a-feature](../docs/adding-a-feature.md),
  [adding-a-server](../docs/adding-a-server.md),
  [msgpack-objects](../docs/msgpack-objects.md)
- Measurements: [benchmarks](../docs/benchmarks.md),
  [load-testing](../docs/load-testing.md), [positioning](../docs/positioning.md)
- [.ai/plans/](plans/) — detailed designs for roadmap items

## Plans

The README keeps only a short, one-line-per-item roadmap. Detailed designs live in
`.ai/plans/` — one Markdown file per plan. When a roadmap item grows beyond a
sentence (mechanics, API sketch, trade-offs, open questions), put the detail in a
`.ai/plans/<kebab-name>.md` file. **Plans are written in Russian** (a maintainer
decision; the `.ru.md` suffix on some older plan files is historical — new plans
use plain `.md` names with Russian content). Code identifiers, paths and code
blocks stay as-is.

**Plans are a development-only artifact.** Never link to `.ai/plans/*` (or
reference the directory) from the main `README.md` or from anything under `docs/`
— those are user-facing. Plan links belong only here, in `.ai/`, and in other
`.ai/plans/` files. Once a plan ships, point the README/docs at the feature's
`docs/*.md` instead.

## Build & run

Requires Docker. All commands via `make`:

```bash
make env-copy           # copy .env.example → .env (first time)
make build              # build Docker images
make up / make down     # start / stop containers
make ext-build          # compile the Rust core → ext/build/sconcur.so
make ext-test           # the core's own Rust unit tests (cargo test --lib)
make ext-check          # smoke the core through the PHP package
make test               # run the PHPUnit suites (loads the sconcur extension)
make test c="--filter=SleeperTest"  # run a specific test
make php-stan           # PHPStan level 6
make cs-fixer-check     # check code style (PSR-12 + custom rules)
make cs-fixer-fix       # auto-fix code style
make check              # cs-fixer, phpstan, ext-build, ext-check, tests
make bench-all          # run all benchmarks
```

Rebuild the extension with `make ext-build` before running tests that depend on
`ext/build/sconcur.so`.

### Two cores

**`ext/` is the extension core. There is no other one.** It is the Rust core,
`SCONCUR_EXT` names it, and every target, benchmark and test harness reads that
variable. Every feature is on it, AMQP included; it is what the release publishes
and what `make test` runs the whole tree against.

`ext-go-legacy/` is the Go core it was ported against, kept as **historical data
and nothing else**. It is not supported, not built, not checked, and CI does not
touch it.

**Compatibility with it is not a requirement, and must not be weighed when
deciding whether to change something.** A simplification that would break only
the Go core costs nothing — judge it on its own merits. This paragraph exists
because the reverse was assumed once and killed a correct change: see item 1 of
[rust-core-hot-path.md](plans/rust-core-hot-path.md).

Two differences the Rust core has from it, both because its AMQP driver cannot
send what the Go one could, and both refused rather than dropped in silence: a
prefetch **size** (`basic.qos`'s prefetch-size is absent from the driver's frame
altogether) and `verify: false` on a TLS connection. Both are in
[docs/amqp.md](../docs/amqp.md).

Two images build from the same commands. `docker/php/Dockerfile` is the development
one and additionally carries the RoadRunner binary and a compiled Swoole — the
reference servers `docs/benchmarks.md` compares against. The release workflow builds
`docker/release/Dockerfile` instead, which has only what `make check` needs; the
`docker-compose.release.yml` overlay points `php` at it and leaves the `servers`
container out. Reproduce that run locally with
`COMPOSE_FILE=docker-compose.yml:docker-compose.release.yml make build up check`.
Anything a check starts needing has to be added to both images, or the release breaks
where local development does not.

A long load run (`bench-*-load-soak`, or any `/all` run past a few minutes) needs
the disk-backed backends described in [benchmarks](../docs/benchmarks.md): on the
default `tmpfs` mounts the backends hit their 1 GiB cap and the demo handler
degrades into silent `500`s while the throughput number still looks plausible.

## Architecture

Execution flow: `WaitGroup::add(closure)` → `Fiber::start()` → the fiber suspends
on `FeatureExecutor::exec()` handing over a pending task
(`PendingPushDto`/`PendingNextDto`) → the resumer (`WaitGroup::launch` /
`Scheduler`) performs `Extension::push()` via `Scheduler::dispatchPendingTask()`
off the fiber stack → a Go goroutine executes it → the result goes to the shared
channel → `Scheduler` retrieves it with `Extension::waitAnyBatch()` (the first
ready result plus the already-ready tail in one crossing) and resumes the owning
Fiber → `WaitGroup::iterate()` yields the result. cgo is never called from a
coroutine's stack — N live boundary-crossing fibers made the fan-out quadratic
(see `.ai/plans/async-fan-out-optimization.ru.md`).

A single process-wide `Scheduler` is the only place that waits on the extension
and resumes fibers, so coroutines never nest on each other's call stack. A nested
`WaitGroup` inside a coroutine cooperatively suspends (`awaitGroup`) instead of
blocking. `Extension::wait(flowKey)` remains for the synchronous, non-fiber path.

Full details, including diagrams, are in
[docs/architecture.md](../docs/architecture.md); per-feature internals are in each
feature's doc. Key PHP classes not covered there:

- `Scheduler/Scheduler` — the cooperative scheduler; `shutdown()` unwinds all live
  coroutines (`FlowStoppedException`) from the shutdown handler registered in
  `get()`, so `exit()` with unfinished work cancels deterministically. `serve()`
  is the shared server loop, `spawn()` a fire-and-forget coroutine.
  `withDeadline()` is the one primitive behind every coroutine deadline: `Deadline::run`
  is its public face, and `add(timeoutMs:)`/`spawn(timeoutMs:)` set the same deadline at
  launch. `0` means "no deadline" everywhere one is taken.
- `Deadline` — the public entry to a scoped deadline, see
  [docs/coroutine-timeout.md](../docs/coroutine-timeout.md).
- `Scheduler/Coroutine` — a tracked fiber: id, fiber, owning group, callback key.
- `Scheduler/FiberPool`, `Scheduler/FiberPoolSignal` — recycles the fibers of
  spawned coroutines: the worker callback never returns, it parks on
  `Fiber::suspend(FiberPoolSignal::Idle)` between jobs, so the per-request stack
  lifecycle (page faults + munmap TLB shootdown) is paid once per fiber. That
  signal replaces `isTerminated()` as the completion signal on the spawn path and
  is an enum case on purpose — an identity comparison handler code cannot forge.
  Stale-result safety rests on the awaited flow/task key validation in
  `Scheduler::resumeByResult` (the keys are fed by never-reused monotonic
  counters), not on fiber identity; for the same reason
  `Scheduler::$pendingDispatches` keys its queue by `Coroutine`, not by fiber.
- `State` — the static registry mapping Fibers ↔ flows, plus the per-coroutine
  context store (released in `unRegisterFiber`). Results route by the owner id
  carried in the frame, so there is no task → fiber map.
- `Context/Context`, `Context/CoroutineContext` — the framework-neutral
  `find`/`has`/`set`/`forget` contract; parent links are recorded in
  `Scheduler::spawn` / `WaitGroup::add`.
- `Features/FeatureExecutor` — coordinates feature execution, detects the async
  context via `Fiber::getCurrent()`.
- `Bson/` — the BSON value objects (`ObjectId`, `UTCDateTime`, `Binary`, …) the
  MongoDB feature hands out, mirroring `MongoDB\BSON\*` so an application moves
  over by changing `use` lines. The namespace is deliberately short: the class name
  travels on the wire with every value. The envelope they cross the boundary in,
  and how to add a type, are in
  [docs/msgpack-objects.md](../docs/msgpack-objects.md).
- `Features/Server/ServerRuntimeSupportTrait` — shared runtime glue for the
  long-lived workers (the servers and `Amqp/Consumer/QueueConsumer`):
  argv→constructor-override parsing, signal handlers, arming automatic preemption,
  the orphaned-worker check, telemetry env. Together with
  `FeatureExecutor::canAwait()` it is where a feature asks the runtime a question
  rather than reaching into the scheduler for the answer. The servers and the
  executor still name `Scheduler` — they spawn and serve, which is what it is for;
  what does not belong there is a feature reading its internals.
- `Features/Amqp/RetryTopology` — the wait queues behind `publish(delayMs:)`, and
  the only thing in the library that declares topology; it does so only when a
  worker script calls it. `RetrySchedule` beside it answers how long a refused
  publish waits before the next attempt.
- `Features/Amqp/Consumer/` — the supervised consumer runtime: `QueueConsumer`,
  plus `QueueSpec`/`QueueSpecParser` for the JSON queue list that arrives in argv.
  It is a server without a socket: the Go side opens the consumers (one channel per
  unit of a queue's weight) and publishes every delivery of all of them as one
  self-pumping stream, and `Scheduler::serve()` drives it exactly as it drives the
  three servers — one coroutine per message, one graceful drain, no loop of its own.
  A stop cancels the consumers and leaves their channels open so the
  acknowledgements in flight land; a consumer the broker takes away is reopened on
  the Go side a second later, and only the connection going away ends one for good
  — see [docs/amqp.md](../docs/amqp.md). `PublishChannelPool` is what keeps a
  prefetch above one from being a trap: a consumer's channel carries the messages of
  every handler running on it, and a publisher confirm is counted per channel, so
  `Delivery::channel()` hands out a channel lent to one handler instead. The pool
  opens them lazily on connections of its own and grows a connection per 255, so no
  weight-and-prefetch combination is ever refused for want of channel numbers; a
  channel handed back with an answer the broker still owes it — a confirm or a return
  nobody waited for — is given up rather than lent on, which is the same
  misattribution delayed.
- `Features/Socket/Dto/AbstractConnection` — shared base for the socket and
  WebSocket `Connection` DTOs (server accept-side and client dial-side); keeps the
  features decoupled, since all depend on the neutral base rather than each other.
- `Worker/` — the worker master (a process supervisor that does NOT load the
  extension): `WorkerMaster`, `MasterConfig`, `MasterCli`, `WorkerProcess`, `Cpu`,
  `MasterLock`, `MasterState`/`MasterGroupState`/`MasterStateFile`, `MasterLogger`,
  `RestartPolicy`. One master supervises several **groups** — `WorkerGroupConfig`
  (a pool's settings, with `MasterDefaults` for what it inherits) and `WorkerGroup`
  (its live slots, backoff and rolling reload). The groups are generic: everything
  a worker needs rides in the group's `server` block, forwarded to its argv
  untouched, so the master stays worker-agnostic.
- `Telemetry/` — the master-side stats collector and live panel (pure PHP):
  `TelemetryRuntime`, `Collector`, `Store`, `PanelServer`, `FrameCodec`,
  `Aggregator`, `Dto/*`, `Render/*`.

Go core (`ext-go-legacy/`) — the structure the Rust core in `ext/src/` mirrors
package for package:

- `main.go` — cgo exports (`ping`, `push`, `wait`, `next`, `waitAny`,
  `waitAnyTimeout`, `waitAnyBatch`, `waitAnyTimeoutBatch`, `tasksCount`,
  `stopFlow`, `httpStopAccepting`, `socketStopAccepting`, `wsStopAccepting`,
  `preemptionArm`, `preemptionDisarm`, `amqpStopConsuming`, `destroy`, `version`)
- `internal/handler/` — singleton orchestrator routing messages to flows
- `internal/flows/`, `internal/tasks/` — concurrent `Flow` instances holding tasks
  and a result channel; a task carries the flow's context directly (cancellation
  is per flow, `stopFlow`), plus a detached flowless path for fire-and-forget
  pushes (`Handler.Push`, allow-listed by `detachable`)
- `internal/states/` — registry of streaming states (cursor batches,
  request-body chunks, client message streams) driven by `next()`; the server
  accept streams are self-pumping and bypass it
- `internal/logger/` — fire-and-forget async log sink: a background goroutine
  writes pre-formatted lines to stdout (buffered, timer-flushed, drops on
  overflow), so the loop never blocks on log I/O
- `internal/features/*` — sleeper, mongodb, sql (one handler dispatching
  Query/Exec/Begin/Commit/Rollback; the driver is selected per `Method`),
  httpserver, httpclient, socketserver, socketclient, wsserver, wsclient, amqp
  (pooled connections, a channel registry, streamed consumers over `amqp091-go`,
  and `consume_serve.go` — the self-pumping delivery stream of a supervised
  worker, whose channels the Go side owns)
- `internal/stats/` — neutral worker-side telemetry shared by the servers: process
  metrics plus `Pusher`, which samples a `Snapshot` and pushes it best-effort as a
  length-prefixed JSON frame over the collector's unix socket
- `internal/socket/`, `internal/ws/` — neutral shared plumbing for the raw TCP and
  WebSocket pairs (frame codec, inbound message stream, write loop with
  backpressure); each pair uses the shared package, not each other
- `internal/helpers/` — `CalcExecutionMs`, `ReadChunk`

Key enums (string-backed; the 2-3 letter values cross the PHP↔Go boundary):

- `MethodEnum`: Sleep (`sl`), Mongodb (`mng`), HttpServe (`hs`), HttpRespond
  (`hr`), HttpClient (`hc`), Mysql (`my`), Pgsql (`pg`), SocketServe (`ss`),
  SocketRespond (`sr`), SocketClient (`sc`), WsServe (`wss`), WsRespond (`wsr`),
  WsClient (`wsc`), Amqp (`amq`)
- Sub-operations selected via the payload envelope's `cm`:
  `SocketClientCommand`/`WsClientCommand` (Connect `con`, Send `snd`, Close
  `cls`), `SqlCommandEnum` (Query `qry`, Exec `exe`, Begin `beg`, Commit `cmt`,
  Rollback `rlb`), `HttpClientCommand` (Request `req`, UploadChunk `upc`,
  UploadEnd `upe`), `AmqpCommandEnum` (Connect `con`, ChannelOpen `cho`,
  QueueDeclare `qud`, Publish `pub`, Consume `csm`, … — see
  `src/Features/Amqp/AmqpCommandEnum.php`), MongoDB's `CommandEnum` (InsertOne
  `ino`, BulkWrite `bw`, Aggregate `agg`, … — see
  `src/Features/Mongodb/CommandEnum.php`)
- `DownloadFileMode` (HttpClient download sink, the `sm` field): Replace (`rpl`),
  Create (`crt`), Append (`app`)

## Tests

- `ext/src/**/mod tests` — the Rust core's own unit tests, run by `make ext-test`
  and part of `make check`. They exist for what the PHP suites can only catch
  statistically: a race whose window is microseconds wide surfaces there as one
  failure in forty full runs and here as a red test. Kept beside their subject
  in a `#[cfg(test)] mod tests`, the way the Go core keeps `*_test.go`. A change
  to concurrent behaviour in `ext/` — a drain, a cancellation, a select over two
  ready branches — belongs here, and the way to show a test earns its keep is to
  undo the fix and watch it fail
- `tests/feature/` — PHPUnit feature tests with `BaseTestCase` (extension
  lifecycle) and `BaseAsyncTestCase` (async event ordering framework)
- `tests/impl/` — test helpers (MongoDB resolver, app bootstrap, server harnesses)
- `tests/benchmarks/` — performance benchmarks comparing async vs native, grouped
  by the technology they measure: `mongodb/`, `mysql/`, `pgsql/`, `http/`,
  `socket/`, `ws/`, `amqp/` (each holds its per-operation benches plus, for the protocols,
  the server benches and the load scripts), `db/` (a whole DB session: repeated
  runs and their aggregation into the markdown rows of `docs/benchmarks.md`),
  `runtime/` (scheduler and PHP↔Go boundary, no backend involved) and `lib/`
  (the shared harness the benches include). A new bench goes into its
  technology's directory, named after the operation (`mysql/select-one.php`), and
  gets a `bench-<tech>-<operation>` make target.
- `tests/consumers/` — demo/test worker scripts that are not servers (the AMQP
  consumer), the counterpart of `tests/servers/`
- `tests/mem-leak/` — memory leak stress tests. The AMQP soak has a target of its
  own, `make mem-leak-amqp scenario=<name> seconds=<n>`, which sets the profiler
  address its Go-side columns are read from, and reports the broker's own connections,
  channels and consumers beside them — a worker flat on its own memory can still leave
  sockets behind on the other side. Two scenarios cover `QueueConsumer` and
  `PublishChannelPool`: `consumer` takes a handler through all twelve of its endings
  (settled by the runtime, settled by the handler, refused, thrown, cut by a deadline,
  a channel given back clean, dirty and dead), and `consumer-lost` takes the ground
  away — the publish connection closed from the broker, the queue deleted under a
  running consumer. A second publish socket appearing and being reaped again is that
  pool recovering, not a leak: it carries no channels and the Go side closes it after
  five idle minutes

Tests use PHPUnit 11. Add feature tests in `tests/feature/...` with `*Test.php`
suffixes; async flow tests commonly extend `BaseAsyncTestCase`,
lifecycle-sensitive tests extend `BaseTestCase`.

## Code style

- PHP 8.4, PSR-12 plus repository-specific `php-cs-fixer` rules from
  `cs-fixer.dist.php`; PHPStan level 6
- Aligned assignments; 4 spaces, LF line endings, ~120 column guide from
  `.editorconfig`
- `readonly` classes for DTOs; namespaces mirror directory paths; namespace
  `SConcur\` → `src/`, test namespaces `SConcur\Tests\Feature\`,
  `SConcur\Tests\Impl\`
- Classes PascalCase; methods and properties camelCase; all traits carry the
  `*Trait` postfix, so a `use` line is recognizable at a glance
- Code must be maximally typed (parameters, return types, properties)
- Prefer short arrays (`[]`)
- Do **not** use `final` on classes
- Class properties (including promoted constructor properties) must be
  `protected`, never `private`; use `public` only for DTO fields read externally
- **Never write a leading `\` on a class name** — import it with `use` and refer
  to the short name. `use Stringable;` then `implements Stringable`, not
  `implements \Stringable`; the same for `\DateTimeImmutable`, `\Throwable`,
  `\RuntimeException` and every other global class. Imported function names
  (`use function msgpack_pack;`) follow the same rule. This keeps the imports at
  the top of a file an honest inventory of what it depends on.

### Language

**English everywhere except the Russian half of the docs.** Russian belongs in
exactly two places: the `*.ru.md` pair files (`README.ru.md`, `docs/*.ru.md`),
where it mirrors the English original, and `.ai/plans/`, which is written in
Russian by a maintainer decision.

Everything else is English, with no exceptions: code and its comments, PHPDoc,
Go comments, exception and log messages, test names and failure messages, shell
and benchmark scripts including everything they print, table headers and verdict
strings in their output, and commit messages. A benchmark whose table headers or verdict strings come out
in Russian is a bug, not a style preference — that output is read by contributors
who do not speak Russian, and it ends up pasted into issues and docs.

### Naming

- Never abbreviate variable names — `$exception`, not `$e`; `$request`, not
  `$req`.
- A variable holding a class instance is named exactly after that class, in
  lowerCamelCase: `CreateBookingHotelAction` → `$createBookingHotelAction`.
- **A property, parameter or constant holding a measured quantity must carry its
  unit in the name**, so the unit is unambiguous at every call site:
  `filesizeBytes`, `bufferSizeBytes`, `maxResponseBodyBytes`, `timeoutMs`,
  `executionMs`, `intervalSeconds`. Applies to new and changed code (do not
  mass-retrofit unrelated areas). Codes and identifiers that are not a measured
  quantity (`statusCode`, `sinkMode`) are exempt.

### Formatting

- Separate every `{}` block with blank lines, and separate logical blocks inside a
  method with a blank line — group variable declarations, then method calls, then
  the return.
- Always name method parameters meaningfully, especially with more than one.
- Use **named arguments** when calling a project method or constructor that has
  more than one parameter, or at least one optional parameter. Built-in PHP
  functions are exempt. A call to a single required-only parameter may stay
  positional and inline.
- When a call uses named arguments, lay them out vertically — one argument per
  line, with a trailing comma:
  ```php
  $response = new NetworkException(
      request: $request,
      message: $message,
      previous: $exception,
  );
  ```
- A call is formatted uniformly: either all arguments on one line, or every
  argument on its own line. Mixed style is forbidden — if a nested call has its
  arguments expanded vertically, the outer call's first argument must also start
  on a new line.
- A signature need not be vertical if the line stays within 120 characters;
  otherwise format it vertically. If any single parameter name is longer than ~20
  characters, format the signature vertically even with one parameter.
- Arrays with two or more elements: one element per line, trailing comma. Empty
  `[]` and a trivial single-element array may stay inline.
- In conditions that mix `&&` and `||`, and in ternaries, wrap condition groups in
  parentheses. Simple same-operator conditions need no extra parentheses.

## Exceptions

**No `@throws` anywhere.** Every exception here descends from `RuntimeException` or
`LogicException`, both unchecked by PHP convention, so a tag adds nothing a reader can
act on — and a partial list is worse than none: PHPStan reads `@throws` as exhaustive
and kills the `catch` blocks for whatever the list left out. The public API does not
advertise concrete throwables; any caught `Throwable` is wrapped before re-throwing, and
what a call can fail with belongs in prose, in the docblock or in `docs/`.

- **Never `throw` a built-in exception directly** (`RuntimeException`,
  `LogicException`, `DomainException`, …). Always throw a custom exception from
  `SConcur\Exceptions\` named for the case.
- Custom exceptions extend a built-in base by nature: **`RuntimeException`** for
  runtime failures (`TaskExecutionException`, `CallbackExecutionException`,
  `ExtensionNotLoadedException`, `Mongodb\InvalidCountResultException`),
  **`LogicException`** for invariant/usage bugs (`OutsideFiberException`,
  `UnexpectedTaskKeyException`, `UnexpectedResultTypeException`,
  `FiberStateException`). So `catch (RuntimeException)` still works while the
  concrete type stays catchable.
- When wrapping a caught `Throwable`, keep it as `previous`. A `Throwable` from
  `Fiber::suspend()`/`Fiber::resume()` is wrapped the same way (see
  `FeatureExecutor::suspend`, `Scheduler::awaitGroup`); a deliberate unwind signal
  (`FlowStoppedException`) is re-thrown as-is.
- A task error from Go surfaces as `TaskErrorException`; on the async path it is
  wrapped in `CallbackExecutionException` (original reachable via
  `getPrevious()`).

## Documentation style

User-facing docs are the `README.md` / `docs/*.md` pair set, maintained in two
languages. They were deliberately reworked to not read as AI-generated. Rules:

- **Verify every technical claim against the code before writing it** — class and
  method names/signatures, option names and defaults, enum cases, CLI flags, file
  paths, behavioral claims. Fix inaccuracies; never guess.
- **Dry and compact.** Short sentences, no marketing metaphors, no long
  reasoning around a fact that a table or one line already states. Do not
  re-narrate in prose what a parameter table says.
- **Minimal bold.** Use `**bold**` only for a genuinely critical warning or a
  couple of key terms — heavy bolding is the top "AI-generated" tell.
- **No duplication across docs.** The general limits (CLI only, Linux only, NTS
  only, no `pcntl_fork`) live in the README, and feature docs link to it;
  `SO_REUSEPORT` is canonical in `docs/http-server.md`, and the other servers
  reference it and describe only their delta.
- **Do not put source line numbers in docs** — they go stale. Reference file paths
  only.
- **No unexplained jargon.** Say what happens in plain words instead of a term the
  reader has to decode: "runs N operations at the same time" not "fan-out"
  (Russian: «одновременно», never «веер»); "endpoint" not "handle" («эндпоинт», not
  «ручка»); "waits until the bytes are flushed, so a fast writer cannot outrun the
  client" not "backpressure"; "finishes the requests already accepted" not "drains
  in-flight". Project terms that do have a definition (`flow`/«флоу», coroutine,
  fiber, feature) are fine, but define them at first use in a doc.
- **Every table cell must be self-contained.** A verdict or comparison cell states
  what was measured and against what, so a reader who jumps straight to the table
  is not left guessing (see `docs/positioning.md`).
- **Diagrams in Mermaid** (GitHub renders them). To keep them rendering
  everywhere, including PhpStorm:
  - No `<br/>` anywhere — some renderers print it literally. Use single-line node
    labels (combine ideas with ` — ` or `(...)`); in `sequenceDiagram` use
    separate `Note over` lines.
  - For a request+response between two components use one bidirectional edge
    `A <-->|"..."| B`, never two opposing edges — a 2-cycle makes the layout
    engine place the blocks side-by-side or reversed.
  - In `flowchart TB` declare the caller first so it renders on top.
  - Label edges with the real call/method names from the code.
- **README is a short visitor card**: what it is, for whom, key limits, a quick
  example, links to `docs/`. Deep internals live under `docs/`.

### Bilingual docs

All user-facing docs are kept in two languages, always in sync. The default
language is English.

- **Naming.** English is the base filename (`README.md`, `docs/cli.md`); Russian
  is the same name with a `.ru` infix (`README.ru.md`, `docs/cli.ru.md`). Every
  doc has both files.
- **Edit one → update the other.** Any change (new section, a fix, a reworded
  paragraph) must be mirrored into the other language in the same commit. The two
  versions never drift; when you fix a factual inaccuracy, fix it in both.
- **Language switcher header.** The first line of every doc is a one-line
  switcher: the current language as plain text, the other as a link to its pair.
  English file: `English | [Русский](<name>.ru.md)`; Russian file:
  `[English](<name>.md) | Русский`.
- **Internal links follow the file's language** — English docs link to `*.md`,
  Russian docs link to `*.ru.md`. English-context references (this file,
  `AGENTS.md`, `CLAUDE.md`, source comments) link to the English `docs/*.md`.

## Extension versioning

**All five version sources must be equal** — bump them together, in the same
commit:

1. `ext/src/lib.rs` → `VERSION` (what the released core reports)
2. `ext/Cargo.toml` → `version` (the crate, kept in step with it)
3. `ext-go-legacy/main.go` → `version()` (nothing enforces this one, but a run
   pointed at the reference core reads its version like any other, so it is kept
   in step rather than left to fail)
4. `src/Connection/Extension.php` → `REQUIRED_EXTENSION_VERSION`
5. `composer.json` → `"version"`

They are bumped on any PHP↔extension protocol change. **Never bump the major
version without the maintainer's approval**; bump the minor only when warranted,
otherwise the patch. **Bump at most once per git branch** — the first protocol
change on a branch bumps it, later commits on the same branch reuse that version.

The release CI derives the release tag from the extension version (via
`bin/sconcur-status`), so a drift between these would ship a mislabeled release.
`tests/feature/Connection/VersionConsistencyTest.php` enforces the equality
against whichever core the run loaded.

## Workflow rules

- Always wait for explicit user approval before committing or pushing, and always
  propose a commit message before committing.
- Never create a git branch without an explicit, direct instruction from the user.
  Work on and commit to the current branch (normally `master`).
- Before implementing any task, propose a plan and wait for explicit user
  approval.
- After any PHP changes, run analyzers (`make php-stan`, `make cs-fixer-check`)
  and tests (`make test`). Fix any errors automatically without asking.

## Answering & code references

When referring to any class, method, or code fragment in a reply, always give the
full path from the project root plus the line number, so the reference is
clickable and jumps straight to the spot in the IDE: whole file
`app/.../MasterWorkerManager.php`, specific spot
`app/.../MasterWorkerManager.php:16`. The line number is required when pointing at
concrete logic; it may be omitted only when referring to a file as a whole. (This
applies to replies — docs carry no line numbers, see "Documentation style".)

## Commit & pull request guidelines

Use short, imperative subjects (`update mongodb serializer`, `remove obsolete
handler tests`). Pull requests should explain the behavioral change, list
validation performed (`make check`, targeted tests, benchmarks if relevant), and
link the related issue or task. Screenshots are usually unnecessary unless
documentation or tooling output changed materially.

When an AI agent creates a git commit itself, it must add a sign-off trailer
identifying the agent:

```
Co-Authored-By: <agent name> <email>
```

**The name must carry the model version the commit was actually written by** —
read it from the running session (for Claude Code: the model reported in the
environment, e.g. `Claude Opus 5 (1M context)`), never copy the version from an
example or from an earlier commit. The trailer is how a later reader knows which
model produced the change, so a stale version in it is misinformation.

The format, with the version standing in for whatever is current:
`Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>` for Claude
Code, `Co-Authored-By: OpenAI Codex <noreply@openai.com>` for OpenAI Codex.
