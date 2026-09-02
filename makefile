MAKEFLAGS += --no-print-directory

DOCKER_COMPOSE = docker compose
PHP_CLI = $(DOCKER_COMPOSE) exec php
# The extension every benchmark and script loads. Overridable so the same targets
# can be pointed at an alternative build without a second copy of each one:
#   make bench-mongodb-aggregate SCONCUR_EXT=./ext-rust/build/sconcur.so
SCONCUR_EXT ?= ./ext/build/sconcur.so

# Exported so the targets that run a script on the host (the load benchmarks)
# pass the same choice down instead of each recipe repeating it.
export SCONCUR_EXT

PHP_EXT = $(PHP_CLI) php -d extension=$(SCONCUR_EXT)

# Master control inside the `servers` container: two masters run there under
# supervisor. One holds the three servers as a group each, the other the RabbitMQ
# consumers; a command names the master by its config and, for a single pool, the
# group by --group.
SERVERS_CLI = $(DOCKER_COMPOSE) exec servers php /sconcur/bin/sconcur-server
SERVERS_CONFIG = /sconcur/config/sconcur.servers.config.json
RABBITMQ_CONFIG = /sconcur/config/sconcur.rabbitmq.config.json

env-copy:
	cp -i .env.example .env

build:
	$(DOCKER_COMPOSE) build

setup:
	make stop
	make build
	make up
	make composer c=i
	make ext-build

up:
	$(DOCKER_COMPOSE) up -d --wait

stop:
	$(DOCKER_COMPOSE) stop --timeout=3

down:
	$(DOCKER_COMPOSE) down --timeout=3

restart:
	make stop
	make up

# Rebuilds the extension and recreates the `servers` container (both master
# servers under supervisor).
servers-restart:
	make ext-build
	$(DOCKER_COMPOSE) up -d --build --force-recreate servers

servers-status:
	$(SERVERS_CLI) status --configPath=$(SERVERS_CONFIG)

servers-stop:
	$(SERVERS_CLI) stop --configPath=$(SERVERS_CONFIG)

# Rolls every pool of the servers master. One pool alone: make http-server-reload.
servers-reload:
	$(SERVERS_CLI) reload --configPath=$(SERVERS_CONFIG)

http-server-status:
	$(SERVERS_CLI) status --configPath=$(SERVERS_CONFIG) --group=http

http-server-reload:
	$(SERVERS_CLI) reload --configPath=$(SERVERS_CONFIG) --group=http

socket-server-status:
	$(SERVERS_CLI) status --configPath=$(SERVERS_CONFIG) --group=socket

socket-server-reload:
	$(SERVERS_CLI) reload --configPath=$(SERVERS_CONFIG) --group=socket

ws-server-status:
	$(SERVERS_CLI) status --configPath=$(SERVERS_CONFIG) --group=ws

ws-server-reload:
	$(SERVERS_CLI) reload --configPath=$(SERVERS_CONFIG) --group=ws

# The RabbitMQ consumers are their own master, started with the container. This brings
# it back after `make rabbitmq-stop`.
rabbitmq-start:
	$(DOCKER_COMPOSE) exec servers supervisorctl -c /sconcur/docker/servers/config/supervisord.conf start rabbitmq

rabbitmq-status:
	$(SERVERS_CLI) status --configPath=$(RABBITMQ_CONFIG)

rabbitmq-stop:
	$(SERVERS_CLI) stop --configPath=$(RABBITMQ_CONFIG)

rabbitmq-reload:
	$(SERVERS_CLI) reload --configPath=$(RABBITMQ_CONFIG)

bash-php:
	$(DOCKER_COMPOSE) exec php bash

bash-php-remote:
	$(DOCKER_COMPOSE) run -it --rm php bash

composer:
	$(PHP_CLI) composer ${c}

php-stan:
	$(PHP_CLI) ./vendor/bin/phpstan analyse \
		--memory-limit=1G

cs-fixer-check:
	$(PHP_CLI) ./vendor/bin/php-cs-fixer fix --config cs-fixer.dist.php --dry-run --diff --verbose

cs-fixer-fix:
	$(PHP_CLI) ./vendor/bin/php-cs-fixer fix --config cs-fixer.dist.php --verbose

check:
	make cs-fixer-check
	make php-stan
	make test
	make ext-test

status:
	$(PHP_EXT) bin/sconcur-status ${c}

