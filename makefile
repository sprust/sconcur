MAKEFLAGS += --no-print-directory

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

cs-fixer-check:
	"$(PHP_CLI)" ./vendor/bin/php-cs-fixer fix --config cs-fixer.dist.php --dry-run --diff --verbose

cs-fixer-fix:
	"$(PHP_CLI)" ./vendor/bin/php-cs-fixer fix --config cs-fixer.dist.php --verbose

check:
	make cs-fixer-check
	make php-stan
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

bench-sleep:
	"$(PHP_CLI)" php -d extension=./ext/build/sconcur.so tests/benchmarks/sleep.php ${c}

bench-mongodb-insertOne:
	"$(PHP_CLI)" php -d extension=./ext/build/sconcur.so tests/benchmarks/mongodb-insert-one.php ${c}

bench-mongodb-bulkWrite:
	"$(PHP_CLI)" php -d extension=./ext/build/sconcur.so tests/benchmarks/mongodb-bulk-write.php ${c}

bench-mongodb-aggregate:
	"$(PHP_CLI)" php -d extension=./ext/build/sconcur.so tests/benchmarks/mongodb-aggregate.php ${c}

bench-mongodb-insertMany:
	"$(PHP_CLI)" php -d extension=./ext/build/sconcur.so tests/benchmarks/mongodb-insert-many.php ${c}

bench-mongodb-count:
	"$(PHP_CLI)" php -d extension=./ext/build/sconcur.so tests/benchmarks/mongodb-count.php ${c}

bench-mongodb-updateOne:
	"$(PHP_CLI)" php -d extension=./ext/build/sconcur.so tests/benchmarks/mongodb-update-one.php ${c}

bench-mongodb-findOne:
	"$(PHP_CLI)" php -d extension=./ext/build/sconcur.so tests/benchmarks/mongodb-find-one.php ${c}

bench-mongodb-createIndex:
	"$(PHP_CLI)" php -d extension=./ext/build/sconcur.so tests/benchmarks/mongodb-create-index.php ${c}

bench-mongodb-deleteOne:
	"$(PHP_CLI)" php -d extension=./ext/build/sconcur.so tests/benchmarks/mongodb-delete-one.php ${c}
