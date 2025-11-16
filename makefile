PHP_CLI="docker-compose exec php"

env-copy:
	cp -i .env.example .env

build:
	docker-compose build

up:
	docker-compose up -d

stop:
	docker-compose stop

down:
	docker-compose down

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
	"$(PHP_CLI)" ./vendor/bin/phpunit \
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

test-sleep:
	"$(PHP_CLI)" php tests/test-concur-sleep.php ${c}

test-mongodb-insertOne:
	"$(PHP_CLI)" php tests/test-concur-mongodb-insert-one.php ${c}

test-mongodb-bulkWrite:
	"$(PHP_CLI)" php tests/test-concur-mongodb-bulk-write.php ${c}

test-mongodb-aggregate:
	"$(PHP_CLI)" php tests/test-concur-mongodb-aggregate.php ${c}