# --log-junit persists the failing test's name for the rare flaky failure that
# only fires on the first run after heavy host activity — see
# .ai/plans/flaky-test-hunt.ru.md. A failed run's report is copied to
# .phpunit-failed/ (gitignored, survives container restarts) with a timestamped
# name, so a one-off failure is never lost to the next run overwriting
# /tmp/sconcur-phpunit.xml. The stale report is removed up front: phpunit
# writes the XML at the end of the run, so a run that dies before that
# (a native segfault) would otherwise preserve the PREVIOUS run's report
# under a fresh timestamp — evidence pointing at the wrong test.
test:
	$(PHP_CLI) sh -c 'rm -f /tmp/sconcur-phpunit.xml'
	$(PHP_EXT) vendor/bin/phpunit \
		-d memory_limit=512M \
		--log-junit /tmp/sconcur-phpunit.xml \
		--colors=auto \
		--testdox \
		--display-incomplete \
		--display-skipped \
		--display-deprecations \
		--display-phpunit-deprecations \
		--display-errors \
		--display-notices \
		--display-warnings \
		tests ${c} || ( \
			$(PHP_CLI) sh -c 'mkdir -p .phpunit-failed && cp /tmp/sconcur-phpunit.xml .phpunit-failed/sconcur-phpunit-$$(date +%Y%m%d-%H%M%S).xml'; \
			exit 1 \
		)

ext-build:
	$(PHP_CLI) sh ./ext-build.sh

ext-test:
	$(PHP_CLI) sh ./ext-test.sh

# --- Rust core spike (branch spike/rust-core) -------------------------------
# An alternative build of the extension core in Rust: the C exports, the shared
# results channel, the sleeper feature and the HTTP server rungs the attribution
# ladder needs. Not part of `make check` — it answers one question (is the L0
# floor and the boundary cheaper in another runtime) and is thrown away or
# promoted on the answer. See .ai/plans/rust-core-spike.md.

# Compiles ext-rust into ext-rust/build/sconcur.so, a drop-in for the Go build.
ext-rust-build:
	$(PHP_CLI) sh -c 'cd /sconcur/ext-rust && CARGO_TARGET_DIR=/sconcur/ext-rust/target sh ./build.sh'

# Exercises the spike core through the unmodified PHP package: the shared
# channel, the error path, flow teardown, the sync wait.
ext-rust-check:
	$(PHP_CLI) php -d extension=/sconcur/ext-rust/build/sconcur.so ext-rust/check/core-smoke.php

# Runs on the HOST (needs wrk): the L0/L1 attribution ladder on both cores,
# interleaved in one session. Tunables via env, e.g.:
#   make bench-ladder-cores ROUNDS=5 SERVERS=8 DURATION=20
bench-ladder-cores:
	ext-rust/bench/ladder.sh

# The extension the Rust checks load, in the container's path shape.
RUST_EXT = /sconcur/ext-rust/build/sconcur.so

# The suites the Rust core can answer for. The features it does not implement
# (http client, socket, websocket, amqp) answer "unknown method", so running
# them would report the gap rather than a regression.
RUST_TEST_PATHS = \
	tests/feature/Connection \
	tests/feature/Scheduler \
	tests/feature/Features/Context \
	tests/feature/Features/Sleeper \
	tests/feature/Features/Mysql \
	tests/feature/Features/Pgsql \
	tests/feature/Features/Mongodb \
	tests/feature/Features/HttpServer \
	tests/feature/Features/SocketServer \
	tests/feature/Features/WsServer \
	tests/feature/Features/SocketClient \
	tests/feature/Features/WsClient

# The tests inside those suites the Rust core deliberately does not answer for.
# This list is the inventory of what the port leaves out, and it is kept here
# rather than by dropping whole suites so the rest of each one still runs:
#
#   - streamed HTTP responses (head/chunk/end) are not implemented, which also
#     takes the streaming half of the handler-timeout test with them;
#   - a bytea binding of invalid UTF-8 is accepted, where pgx's text-format path
#     rejects it (see .ai/plans/rust-core-spike.md).
#
# Anything that fails OUTSIDE this list is a real failure. Kept on one line:
# make turns a backslash-newline into a space, which would put spaces inside
# the regex and quietly match nothing.
RUST_TEST_EXCLUDE = testStreamedBodyIsAssembledFromChunks|testStreamedResponseUsesChunkedTransferEncoding|testStreamingHandlerIsCutOffByTheTotalDeadline|testBinaryWithNulByteFailsOnUtf8

