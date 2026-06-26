MAKEFLAGS += --no-print-directory

DOCKER_COMPOSE = docker compose
PHP_CLI = $(DOCKER_COMPOSE) exec php
PHP_EXT = $(PHP_CLI) php -d extension=./ext/build/sconcur.so

# Master-server control inside the `servers` container (both masters run there
# under supervisor). Each command targets one master by its JSON config.
SERVERS_CLI = $(DOCKER_COMPOSE) exec servers php /sconcur/bin/sconcur-server
HTTP_SERVER_CONFIG = /sconcur/config/sconcur.http-server.config.json
SOCKET_SERVER_CONFIG = /sconcur/config/sconcur.socket-server.config.json
WS_SERVER_CONFIG = /sconcur/config/sconcur.ws-server.config.json

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

http-server-status:
	$(SERVERS_CLI) status --configPath=$(HTTP_SERVER_CONFIG)

http-server-stop:
	$(SERVERS_CLI) stop --configPath=$(HTTP_SERVER_CONFIG)

http-server-reload:
	$(SERVERS_CLI) reload --configPath=$(HTTP_SERVER_CONFIG)

socket-server-status:
	$(SERVERS_CLI) status --configPath=$(SOCKET_SERVER_CONFIG)

socket-server-stop:
	$(SERVERS_CLI) stop --configPath=$(SOCKET_SERVER_CONFIG)

socket-server-reload:
	$(SERVERS_CLI) reload --configPath=$(SOCKET_SERVER_CONFIG)

ws-server-status:
	$(SERVERS_CLI) status --configPath=$(WS_SERVER_CONFIG)

ws-server-stop:
	$(SERVERS_CLI) stop --configPath=$(WS_SERVER_CONFIG)

ws-server-reload:
	$(SERVERS_CLI) reload --configPath=$(WS_SERVER_CONFIG)

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

test:
	$(PHP_EXT) vendor/bin/phpunit \
		-d memory_limit=512M \
		--colors=auto \
		--testdox \
		--display-incomplete \
		--display-skipped \
		--display-deprecations \
		--display-phpunit-deprecations \
		--display-errors \
		--display-notices \
		--display-warnings \
		tests ${c}

ext-build:
	$(PHP_CLI) sh ./ext-build.sh

ext-test:
	$(PHP_CLI) sh ./ext-test.sh

bench-all:
	make bench-sleep
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
	make bench-http-client-google
	make bench-http-client-download
	make bench-http-server-io
	make bench-http-server-cpu
	make bench-socket-client
	make bench-socket-throughput
	make bench-socket-server-io
	make bench-socket-server-cpu

bench-http-client-download:
	$(PHP_EXT) tests/benchmarks/http-client-download.php ${c}

bench-sleep:
	$(PHP_EXT) tests/benchmarks/sleep.php ${c}

bench-mongodb-insertOne:
	$(PHP_EXT) tests/benchmarks/mongodb-insert-one.php ${c}

bench-mongodb-bulkWrite:
	$(PHP_EXT) tests/benchmarks/mongodb-bulk-write.php ${c}

bench-mongodb-aggregate:
	$(PHP_EXT) tests/benchmarks/mongodb-aggregate.php ${c}

bench-mongodb-insertMany:
	$(PHP_EXT) tests/benchmarks/mongodb-insert-many.php ${c}

bench-mongodb-count:
	$(PHP_EXT) tests/benchmarks/mongodb-count.php ${c}

bench-mongodb-command:
	$(PHP_EXT) tests/benchmarks/mongodb-command.php ${c}

bench-mongodb-updateOne:
	$(PHP_EXT) tests/benchmarks/mongodb-update-one.php ${c}

bench-mongodb-findOne:
	$(PHP_EXT) tests/benchmarks/mongodb-find-one.php ${c}

bench-mongodb-createIndex:
	$(PHP_EXT) tests/benchmarks/mongodb-create-index.php ${c}

