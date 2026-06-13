# SConcur — Agent & Contributor Guide

This is the single source of truth for AI agents (Claude Code, etc.) and human
contributors working in this repository. `CLAUDE.md` and `AGENTS.md` both point
here.

## Further Reading

- [README.md](../README.md) — project overview and usage
- [docs/adding-a-feature.ru.md](../docs/adding-a-feature.ru.md) — guide for adding a new feature

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
executes → result sent via channel → `Extension::wait()` retrieves → Fiber
resumes → `WaitGroup::iterate()` yields result.

**PHP layer** (`src/`):
- `WaitGroup` — main API: `add()`, `iterate()`, `waitAll()`, `waitResults()`
- `State` — static registry mapping Fibers ↔ flows ↔ tasks
- `Connection/Extension` — singleton wrapping Go extension's exported C functions (`push`, `wait`, `next`, `stopFlow`, etc.)
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