# The feature suites, run against the Rust core. SCONCUR_EXT reaches the server
# harnesses too: they spawn their worker with proc_open, so without it a run
# would load Rust in the PHPUnit process and Go in the process doing the work.
test-rust:
	$(DOCKER_COMPOSE) exec -e SCONCUR_EXT=$(RUST_EXT) php \
		php -d extension=$(RUST_EXT) -d memory_limit=512M vendor/bin/phpunit \
		--colors=auto \
		--display-incomplete \
		--display-skipped \
		--display-errors \
		--exclude-filter '$(RUST_TEST_EXCLUDE)' \
		$(RUST_TEST_PATHS) ${c}

# `make check` for the Rust core: the same PHP-side gates, then the Rust build
# and its own checks in place of ext-test, then the feature suites through the
# Rust extension.
check-rust:
	make cs-fixer-check
	make php-stan
	make ext-rust-build
	make ext-rust-check
	make test-rust

# Resets the DB backends to a clean state before a benchmark session: drops the
# named data volumes and recreates the containers. Without it writes accumulate
# across runs (the DB data lives on disk now, not tmpfs) and the numbers drift.
bench-reset:
	$(DOCKER_COMPOSE) rm -sf mongodb mysql postgres rabbitmq
	docker volume rm -f sconcur-php_mongodb-data sconcur-php_mongodb-configdb sconcur-php_mysql-data sconcur-php_postgres-data sconcur-php_rabbitmq-data
	$(DOCKER_COMPOSE) up -d --wait mongodb mysql postgres rabbitmq

# Benchmark scripts live in tests/benchmarks/, grouped by the technology they
# measure: mongodb/, mysql/, pgsql/, http/, socket/, ws/, amqp/, db/ (whole-session
# DB runs), runtime/ (scheduler and PHP<->Go boundary) and lib/ (shared harness).
bench-all:
	make bench-sleeper
	make bench-mongodb-insertOne
	make bench-mongodb-bulkWrite
	make bench-mongodb-aggregate
	make bench-mongodb-insertMany
	make bench-mongodb-count
	make bench-mongodb-updateOne
	make bench-mongodb-findOne
	make bench-mongodb-createIndex
	make bench-mongodb-deleteOne
	make bench-mongodb-updateMany
	make bench-mongodb-command
	make bench-mysql-insert
	make bench-mysql-selectOne
	make bench-mysql-selectMany
	make bench-mysql-count
	make bench-mysql-update
	make bench-mysql-delete
	make bench-mysql-transaction
	make bench-pgsql-insert
	make bench-pgsql-selectOne
	make bench-pgsql-selectMany
	make bench-pgsql-count
	make bench-pgsql-update
	make bench-pgsql-delete
	make bench-pgsql-transaction
	make bench-http-client
	make bench-http-client-external
	make bench-http-client-download
	make bench-http-server-io
	make bench-http-server-cpu
	make bench-socket-client
	make bench-socket-throughput
	make bench-socket-server-io
	make bench-socket-server-cpu
	make bench-ws-client
	make bench-ws-throughput
	make bench-ws-server-io
	make bench-ws-server-cpu
	make bench-amqp-publish
	make bench-amqp-get
	make bench-amqp-consume

bench-amqp-publish:
	$(PHP_EXT) tests/benchmarks/amqp/publish.php ${c}

bench-amqp-get:
	$(PHP_EXT) tests/benchmarks/amqp/get.php ${c}

bench-amqp-consume:
	$(PHP_EXT) tests/benchmarks/amqp/consume.php ${c}

# Memory-leak soak for the AMQP feature: runs one scenario in a loop and prints, every
# five seconds, what the two runtimes hold — the PHP heap and its dangling tasks, the Go
# goroutine count and heap. Every cycle releases whatever it opened, so a column that only
# grows is a leak. Scenarios: publish, churn, consume, fanout, errors, confirms,
# consume-async, stop. Defaults to publish for two minutes.
#
# e.g.: make mem-leak-amqp scenario=churn seconds=600
#
# The goroutine and Go-heap columns come from the extension's own profiler, which
# SCONCUR_PPROF_ADDR switches on (ext/pprof.go); without it the run works and reports
# those two as zero, which hides exactly the half a soak is for.
mem-leak-amqp:
	$(DOCKER_COMPOSE) exec -e SCONCUR_PPROF_ADDR=127.0.0.1:6060 php \
		php -d extension=./ext/build/sconcur.so \
		tests/mem-leak/amqp-soak.php $(or $(scenario),publish) $(or $(seconds),120)

