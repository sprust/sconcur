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
modules in `src/Features/`. The Go extension lives in `ext/`. Tests: feature and
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
  [docs/coroutine-switching.md](../docs/coroutine-switching.md) — per-coroutine
  context; `Scheduler::switch()` and automatic preemption
- Features: [mongodb](../docs/mongodb.md), [mysql](../docs/mysql.md),
  [pgsql](../docs/pgsql.md), [http-server](../docs/http-server.md),
  [http-client](../docs/http-client.md),
  [socket-server](../docs/socket-server.md),
  [socket-client](../docs/socket-client.md),
  [websocket-server](../docs/websocket-server.md),
  [websocket-client](../docs/websocket-client.md)
- Operations: [worker-master](../docs/worker-master.md),
  [admin-stats](../docs/admin-stats.md)
- Guides: [adding-a-feature](../docs/adding-a-feature.md),
  [adding-a-server](../docs/adding-a-server.md)
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
make ext-build          # compile Go extension → ext/build/sconcur.so
make ext-test           # run Go tests
make test               # run all PHPUnit tests (loads the sconcur extension)
make test c="--filter=SleeperTest"  # run a specific test
make php-stan           # PHPStan level 6
make cs-fixer-check     # check code style (PSR-12 + custom rules)
make cs-fixer-fix       # auto-fix code style
make check              # cs-fixer, phpstan, tests, ext-test
make bench-all          # run all benchmarks
```

Rebuild the extension with `make ext-build` before running tests that depend on
`ext/build/sconcur.so`. Use `make ext-test` when changing Go extension behavior.

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
- `Scheduler/Coroutine` — a tracked fiber: id, fiber, owning group, callback key.
- `State` — the static registry mapping Fibers ↔ flows ↔ tasks, plus the
  per-coroutine context store (released in `unRegisterFiber`).
- `Context/Context`, `Context/CoroutineContext` — the framework-neutral
  `find`/`has`/`set`/`forget` contract; parent links are recorded in
  `Scheduler::spawn` / `WaitGroup::add`.
- `Features/FeatureExecutor` — coordinates feature execution, detects the async
  context via `Fiber::getCurrent()`.
- `Features/Server/ServerRuntimeSupportTrait` — shared server runtime glue:
  argv→constructor-override parsing, signal handlers, the orphaned-worker check,
  telemetry env.
- `Features/Socket/Dto/AbstractConnection` — shared base for the socket and
  WebSocket `Connection` DTOs (server accept-side and client dial-side); keeps the
  features decoupled, since all depend on the neutral base rather than each other.
- `Worker/` — the worker master (a process supervisor that does NOT load the
  extension): `WorkerMaster`, `MasterConfig`, `MasterCli`, `WorkerProcess`, `Cpu`,
  `MasterLock`, `MasterState`/`MasterStateFile`, `MasterLogger`, `RestartPolicy`.
- `Telemetry/` — the master-side stats collector and live panel (pure PHP):
  `TelemetryRuntime`, `Collector`, `Store`, `PanelServer`, `FrameCodec`,
  `Aggregator`, `Dto/*`, `Render/*`.

Go extension (`ext/`):

- `main.go` — cgo exports (`ping`, `push`, `wait`, `next`, `waitAny`,
  `waitAnyTimeout`, `waitAnyBatch`, `waitAnyTimeoutBatch`, `tasksCount`,
  `stopFlow`, `httpStopAccepting`, `socketStopAccepting`, `wsStopAccepting`,
  `preemptionArm`, `preemptionDisarm`, `destroy`, `version`)
- `internal/handler/` — singleton orchestrator routing messages to flows
- `internal/flows/`, `internal/tasks/` — concurrent `Flow` instances holding tasks
  and a result channel; individual task units with context cancellation
- `internal/states/` — registry of streaming states (cursor batches, HTTP
  requests, request-body chunks) driven by `next()`
- `internal/logger/` — fire-and-forget async log sink: a background goroutine
  writes pre-formatted lines to stdout (buffered, timer-flushed, drops on
  overflow), so the loop never blocks on log I/O
- `internal/features/*` — sleeper, mongodb, sql (one handler dispatching
  Query/Exec/Begin/Commit/Rollback; the driver is selected per `Method`),
  httpserver, httpclient, socketserver, socketclient, wsserver, wsclient
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
  WsClient (`wsc`)
- Sub-operations selected via the payload envelope's `cm`:
  `SocketClientCommand`/`WsClientCommand` (Connect `con`, Send `snd`, Close
  `cls`), `SqlCommandEnum` (Query `qry`, Exec `exe`, Begin `beg`, Commit `cmt`,
  Rollback `rlb`), `HttpClientCommand` (Request `req`, UploadChunk `upc`,
  UploadEnd `upe`), MongoDB's `CommandEnum` (InsertOne `ino`, BulkWrite `bw`,
  Aggregate `agg`, … — see `src/Features/Mongodb/CommandEnum.php`)
- `DownloadFileMode` (HttpClient download sink, the `sm` field): Replace (`rpl`),
  Create (`crt`), Append (`app`)

## Tests

- `tests/feature/` — PHPUnit feature tests with `BaseTestCase` (extension
  lifecycle) and `BaseAsyncTestCase` (async event ordering framework)
- `tests/impl/` — test helpers (MongoDB resolver, app bootstrap, server harnesses)
- `tests/benchmarks/` — performance benchmarks comparing async vs native
- `tests/mem-leak/` — memory leak stress tests

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

Callable signatures stay clean of `@throws` noise, so the public API does not
advertise concrete throwables — any caught `Throwable` is wrapped before
re-throwing.

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

**All three version sources must be equal** — bump them together, in the same
commit:

1. `ext/main.go` → `version()` (the Go extension's reported version)
2. `src/Connection/Extension.php` → `REQUIRED_EXTENSION_VERSION`
3. `composer.json` → `"version"`

They are bumped on any PHP↔Go protocol change. **Never bump the major version
without the maintainer's approval**; bump the minor only when warranted, otherwise
the patch. **Bump at most once per git branch** — the first protocol change on a
branch bumps it, later commits on the same branch reuse that version.

The release CI derives the release tag from the extension version (via
`bin/sconcur-status`), so a drift between these would ship a mislabeled release.
`tests/feature/Connection/VersionConsistencyTest.php` enforces the equality and
fails the build if any of the three diverges.

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

For example, Claude Code uses
`Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`; OpenAI
Codex uses `Co-Authored-By: OpenAI Codex <noreply@openai.com>`.
