PHP_CLI="docker-compose exec php"

env-copy:
	cp -i .env.example .env

build:
	docker-compose build

up:
	docker-compose up -d

stop:
	docker-compose stop --timeout=3

down:
	docker-compose down --timeout=3

bash-php:
	"$(PHP_CLI)" bash

bash-php-remote:
	docker-compose run -it --rm php bash

composer:
	"$(PHP_CLI)" composer ${c}

php-stan:
	"$(PHP_CLI)" ./vendor/bin/phpstan analyse \
		--memory-limit=1G

check:
	make phpstan
	make test

test:
	"$(PHP_CLI)" php -d extension=./ext/build/sconcur.so vendor/bin/phpunit \
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
	"$(PHP_CLI)" sh ./ext-build.sh

bench-all:
	make bench-sleep && \
		make bench-mongodb-insertOne && \
		make bench-mongodb-bulkWrite && \
		make bench-mongodb-aggregate

bench-sleep:
	"$(PHP_CLI)" php -d extension=./ext/build/sconcur.so tests/benchmarks/sleep.php ${c}

bench-mongodb-insertOne:
	"$(PHP_CLI)" php -d extension=./ext/build/sconcur.so tests/benchmarks/mongodb-insert-one.php ${c}

bench-mongodb-bulkWrite:
	"$(PHP_CLI)" php -d extension=./ext/build/sconcur.so tests/benchmarks/mongodb-bulk-write.php ${c}

bench-mongodb-aggregate:
	"$(PHP_CLI)" php -d extension=./ext/build/sconcur.so tests/benchmarks/mongodb-aggregate.php ${c}