bench-db-lifecycle:
	$(PHP_EXT) tests/benchmarks/db/lifecycle.php ${c} ${runs} ${pool}

# Re-measures all DB benchmarks for docs/benchmarks.md: several runs per bench,
# each against a cold seeded dataset, aggregated to median/min/max markdown
# rows. Tunables via env, e.g.: make bench-db-runs RUNS=2 DATASET=1000
bench-db-runs:
	tests/benchmarks/db/runs.sh

bench-http-client-download:
	$(PHP_EXT) tests/benchmarks/http/client-download.php ${c}

bench-sleeper:
	$(PHP_EXT) tests/benchmarks/runtime/sleeper.php ${c}

bench-mongodb-insertOne:
	$(PHP_EXT) tests/benchmarks/mongodb/insert-one.php ${c}

bench-mongodb-bulkWrite:
	$(PHP_EXT) tests/benchmarks/mongodb/bulk-write.php ${c}

bench-mongodb-aggregate:
	$(PHP_EXT) tests/benchmarks/mongodb/aggregate.php ${c}

bench-mongodb-insertMany:
	$(PHP_EXT) tests/benchmarks/mongodb/insert-many.php ${c}

bench-mongodb-count:
	$(PHP_EXT) tests/benchmarks/mongodb/count.php ${c}

bench-mongodb-command:
	$(PHP_EXT) tests/benchmarks/mongodb/command.php ${c}

bench-mongodb-updateOne:
	$(PHP_EXT) tests/benchmarks/mongodb/update-one.php ${c}

bench-mongodb-findOne:
	$(PHP_EXT) tests/benchmarks/mongodb/find-one.php ${c}

bench-mongodb-createIndex:
	$(PHP_EXT) tests/benchmarks/mongodb/create-index.php ${c}

bench-mongodb-deleteOne:
	$(PHP_EXT) tests/benchmarks/mongodb/delete-one.php ${c}

bench-mongodb-updateMany:
	$(PHP_EXT) tests/benchmarks/mongodb/update-many.php ${c}

# Document-codec micro-benchmark: serialize/unserialize only, no database and no
# extension. c = iterations (default 20000).
bench-mongodb-serializer:
	$(PHP_CLI) php tests/benchmarks/mongodb/serializer.php ${c}

bench-mysql-insert:
	$(PHP_EXT) tests/benchmarks/mysql/insert.php ${c}

bench-mysql-selectOne:
	$(PHP_EXT) tests/benchmarks/mysql/select-one.php ${c}

bench-mysql-selectMany:
	$(PHP_EXT) tests/benchmarks/mysql/select-many.php ${c}

bench-mysql-count:
	$(PHP_EXT) tests/benchmarks/mysql/count.php ${c}

bench-mysql-update:
	$(PHP_EXT) tests/benchmarks/mysql/update.php ${c}

bench-mysql-delete:
	$(PHP_EXT) tests/benchmarks/mysql/delete.php ${c}

bench-mysql-transaction:
	$(PHP_EXT) tests/benchmarks/mysql/transaction.php ${c}

bench-pgsql-insert:
	$(PHP_EXT) tests/benchmarks/pgsql/insert.php ${c}

bench-pgsql-selectOne:
	$(PHP_EXT) tests/benchmarks/pgsql/select-one.php ${c}

bench-pgsql-selectMany:
	$(PHP_EXT) tests/benchmarks/pgsql/select-many.php ${c}

bench-pgsql-count:
	$(PHP_EXT) tests/benchmarks/pgsql/count.php ${c}

bench-pgsql-update:
	$(PHP_EXT) tests/benchmarks/pgsql/update.php ${c}

bench-pgsql-delete:
	$(PHP_EXT) tests/benchmarks/pgsql/delete.php ${c}

bench-pgsql-transaction:
	$(PHP_EXT) tests/benchmarks/pgsql/transaction.php ${c}

# Payload-size benches: p = payload bytes per operation (default 1024), c = calls.
# E.g.: make bench-mysql-payloadWrite p=1048576 c=50
PHP_EXT_PAYLOAD = $(DOCKER_COMPOSE) exec -e SCONCUR_BENCH_PAYLOAD_BYTES=$(p) php php -d extension=$(SCONCUR_EXT)

