# Repository Guidelines

## Project Structure & Module Organization
Core PHP code lives in `src/` under the `SConcur\\` namespace. The main entry points are `WaitGroup`, `State`, `Connection/Extension`, and feature modules in `src/Features/` such as `Mongodb` and `Sleeper`. The custom Go-backed PHP extension lives in `ext/`. Feature and integration coverage lives in `tests/feature/`, shared test helpers in `tests/impl/`, benchmarks in `tests/benchmarks/`, and stress checks in `tests/mem-leak/`. Container and release build assets are under `docker/` and `docker-compose.yml`.

## Build, Test, and Development Commands
Use Docker-based Make targets for all local work:

- `make env-copy` copies `.env.example` to `.env`.
- `make build && make up` builds and starts the development containers.
- `make ext-build` compiles `ext/build/sconcur.so`.
- `make test` runs PHPUnit with the compiled extension loaded.
- `make test c="--filter=SleeperTest"` runs a focused test selection.
- `make php-stan` runs static analysis.
- `make cs-fixer-check` verifies formatting; `make cs-fixer-fix` applies fixes.
- `make ext-test` runs extension-side tests.
- `make check` runs the full quality gate.

## Coding Style & Naming Conventions
Target PHP `8.4` and follow PSR-12 plus repository-specific `php-cs-fixer` rules from `cs-fixer.dist.php`. Use 4 spaces, LF line endings, and keep lines near the `120` column guide from `.editorconfig`. Classes use PascalCase, methods and properties use camelCase, and namespaces mirror directory paths, for example `SConcur\\Features\\Sleeper\\Sleeper`. Prefer explicit typing, descriptive variable names, and short arrays (`[]`).

## Testing Guidelines
Tests use PHPUnit 11. Add feature tests in `tests/feature/...` with `*Test.php` suffixes. Async flow tests commonly extend `BaseAsyncTestCase`; lifecycle-sensitive tests extend `BaseTestCase`. Rebuild the extension with `make ext-build` before running tests that depend on `ext/build/sconcur.so`. Run `make test`, and use `make ext-test` when changing Go extension behavior.

## Commit & Pull Request Guidelines
Recent history uses short, imperative subjects such as `update mongodb serializer` and `remove obsolete handler tests`; keep commit titles concise and descriptive. Pull requests should explain the behavioral change, list validation performed (`make check`, targeted tests, benchmarks if relevant), and link the related issue or task. Screenshots are usually unnecessary unless documentation or tooling output changed materially.
