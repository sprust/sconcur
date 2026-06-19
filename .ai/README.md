# SConcur — Agent & Contributor Guide

This is the single source of truth for AI agents (Claude Code, etc.) and human
contributors working in this repository. `CLAUDE.md` and `AGENTS.md` both point
here.

## Further Reading

- [README.md](../README.md) — project overview and usage
- [docs/adding-a-feature.ru.md](../docs/adding-a-feature.ru.md) — guide for adding a new feature
- [docs/mongodb.ru.md](../docs/mongodb.ru.md) — MongoDB feature: collection operations, cursors, BSON types, concurrency, internals
- [docs/http-server.ru.md](../docs/http-server.ru.md) — HTTP-server feature: usage, params, internals, limits
- [docs/worker-master.ru.md](../docs/worker-master.ru.md) — worker master: CLI start/status/stop, restart policy, logging, single-instance, orphan self-termination
- [docs/http-client.ru.md](../docs/http-client.ru.md) — HTTP-client feature (PSR-18): usage, options, streaming, internals
- [docs/mysql.ru.md](../docs/mysql.ru.md) — MySQL / universal SQL feature: usage, bindings, transactions, streaming, internals
- [docs/pgsql.ru.md](../docs/pgsql.ru.md) — PostgreSQL: the SQL feature's second driver; PG-specific differences
- [.ai/plans/](plans/) — detailed designs for roadmap items

## Plans

The README keeps only a short, one-line-per-item roadmap. Detailed designs for
roadmap items live in `.ai/plans/` — one Markdown file per plan. When a roadmap
item grows beyond a sentence (mechanics, API sketch, trade-offs, open
questions), put the detail in a `.ai/plans/<kebab-name>.md` file and link it
from the README's `## Планы` bullet instead of inlining it.

## Project

SConcur is a PHP concurrency library backed by a custom Go-based PHP extension.
PHP Fibers suspend while the Go extension executes tasks (MongoDB operations,
sleep) concurrently via goroutines. Communication between PHP and Go is
JSON-based.

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
- `State` — static registry mapping Fibers ↔ flows ↔ tasks
- `Connection/Extension` — singleton wrapping Go extension's exported C functions (`push`, `wait`, `waitAny`, `next`, `stopFlow`, etc.)
- `Features/FeatureExecutor` — coordinates feature execution, detects async context via `Fiber::getCurrent()`
- `Features/Mongodb/Connection/{Client,Database,Collection}` — MongoDB operations (insert, update, delete, find, aggregate, indexes, bulk write)
- `Features/Sleeper/Sleeper` — async sleep
- `Features/Mongodb/Serialization/DocumentSerializer` — encodes/decodes raw BSON via `ext-mongodb` (`MongoDB\BSON\Document`); values are native `MongoDB\BSON\*` types
- `Features/HttpServer/` — long-lived HTTP server: `HttpServer::serve()`, `HttpServer::fromArgs()` (build from argv), `Scheduler::serve()`, DTOs (`Request`/`RequestBody`/`Response`/`StreamedResponse`/`ResponseStream`). A built-in access log line per request goes to STDOUT. See [docs/http-server.ru.md](../docs/http-server.ru.md).
- `Features/HttpClient/` — async PSR-18 HTTP client with response streaming: `HttpClient` (`ClientInterface`), `HttpClientOptions`, `Payloads/RequestPayload`, `Dto/ResponseBodyStream` (`StreamInterface`). `HttpClient::download()` writes the response body straight to a file on the Go side (`DownloadFileMode`, `Dto/DownloadResult`, `DownloadException`) — never crossing into PHP. See [docs/http-client.ru.md](../docs/http-client.ru.md).
- `Features/Sql/` — universal SQL feature (driver-agnostic core on Go `database/sql`): `Connection` (`query`/`fetchAll`/`exec`/`begin`), `Transaction`, `Results/{RowsResult,ExecResult}`, command-envelope payloads. `Features/Mysql/Connection` and `Features/Pgsql/Connection` are thin driver facades supplying `MethodEnum::Mysql` / `MethodEnum::Pgsql`. See [docs/mysql.ru.md](../docs/mysql.ru.md) and [docs/pgsql.ru.md](../docs/pgsql.ru.md).
- `Worker/` — worker master (a process supervisor; does NOT load the extension): `WorkerMaster` (`run()`: spawn/supervise/restart/graceful), `MasterConfig` (loads the `--configPath` JSON, expands the `server` block into worker argv), `MasterCli` (`start`/`status`/`stop` behind `bin/sconcur-http-server`), `WorkerProcess` (proc_open + output capture), `Cpu`, `MasterLock` (flock single-instance), `MasterState`/`MasterStateFile`, `MasterLogger` (daily rotation), `RestartPolicy`. The master injects its pid as `--masterPid`, which `HttpServer::fromArgs()` wires into the orphan check. See [docs/worker-master.ru.md](../docs/worker-master.ru.md).