bench-mongodb-payloadWrite:
	$(PHP_EXT_PAYLOAD) tests/benchmarks/mongodb/payload-write.php ${c}

bench-mongodb-payloadRead:
	$(PHP_EXT_PAYLOAD) tests/benchmarks/mongodb/payload-read.php ${c}

bench-mysql-payloadWrite:
	$(PHP_EXT_PAYLOAD) tests/benchmarks/mysql/payload-write.php ${c}

bench-mysql-payloadRead:
	$(PHP_EXT_PAYLOAD) tests/benchmarks/mysql/payload-read.php ${c}

bench-pgsql-payloadWrite:
	$(PHP_EXT_PAYLOAD) tests/benchmarks/pgsql/payload-write.php ${c}

bench-pgsql-payloadRead:
	$(PHP_EXT_PAYLOAD) tests/benchmarks/pgsql/payload-read.php ${c}

bench-http-client:
	$(PHP_EXT) tests/benchmarks/http/client.php ${c}

bench-http-client-external:
	$(PHP_EXT) tests/benchmarks/http/client-external.php ${c}

bench-http-server-io:
	$(PHP_CLI) php tests/benchmarks/http/server-io.php

bench-http-server-cpu:
	$(PHP_CLI) php tests/benchmarks/http/server-cpu.php

bench-socket-client:
	$(PHP_EXT) tests/benchmarks/socket/client.php ${c}

bench-socket-throughput:
	$(PHP_CLI) php tests/benchmarks/socket/throughput.php

bench-socket-server-io:
	$(PHP_CLI) php tests/benchmarks/socket/server-io.php

bench-socket-server-cpu:
	$(PHP_CLI) php tests/benchmarks/socket/server-cpu.php

bench-ws-client:
	$(PHP_EXT) tests/benchmarks/ws/client.php ${c}

bench-ws-throughput:
	$(PHP_CLI) php tests/benchmarks/ws/throughput.php

bench-ws-server-io:
	$(PHP_CLI) php tests/benchmarks/ws/server-io.php

bench-ws-server-cpu:
	$(PHP_CLI) php tests/benchmarks/ws/server-cpu.php

# Runs on the HOST (needs wrk): one server per core with SO_REUSEPORT inside the
# php container, wrk pinned to separate cores, hitting the container IP (no NAT).
# Tunables via env, e.g.: make bench-http-throughput SERVERS=16 DURATION=20
bench-http-throughput:
	tests/benchmarks/http/throughput.sh

# RoadRunner reference server (native drivers, tests/servers/roadrunner) for the
# honest / and /all comparison. Foreground; stop with Ctrl+C. Tunables:
# make rr-serve RR_HTTP_PORT=18082 RR_NUM_WORKERS=8
# The /all-sconcur route needs the extension in the workers:
# make rr-serve RR_WORKER_CMD='php -d extension=/sconcur/ext/build/sconcur.so rr-worker.php'
RR_HTTP_PORT ?= 18081
RR_NUM_WORKERS ?= 16
RR_WORKER_CMD ?=

rr-serve:
	$(DOCKER_COMPOSE) exec -e RR_HTTP_PORT=$(RR_HTTP_PORT) -e RR_NUM_WORKERS=$(RR_NUM_WORKERS) -e RR_WORKER_CMD="$(RR_WORKER_CMD)" php rr serve -c tests/servers/roadrunner/.rr.yaml

# Swoole reference server (native drivers on coroutine workers,
# tests/servers/swoole) — the second reference stack of the comparison.
# Foreground; stop with Ctrl+C. The swoole extension is built into the php image
# but not enabled globally, so it is loaded per run. Tunables:
# make swoole-serve SWOOLE_HTTP_PORT=18083 SWOOLE_NUM_WORKERS=8
SWOOLE_HTTP_PORT ?= 18082
SWOOLE_NUM_WORKERS ?= 16
SWOOLE_DB_POOL_SIZE ?= 9
SWOOLE_ALL_POOL_SIZE ?= 5

swoole-serve:
	$(DOCKER_COMPOSE) exec \
		-e SWOOLE_HTTP_PORT=$(SWOOLE_HTTP_PORT) \
		-e SWOOLE_NUM_WORKERS=$(SWOOLE_NUM_WORKERS) \
		-e SWOOLE_DB_POOL_SIZE=$(SWOOLE_DB_POOL_SIZE) \
		-e SWOOLE_ALL_POOL_SIZE=$(SWOOLE_ALL_POOL_SIZE) \
		php php -d extension=swoole.so tests/servers/swoole/swoole-server.php

