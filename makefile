MAKEFLAGS += --no-print-directory

DOCKER_COMPOSE = docker compose
PHP_CLI = $(DOCKER_COMPOSE) exec php
# The extension every target, benchmark and test harness loads: the Rust core.
# Absolute in the container's path shape, because it is handed to subprocesses
# that do not share this one's working directory (the server harnesses spawn
# their worker with proc_open).
#
# Overridable, so the same targets can be pointed at the Go build still living
# in ext-go-legacy/ without a second copy of each one:
#   make bench-mongodb-aggregate SCONCUR_EXT=/sconcur/ext-go-legacy/build/sconcur.so
SCONCUR_EXT ?= /sconcur/ext/build/sconcur.so

# Exported so the targets that run a script on the host (the load benchmarks)
# pass the same choice down instead of each recipe repeating it.
export SCONCUR_EXT

# Every target that loads the extension also puts the choice into the container's
# environment: the harnesses that spawn a worker of their own read SCONCUR_EXT,
# and without it a run would load one build in the PHPUnit process and another in
# the process doing the work.
PHP_CLI_EXT = $(DOCKER_COMPOSE) exec -e SCONCUR_EXT=$(SCONCUR_EXT) php
PHP_EXT = $(PHP_CLI_EXT) php -d extension=$(SCONCUR_EXT)

# Master control inside the `servers` container: one master runs there under
# supervisor, holding the three servers and the RabbitMQ consumers as a group
# each. A command names it by its config and, for a single pool, the group by
# --group.
SERVERS_CLI = $(DOCKER_COMPOSE) exec servers php /sconcur/bin/sconcur-server
SERVERS_CONFIG = /sconcur/config/sconcur.servers.config.json

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

# Rebuilds the extension and recreates the `servers` container.
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

rabbitmq-status:
	$(SERVERS_CLI) status --configPath=$(SERVERS_CONFIG) --group=rabbitmq

rabbitmq-reload:
	$(SERVERS_CLI) reload --configPath=$(SERVERS_CONFIG) --group=rabbitmq

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
	make ext-build
	make ext-test
	make ext-check
	make test

status:
	$(PHP_EXT) bin/sconcur-status ${c}

# The suites `make test` runs. Overridable, so a run can be narrowed to one
# directory without a target of its own.
TEST_PATHS = tests

# --log-junit persists the failing test's name for the rare flaky failure that
# only fires on the first run after heavy host activity. A failed run's report is
# copied to .phpunit-failed/ (gitignored, survives restarts) with a timestamped
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
		$(TEST_PATHS) ${c} || ( \
			$(PHP_CLI) sh -c 'mkdir -p .phpunit-failed && cp /tmp/sconcur-phpunit.xml .phpunit-failed/sconcur-phpunit-$$(date +%Y%m%d-%H%M%S).xml'; \
			exit 1 \
		)

# Compiles ext/ into ext/build/sconcur.so — the extension everything here loads. Two steps in one script: cargo builds the core into a static
# archive, gcc links it with the PHP glue (ext/build.sh).
ext-build:
	$(PHP_CLI) sh -c 'cd /sconcur/ext && CARGO_TARGET_DIR=/sconcur/ext/target sh ./build.sh'

# Exercises the core through the unmodified PHP package before the suites run:
# the shared channel, the error path, flow teardown, the sync wait.
ext-check:
	$(PHP_EXT) ext/check/core-smoke.php

# The Rust core's own unit tests, for what the PHP suites can only catch
# statistically — a race whose window is a few microseconds wide shows up there
# as one failure in forty runs, and here as a red test.
#
# --lib, and the crate stays a plain staticlib: cargo compiles the unit-test
# harness straight from the sources, so nothing about the shipped build changes.
# Adding "lib" to crate-type to make this work is the wrong instinct and was
# tried — an rlib beside the staticlib costs full LTO, and ext/build/sconcur.so
# went from 21.9 MB to 39.6.
ext-test:
	$(PHP_CLI) sh -c 'cd /sconcur/ext && CARGO_TARGET_DIR=/sconcur/ext/target cargo test --lib ${c}'

