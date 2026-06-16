MAKEFLAGS += --no-print-directory

DOCKER_COMPOSE = docker compose
PHP_CLI = $(DOCKER_COMPOSE) exec php
PHP_EXT = $(PHP_CLI) php -d extension=./ext/build/sconcur.so

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

http-server-restart:
	make ext-build
	$(DOCKER_COMPOSE) up -d --build --force-recreate http-server

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
	make bench-http-client
	make bench-http-client-google
	make bench-http-reuseport-io
	make bench-http-reuseport-cpu

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

bench-http-client:
	$(PHP_EXT) tests/benchmarks/http-client.php ${c}

bench-http-client-google:
	$(PHP_EXT) tests/benchmarks/http-client-google.php ${c}

bench-http-reuseport-io:
	$(PHP_CLI) php tests/benchmarks/http-reuseport-io.php

bench-http-reuseport-cpu:
	$(PHP_CLI) php tests/benchmarks/http-reuseport-cpu.php
