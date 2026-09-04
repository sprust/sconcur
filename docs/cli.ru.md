[English](cli.md) | Русский

# Консольные команды

Пакет ставит три исполняемых файла в `vendor/bin` (секция `bin` в
`composer.json`): `sconcur-load`, `sconcur-status`, `sconcur-server`. Из
репозитория они запускаются как `bin/<команда>`, из приложения-потребителя — как
`vendor/bin/<команда>`.

## sconcur-load — скачать расширение

Скачивает собранный `.so` расширения из GitHub Releases. Проект
экспериментальный, поэтому расширение не публикуется в PECL и подобных реестрах.
Перед скачиванием сверьтесь с разделом
[«Версии, на которых тестировалось»](../README.ru.md#версии-на-которых-тестировалось):
расширение собрано под конкретные версии PHP, Rust и серверов БД, и совместимость
гарантируется только с ними.

Версия не задаётся вручную — она берётся из
`Extension::REQUIRED_EXTENSION_VERSION` (версия, под которую собран PHP-пакет),
поэтому скачанный файл гарантированно проходит проверку при загрузке. Качается
ассет `https://github.com/sprust/sconcur/releases/download/v<версия>/sconcur.so`;
скользящего релиза `latest` нет, поэтому `.../releases/latest/download/...`
использовать нельзя.

Единственный аргумент — локальный путь назначения:

```sh
vendor/bin/sconcur-load ./ext             # каталог → <каталог>/sconcur.so
vendor/bin/sconcur-load ./ext/sconcur.so  # либо точный путь файла
```

- Если путь — существующий каталог, файл сохраняется как
  `<каталог>/sconcur.so`; иначе аргумент трактуется как полный путь файла.
- Каталог назначения должен существовать. Права на запись проверяются заранее:
  существующий файл должен быть перезаписываемым, иначе каталог — доступным на
  запись.
- Скачивание идёт во временный файл `<путь>.tmp` и затем атомарно переименовывается,
  поэтому целевой файл никогда не остаётся недописанным. При ошибке или пустом
  ответе временный файл удаляется.
- Редиректы GitHub на CDN отрабатываются автоматически; любой статус кроме `200` —
  ошибка, файл не пишется.
- Коды возврата: `0` — скачано, `1` — ошибка (нет аргумента, нет каталога, нет
  прав, неуспешный HTTP-статус, пустой файл). Сообщения об ошибках идут в `STDERR`.

После скачивания расширение включается флагом интерпретатора:

```sh
php -d extension=./ext/sconcur.so your-script.php
```

Либо положите его сразу в каталог расширений PHP и включите постоянно через
`.ini` — тогда флаг не нужен:

```dockerfile
RUN vendor/bin/sconcur-load "$(php-config --extension-dir)/sconcur.so" \
    && echo "extension=sconcur.so" > /usr/local/etc/php/conf.d/docker-php-ext-sconcur.ini
```

### Установка в образ до `composer install`

`bin/sconcur-load` — часть пакета и появляется только после установки
зависимостей. Чтобы положить расширение в системный каталог на более раннем этапе
сборки (ради кеширования слоёв), возьмите точную версию из `composer.lock` — он, в
отличие от `composer.json`, фиксирует разрешённую версию, а не ограничение — и
скачайте ассет напрямую. Копировать нужно только `composer.lock`, `vendor/` не
требуется:

```dockerfile
COPY composer.lock ./

RUN set -eux; \
    version="$(jq -r '.packages[] | select(.name=="sconcur/sconcur") | .version' composer.lock | sed 's/^v//')"; \
    curl -fSL --connect-timeout 10 --retry 3 -4 \
      "https://github.com/sprust/sconcur/releases/download/v${version}/sconcur.so" \
      -o "$(php-config --extension-dir)/sconcur.so"; \
    echo "extension=sconcur.so" > /usr/local/etc/php/conf.d/docker-php-ext-sconcur.ini
```

В `composer.lock` версия может лежать с префиксом `v` (`v0.11.0`), поэтому ведущий
`v` срезается и добавляется обратно в URL — иначе получится `vv0.11.0`. В этом слое
сборки нужны `jq` и `curl`.

## sconcur-status — проверить установку

Сообщает, загружено ли расширение `sconcur` и совпадает ли его версия с версией,
под которую собран пакет. По умолчанию печатает человекочитаемый отчёт, с `--json`
— одну машиночитаемую строку.

```sh
vendor/bin/sconcur-status
#   sconcur status
#     extension installed:  yes
#     package version:      0.11.0
#     extension version:    0.11.0
#     ready:                yes

vendor/bin/sconcur-status --json
#   {"extension_installed":true,"package_version":"0.11.0","extension_version":"0.11.0","ready":true}
```

Расширение должно быть включено для того же процесса, иначе скрипт его не увидит:
`php -d extension=./ext/sconcur.so vendor/bin/sconcur-status`.

Поля JSON: `extension_installed` — загружено ли расширение в текущем процессе;
`package_version` — версия, под которую собран пакет
(`Extension::REQUIRED_EXTENSION_VERSION`); `extension_version` — версия
загруженного расширения (`null`, если не загружено); `ready` — `true` только когда
расширение загружено и версия точно совпадает.

Код возврата — `0` при `ready=true` и `1` иначе, поэтому пайплайн может
ветвиться без парсинга вывода. На этом построен релизный CI: он пропускает
сборку дальше только при `ready` и тегирует релиз как `v<extension_version>`.

## sconcur-server — мастер воркеров

Запускает и стережёт пул процессов-воркеров (масштаб на ядра через
`SO_REUSEPORT`, перезапуск упавших, graceful shutdown). Команды
`start` / `status` / `stop` / `reload`, все принимают флаг `--configPath`,
указывающий на JSON-конфиг мастера; `--group=NAME` сужает `status` и `reload` до
одного пула (`start` и `stop` его принимают и проверяют имя, но работают со всем
конфигом):

```sh
vendor/bin/sconcur-server start --configPath=/app/master.json
```

Детали — параметры конфига, политика перезапуска, логирование и graceful shutdown
— в [мастере воркеров](worker-master.ru.md).