# What accepting one task costs the runtime, stage by stage: the flow registry,
# the per-flow bookkeeping and the spawn, which from PHP are one crossing seen
# from outside. --release because the debug build prices a different program
# (its numbers ran 2-4x the release ones), and #[ignore] so a plain ext-test
# does not spend seconds on a measurement.
ext-bench-push:
	$(PHP_CLI) sh -c 'cd /sconcur/ext && CARGO_TARGET_DIR=/sconcur/ext/target \
		cargo test --release --lib -- --ignored --nocapture push_cost'

# --- Profiling --------------------------------------------------------------
# A separate image whose PHP binary is built with frame pointers and left
# unstripped, so a sampling profiler can name what it sees. The image the
# benchmark numbers come from is untouched: the profilers live here and here
# only, and even here excimer is installed but not enabled, so nothing loads it
# unless a target below says so.
#
# Why it exists: the two PHP-side articles worth taking apart — building the
# PSR-7 request, and the residue of the coordination cycle — could not be, because
# the stock binary makes PHP-side call graphs unreadable.
#
# Needs the development image first (`make build`), and rebuilds PHP from source,
# so the first run takes a while.
PROFILE_COMPOSE = COMPOSE_FILE=docker-compose.yml:docker-compose.profiling.yml

profile-build:
	$(PROFILE_COMPOSE) docker compose build php

# Recreates the php container from the profiling image. The backends keep
# running; only php is replaced.
profile-up:
	$(PROFILE_COMPOSE) docker compose up -d --no-deps php

# Back to the development image.
profile-down:
	docker compose up -d --no-deps php

# Runs as root inside the container: the capabilities the overlay grants are
# only effective for uid 0, and a container running as a normal user has an
# empty CapEff whatever cap_add says.
# Samples a script with perf and writes a folded stack file next to it, which
# reads directly and also feeds a flame graph.
#   make profile-perf c="tests/benchmarks/runtime/push-profile.php"
profile-perf:
	$(PROFILE_COMPOSE) docker compose exec -u root -e SCONCUR_EXT=$(SCONCUR_EXT) php \
		perf record -F 999 -g --call-graph fp -o /tmp/sconcur-perf.data -- \
		php -d extension=$(SCONCUR_EXT) ${c}
	$(PROFILE_COMPOSE) docker compose exec -u root php \
		sh -c 'perf report -i /tmp/sconcur-perf.data --stdio --no-children --percent-limit 0.5 | head -60'

# Whether this host will let perf sample at all. kernel.perf_event_paranoid is a
# host setting a container cannot change: at 2 or below the target above works,
# at 3 or more (Ubuntu ships 4) the kernel refuses whatever capabilities the
# container was given.
profile-perf-check:
	@echo "kernel.perf_event_paranoid = $$(cat /proc/sys/kernel/perf_event_paranoid)"
	@echo "  <= 2  perf works"
	@echo "  >= 3  blocked; allow it for this boot with:"
	@echo "        sudo sysctl kernel.perf_event_paranoid=1"

# Samples the PHP stack rather than the C one: which PHP function was running,
# not which engine function it was inside. The two answer different halves.
#   make profile-php c="tests/benchmarks/runtime/push-profile.php"
profile-php:
	$(PROFILE_COMPOSE) docker compose exec -e SCONCUR_EXT=$(SCONCUR_EXT) php \
		php -d extension=excimer.so -d extension=$(SCONCUR_EXT) \
		tests/benchmarks/lib/excimer-profile.php ${c}

# Proof the image is what it claims: unstripped, with frame pointers, and
# carrying both profilers.
profile-verify:
	$(PROFILE_COMPOSE) docker compose exec php sh -c '\
		echo "php binary:"; file $$(which php) | sed "s/^/  /"; \
		echo "frame pointers:"; objdump -d $$(which php) | grep -c "push   %rbp" | sed "s/^/  push %rbp sites: /"; \
		echo "profilers:"; php -d extension=excimer.so -m | grep -i excimer | sed "s/^/  /"; \
		perf --version | sed "s/^/  /"'

# --- The Go core ------------------------------------------------------------
# Moved to ext-go-legacy/, still buildable, no longer what anything loads. It is
# the reference the Rust core was ported against, and the only build that carries
# the AMQP feature today. To run something against it:
#   make test SCONCUR_EXT=/sconcur/ext-go-legacy/build/sconcur.so

ext-build-go:
	$(PHP_CLI) sh ./ext-go-legacy/build.sh

