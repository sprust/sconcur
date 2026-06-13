# SConcur — Agent & Contributor Guide

This is the single source of truth for AI agents (Claude Code, etc.) and human
contributors working in this repository. `CLAUDE.md` and `AGENTS.md` both point
here.

## Further Reading

- [README.md](../README.md) — project overview and usage
- [docs/adding-a-feature.ru.md](../docs/adding-a-feature.ru.md) — guide for adding a new feature
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
- `Features/Mongodb/Serialization/DocumentSerializer` — handles MongoDB Extended JSON (`$oid`, `$date`, `$numberLong`, etc.)

**Go extension** (`ext/`):
- `main.go` — cgo exports (`push`, `wait`, `next`, `count`, `stopFlow`, `destroy`)
- `internal/handler/` — singleton orchestrator routing messages to flows
- `internal/flows/` — `Flows` manages concurrent `Flow` instances; each `Flow` holds tasks and a result channel
- `internal/tasks/` — individual task unit with context cancellation
- `internal/features/sleep/` — goroutine-based sleep
- `internal/features/mongodb/` — MongoDB operations via Go driver, with aggregation cursor state management

**Key enums:**
- `MethodEnum`: Sleep (1), MongodbCollection (2)
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

## Commit & Pull Request Guidelines

Use short, imperative subjects (e.g. `update mongodb serializer`,
`remove obsolete handler tests`); keep commit titles concise and descriptive.
Pull requests should explain the behavioral change, list validation performed
(`make check`, targeted tests, benchmarks if relevant), and link the related
issue or task. Screenshots are usually unnecessary unless documentation or
tooling output changed materially.
