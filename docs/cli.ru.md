# Консольные команды

Пакет ставит три исполняемых скрипта в `vendor/bin` (раздел `bin` в
`composer.json`): `sconcur-load`, `sconcur-status`, `sconcur-server`. Из репозитория
их можно запускать как `bin/<команда>`, из приложения-потребителя — как
`vendor/bin/<команда>`.

## Оглавление

- [sconcur-load — скачать расширение](#sconcur-load--скачать-расширение)
- [sconcur-status — проверить установку](#sconcur-status--проверить-установку)
- [sconcur-server — мастер воркеров](#sconcur-server--мастер-воркеров)

## sconcur-load — скачать расширение

Проект экспериментальный, поэтому расширение не публикуется в реестрах
PHP-расширений (PECL и т.п.) — собранный `.so` берётся напрямую из GitHub
Releases этой команды (или вручную по ссылке релиза), а не через сторонний
репозиторий расширений.

Перед скачиванием сверьтесь с разделом «Версии, на которых тестировалось» в
[главном README](../README.md): расширение собрано под конкретные версии PHP, Go
и серверов (MongoDB, MySQL, PostgreSQL), и совместимость гарантируется только с
ними.

Скачивает собранный `.so` расширения с GitHub Releases. Версия не задаётся
вручную — берётся из `Extension::REQUIRED_EXTENSION_VERSION` (версия, под которую
собран PHP-пакет), поэтому скачанный файл гарантированно проходит проверку версии
при загрузке (`Extension::checkExtension`). Качается ассет
`https://github.com/sprust/sconcur/releases/download/v<версия>/sconcur.so`.

Единственный аргумент — локальный путь назначения:

```sh
# в каталог: файл ляжет как <каталог>/sconcur.so
vendor/bin/sconcur-load ./ext

# или точным путём к файлу
vendor/bin/sconcur-load ./ext/sconcur.so
```

Поведение:

- Если путь — существующий каталог, файл сохраняется как `<каталог>/sconcur.so`;
  иначе аргумент трактуется как полный путь к файлу.
- Каталог назначения должен существовать. Права на запись проверяются заранее (до
  загрузки): существующий файл должен быть перезаписываемым, иначе каталог —
  доступным для записи.
- Загрузка идёт во временный файл `<путь>.tmp`, затем атомарно переименовывается в
  целевой путь, так что цель никогда не остаётся полузаписанной. При ошибке или
  пустом ответе временный файл удаляется.
- HTTP-редиректы GitHub на CDN отслеживаются автоматически; ответ не `200` —
  ошибка без записи файла.

Коды возврата: `0` — файл скачан, `1` — ошибка (нет аргумента, нет каталога, нет
прав, неуспешный HTTP-статус, пустой файл). Сообщения об ошибках идут в `STDERR`.

После загрузки расширение подключается флагом интерпретатора:

```sh
php -d extension=./ext/sconcur.so your-script.php
```

Либо скачать сразу в каталог расширений PHP и подключить его навсегда через
`.ini` — тогда флаг не нужен. Пример для Docker (зависимости composer уже
установлены, поэтому `vendor/bin/sconcur-load` доступен и сам берёт нужную
версию):

```dockerfile
RUN vendor/bin/sconcur-load "$(php-config --extension-dir)/sconcur.so" \
    && echo "extension=sconcur.so" > /usr/local/etc/php/conf.d/docker-php-ext-sconcur.ini
```

Скрипт качает ассет из версионного релиза `v<версия>`, где версия — ровно та, под
которую собран пакет (`Extension::REQUIRED_EXTENSION_VERSION`), так что расширение
и пакет не разойдутся по версии. Скользящего релиза `latest` нет (CI помечает
«Latest» сам версионный релиз), поэтому качать по `.../releases/latest/download/...`
не следует — только по точному тегу, что скрипт и делает.

### Установка в образ до `composer install`

`bin/sconcur-load` — часть пакета и появляется только после установки зависимостей
(в `vendor/`). Если расширение нужно положить в системный каталог раньше — на
ранней стадии сборки образа, до `composer install` (например, ради кеша слоёв) —
скрипт ещё недоступен. Тогда точную версию берут из `composer.lock` (в нём, в
отличие от `composer.json`, зафиксирована разрешённая версия, а не ограничение) и
качают ассет напрямую.

Достаточно скопировать в образ только `composer.lock` — `vendor/` не нужен:

```dockerfile
COPY composer.lock ./

RUN set -eux; \
    version="$(jq -r '.packages[] | select(.name=="sconcur/sconcur") | .version' composer.lock | sed 's/^v//')"; \
    curl -fSL --connect-timeout 10 --retry 3 -4 \
      "https://github.com/sprust/sconcur/releases/download/v${version}/sconcur.so" \
      -o "$(php-config --extension-dir)/sconcur.so"; \
    echo "extension=sconcur.so" > /usr/local/etc/php/conf.d/docker-php-ext-sconcur.ini
```

В `composer.lock` версия может храниться с префиксом `v` (`v0.4.0`), поэтому
ведущий `v` срезается (`sed 's/^v//'`), а в URL он добавляется обратно (`v${version}`)
— иначе вышло бы `vv0.4.0`. Требуются `jq` и `curl` в этом слое сборки.

## sconcur-status — проверить установку

Сообщает, загружено ли расширение `sconcur` и совпадает ли его версия с версией,
под которую собран пакет. По умолчанию печатает человекочитаемый отчёт; с `--json`
— одну машиночитаемую строку.

```sh
vendor/bin/sconcur-status
#   sconcur status
#     extension installed:  yes
#     package version:      0.4.0
#     extension version:    0.4.0
#     ready:                yes

vendor/bin/sconcur-status --json
#   {"extension_installed":true,"package_version":"0.4.0","extension_version":"0.4.0","ready":true}
```

Расширение должно быть подключено к тому же процессу, иначе оно не видно скрипту:

```sh
php -d extension=./ext/sconcur.so vendor/bin/sconcur-status
```

Поля JSON:

- `extension_installed` — загружено ли расширение `sconcur` в текущем процессе.
- `package_version` — версия, под которую собран пакет
  (`Extension::REQUIRED_EXTENSION_VERSION`).
- `extension_version` — версия загруженного расширения (`null`, если не загружено).
- `ready` — `true`, только когда расширение загружено и его версия точно совпадает
  с версией пакета.

Код возврата: `0`, когда `ready=true`, иначе `1` — пайплайн может ветвиться без
разбора вывода. На этом построен релизный CI: он гейтит сборку по `ready` и тегает
релиз как `v<extension_version>`.

## sconcur-server — мастер воркеров

Запускает и супервизирует пул процессов-воркеров (масштаб на ядра через
`SO_REUSEPORT`, перезапуск упавших, graceful shutdown). Команды:
`start` / `status` / `stop` / `reload`, у всех один флаг `--configPath` с путём к
JSON-конфигу мастера.

```sh
vendor/bin/sconcur-server start --configPath=/app/master.json
```

Подробно — параметры конфига, политика перезапуска, логирование и graceful
shutdown — в [Мастер воркеров](worker-master.ru.md).