**Go extension** (`ext/`):
- `main.go` — cgo exports (`push`, `wait`, `next`, `waitAny`, `waitAnyTimeout`, `tasksCount`, `stopFlow`, `httpStopAccepting`, `logLine`, `destroy`, `version`)
- `internal/handler/` — singleton orchestrator routing messages to flows
- `internal/logger/` — fire-and-forget async log sink: a background goroutine writes pre-formatted lines to stdout (buffered, timer-flushed, drops on overflow), so the loop never blocks on log I/O. The HttpServer access log feeds it directly from the Go response goroutine (no PHP↔Go crossing per request); PHP can also push lines via the `logLine` cgo export
- `internal/flows/` — `Flows` manages concurrent `Flow` instances; each `Flow` holds tasks and a result channel
- `internal/tasks/` — individual task unit with context cancellation
- `internal/states/` — registry of streaming states (cursor batches, HTTP requests, request-body chunks) driven by `next()`
- `internal/features/sleeper/` — goroutine-based sleep
- `internal/features/mongodb/` — MongoDB operations via Go driver, with aggregation cursor state management
- `internal/features/httpserver/` — `net/http.Server` as an http.Handler streaming each request to PHP; response write-commands, request-body streaming, concurrency limit, timeouts, graceful shutdown, SO_REUSEPORT
- `internal/features/sql/` — driver-agnostic SQL on `database/sql`: one handler dispatches Query/Exec/Begin/Commit/Rollback by the envelope's command; `pools.go` is the `*sql.DB` pool registry (mirrors MongoDB clients), `rows_state.go` streams a SELECT cursor, `transactions.go` pins a `*sql.Tx` to a held begin task (auto-rollback on context cancel). The driver is selected per `Method`: `GetMysql()` registers go-sql-driver/mysql, `GetPgsql()` registers jackc/pgx (error label "pgsql").
- `internal/features/httpclient/` — `net/http.Client` sending one request as a streaming state: first result carries response metadata + inline first chunk, subsequent results are raw body chunks; reusable transports (keep-alive pool), per-request deadline; optional streamed request body (upload) via an `io.Pipe` fed by `UploadChunk`/`UploadEnd` commands. Sub-operations are selected by a command in the payload envelope (`HttpClientCommand`), like MongoDB — not by separate `MethodEnum` values. `download.go` is the sink path: when the request carries `SinkPath`, the response body is `io.Copy`'d straight into a file (mode→`os.O_*` via `downloadModeToFlags`) and only status+headers return to PHP — the body never crosses the boundary
- `internal/helpers/` — small shared helpers: `CalcExecutionMs`, and `ReadChunk` (fixed-granularity body chunk reader used by both the HTTP server and client)

**Key enums:**
- `MethodEnum`: Sleep (1), MongodbCollection (2), HttpServe (3), HttpRespond (4), HttpClient (5), Mysql (6), Pgsql (7)
- `SqlCommandEnum` (sub-operations under a SQL method, selected via the envelope's `cm`): Query (1), Exec (2), Begin (3), Commit (4), Rollback (5)
- `HttpClientCommand` (sub-operations under HttpClient): Request (1), UploadChunk (2), UploadEnd (3) — selected via the payload envelope's `cm`, like MongoDB's `CommandEnum`
- `CommandEnum`: InsertOne (1), BulkWrite (2), Aggregate (3), InsertMany (4), CountDocuments (5), UpdateOne (6), FindOne (7), CreateIndex (8), DeleteOne (9), DeleteMany (10), UpdateMany (11), Drop (12), DropIndex (13)

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

## Extension versioning

The Go extension version lives in `ext/main.go` (`version()`) and the minimum
required version in `src/Connection/Extension.php`
(`REQUIRED_EXTENSION_VERSION`); they are bumped together on any PHP↔Go protocol
change. **Never bump the major version without the maintainer's approval**; bump
the minor only when warranted, otherwise the patch. **Bump the version at most
once per git branch** — the first protocol change on a branch bumps it, later
commits on the same branch reuse that version (do not move it again). Current:
`0.2.1`.

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