# The Go unit tests, which only ever applied to the Go tree.
ext-test-go:
	$(PHP_CLI) sh ./ext-go-legacy/test.sh

# Runs on the HOST (needs wrk): the L0/L1 attribution ladder on both cores,
# interleaved in one session. Needs both builds. Tunables via env, e.g.:
#   make bench-ladder-cores ROUNDS=5 SERVERS=8 DURATION=20
bench-ladder-cores:
	ext/bench/ladder.sh

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

# Memory-leak soak for the AMQP feature: runs one scenario in a loop and prints,
# every five seconds, what is held on both sides — the PHP heap and its dangling
# tasks, and what the broker still has open. Every cycle releases whatever it
# opened, so a column that only grows is a leak. Scenarios: publish, churn,
# consume, fanout, errors, confirms, consume-async, stop, consumer, consumer-lost.
# Defaults to publish for two minutes.
#
# e.g.: make mem-leak-amqp scenario=churn seconds=600
#
# The extension side is reported through the task count and the broker's own
# connections, channels and consumers: a worker flat on its own memory can still
# leave sockets behind on the other side.
mem-leak-amqp:
	$(DOCKER_COMPOSE) exec php \
		php -d extension=$(SCONCUR_EXT) \
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

# What a fan-out costs per member on both sides of the shared results buffer.
# Nothing else here reaches the far side: a server serves tens of requests at
# once, not thousands, so the backpressure path is invisible to every other
# target.
bench-fan-out:
	$(PHP_EXT) tests/benchmarks/runtime/fan-out.php

# Where the scheduler's suspend -> push -> waitAny -> resume cycle spends its
# time. The companion of the boundary profile: that one prices taking a result
# across the boundary, this one prices the PHP coordination wrapped around it.
bench-coordination:
	$(PHP_EXT) tests/benchmarks/runtime/coordination-profile.php

# Where the per-request PSR-7 construction goes, stage by stage: the decode the
# server pays for on every request, split into unpack, URI, headers and body, so
# a lazy request can be judged against what it would actually save.
bench-request:
	$(PHP_EXT) tests/benchmarks/runtime/request-profile.php

# What taking a result out of the batch costs on the PHP side: the frame parse
# the profile puts at 17%, priced stage by stage on a captured batch.
bench-result:
	$(PHP_EXT) tests/benchmarks/runtime/result-profile.php

# Whether tearing a coroutine down costs more when more coroutines are alive:
# the same group of short-lived members against a growing crowd of parked
# neighbours. Two steps of that path are scans, and a scan costs what the
# process is holding.
bench-teardown:
	$(PHP_EXT) tests/benchmarks/runtime/teardown-profile.php

# The push half of the boundary, which neither of the two above prices. Pushes
# sleepers that hold for seconds, so the runtime cannot execute anything inside
# the measured window — its threads are this process's threads, and their CPU
# would otherwise land in the number.
bench-push:
	$(PHP_EXT) tests/benchmarks/runtime/push-profile.php

# What a member of a nested fan-out costs — a WaitGroup created inside a
# coroutine, the only shape whose pushes come off a coroutine's own stack and so
# the only one that feeds Scheduler::$pendingDispatches. Every other runtime
# bench here is flat, which is why that path had never been measured.
bench-nested-fan-out:
	$(PHP_EXT) tests/benchmarks/runtime/nested-fan-out.php

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

# Response-size variant: the same harness against /big/N, whose handler answers N
# bytes and does no I/O. It is what /  and /all cannot see — everything they
# measure carries a body of a few bytes, so a cost that scales with the response
# is invisible to both. That blind spot is how a response body crossed as three
# copies unnoticed until 2026-09-03;
# removing one of them was worth +16% rps here and nothing at all on "/".
# Size via BODY_BYTES, e.g.: make bench-http-load-stats-big BODY_BYTES=1048576
#
# No RoadRunner or Swoole counterpart: neither reference server has a /big route,
# so this compares SConcur against itself over time, not against another stack.
BODY_BYTES ?= 102400

bench-http-load-stats-big:
	ROUTE=/big/$(BODY_BYTES) tests/benchmarks/http/load-stats.sh

# RoadRunner counterpart of bench-http-load-stats, -soak and -empty (not of
# -big, whose route the reference servers do not have): the same harness against
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