bench-mongodb-deleteOne:
	$(PHP_EXT) tests/benchmarks/mongodb-delete-one.php ${c}

bench-mongodb-updateMany:
	$(PHP_EXT) tests/benchmarks/mongodb-update-many.php ${c}

bench-mysql-insert:
	$(PHP_EXT) tests/benchmarks/mysql-insert.php ${c}

bench-mysql-selectOne:
	$(PHP_EXT) tests/benchmarks/mysql-select-one.php ${c}

bench-mysql-selectMany:
	$(PHP_EXT) tests/benchmarks/mysql-select-many.php ${c}

bench-mysql-count:
	$(PHP_EXT) tests/benchmarks/mysql-count.php ${c}

bench-mysql-update:
	$(PHP_EXT) tests/benchmarks/mysql-update.php ${c}

bench-mysql-delete:
	$(PHP_EXT) tests/benchmarks/mysql-delete.php ${c}

bench-mysql-transaction:
	$(PHP_EXT) tests/benchmarks/mysql-transaction.php ${c}

bench-pgsql-insert:
	$(PHP_EXT) tests/benchmarks/pgsql-insert.php ${c}

bench-pgsql-selectOne:
	$(PHP_EXT) tests/benchmarks/pgsql-select-one.php ${c}

bench-pgsql-selectMany:
	$(PHP_EXT) tests/benchmarks/pgsql-select-many.php ${c}

bench-pgsql-count:
	$(PHP_EXT) tests/benchmarks/pgsql-count.php ${c}

bench-pgsql-update:
	$(PHP_EXT) tests/benchmarks/pgsql-update.php ${c}

bench-pgsql-delete:
	$(PHP_EXT) tests/benchmarks/pgsql-delete.php ${c}

bench-pgsql-transaction:
	$(PHP_EXT) tests/benchmarks/pgsql-transaction.php ${c}

bench-http-client:
	$(PHP_EXT) tests/benchmarks/http-client.php ${c}

bench-http-client-google:
	$(PHP_EXT) tests/benchmarks/http-client-google.php ${c}

bench-http-server-io:
	$(PHP_CLI) php tests/benchmarks/http-server-io.php

bench-http-server-cpu:
	$(PHP_CLI) php tests/benchmarks/http-server-cpu.php

bench-socket-client:
	$(PHP_EXT) tests/benchmarks/socket-client.php ${c}

bench-socket-throughput:
	$(PHP_CLI) php tests/benchmarks/socket-throughput.php

bench-socket-server-io:
	$(PHP_CLI) php tests/benchmarks/socket-server-io.php

bench-socket-server-cpu:
	$(PHP_CLI) php tests/benchmarks/socket-server-cpu.php

# Runs on the HOST (needs wrk): one server per core with SO_REUSEPORT inside the
# php container, wrk pinned to separate cores, hitting the container IP (no NAT).
# Tunables via env, e.g.: make bench-http-throughput SERVERS=16 DURATION=20
bench-http-throughput:
	tests/benchmarks/http-throughput.sh

# Runs on the HOST (needs wrk + the mongodb/mysql/postgres services up): load the
# /all route (fans out across EVERY async I/O feature per request) and sample
# CPU/memory of the server and backend containers + per-worker RSS (leak check).
# Tunables via env, e.g.: make bench-http-load-stats SERVERS=12 DURATION=30
bench-http-load-stats:
	tests/benchmarks/http-load-stats.sh

# Soak variant: a long, steady-load run (10 min by default) that prints the
# worker-RSS trend over time and a least-squares leak slope. Override via env,
# e.g.: make bench-http-load-soak DURATION=3600
bench-http-load-soak:
	MODE=soak tests/benchmarks/http-load-stats.sh

# Baseline variant: same harness against the bare "/" route (no I/O fan-out) —
# measures the pure HTTP + framework ceiling, the floor under the /all numbers.
bench-http-load-stats-empty:
	ROUTE=/ tests/benchmarks/http-load-stats.sh