# Runs on the HOST (needs wrk + the mongodb/mysql/postgres services up): load the
# /all route (fans out across EVERY async I/O feature per request) and sample
# CPU/memory of the server and backend containers + per-worker RSS (leak check).
# Tunables via env, e.g.: make bench-http-load-stats SERVERS=12 DURATION=30
bench-http-load-stats:
	tests/benchmarks/http/load-stats.sh

# Soak variant: a long, steady-load run (10 min by default) that prints the
# worker-RSS trend over time and a least-squares leak slope. Override via env,
# e.g.: make bench-http-load-soak DURATION=3600
bench-http-load-soak:
	MODE=soak tests/benchmarks/http/load-stats.sh

# Baseline variant: same harness against the bare "/" route (no I/O fan-out) —
# measures the pure HTTP + framework ceiling, the floor under the /all numbers.
bench-http-load-stats-empty:
	ROUTE=/ tests/benchmarks/http/load-stats.sh

# RoadRunner counterparts of the three targets above: the same harness against
# the native-driver reference stack (tests/servers/roadrunner), so the numbers
# are directly comparable. Tunables via env, e.g.: make bench-rr-load-stats
# WORKERS=12 DURATION=30
bench-rr-load-stats:
	tests/benchmarks/http/rr-load-stats.sh

# SConcur fan-out INSIDE the RoadRunner worker: the same rr pool and harness,
# but the route is /all-sconcur (the SConcur features fanned out in a WaitGroup)
# and the workers load the sconcur extension. Comparable head-to-head with
# bench-rr-load-stats at the same WORKERS count.
bench-rr-sconcur-load-stats:
	ROUTE=/all-sconcur RR_WORKER_CMD='php -d extension=/sconcur/ext/build/sconcur.so rr-worker.php' tests/benchmarks/http/rr-load-stats.sh

bench-rr-load-soak:
	MODE=soak tests/benchmarks/http/rr-load-stats.sh

bench-rr-load-stats-empty:
	ROUTE=/ tests/benchmarks/http/rr-load-stats.sh

# Swoole counterparts of the same targets: the same harness against the coroutine
# reference stack (tests/servers/swoole), so all three servers are directly
# comparable at the same worker count. Tunables via env, e.g.:
# make bench-swoole-load-stats WORKERS=12 DURATION=30
bench-swoole-load-stats:
	tests/benchmarks/http/swoole-load-stats.sh

# Swoole's own in-request fan-out: the same pool and harness, but the route is
# /all-coro (the three features in a Swoole Coroutine\WaitGroup). Head-to-head
# with bench-swoole-load-stats at the same WORKERS count, the mirror of the
# /all vs /all-sconcur pair on RoadRunner.
bench-swoole-coro-load-stats:
	ROUTE=/all-coro tests/benchmarks/http/swoole-load-stats.sh

bench-swoole-load-soak:
	MODE=soak tests/benchmarks/http/swoole-load-stats.sh

bench-swoole-load-stats-empty:
	ROUTE=/ tests/benchmarks/http/swoole-load-stats.sh

# WebSocket load test: spawn a ws-server pool (SO_REUSEPORT, one per core) and drive
# it with the Go ws-load generator on the "all" message (fans out across EVERY async
# I/O feature per message), sampling CPU/memory + per-worker RSS (leak check). Both
# the pool and the generator run in the php container (no host tooling needed).
# Tunables via env, e.g.: make bench-ws-load-stats SERVERS=12 DURATION=30
bench-ws-load-stats:
	tests/benchmarks/ws/load-stats.sh

# Soak variant: a long, steady-load run (10 min by default) that prints the
# worker-RSS trend over time and a least-squares leak slope. Override via env,
# e.g.: make bench-ws-load-soak DURATION=3600
bench-ws-load-soak:
	MODE=soak tests/benchmarks/ws/load-stats.sh

# Baseline variant: same harness against the bare "ping" message (no I/O fan-out) —
# measures the pure WebSocket + framework ceiling, the floor under the "all" numbers.
bench-ws-load-stats-empty:
	MSG=ping tests/benchmarks/ws/load-stats.sh
