# Swoole-эталон для сравнения

Референсный сервер на [Swoole](https://swoole.com) 6.2.2 с копиями бенчмарк-ручек
SConcur-сервера (`tests/servers/http/http-server.php`) на нативных драйверах.
Второй эталон рядом с [RoadRunner](../roadrunner/README.md): те же бэкенды и та
же полезная нагрузка, но другая модель — корутинный воркер (один процесс держит
много одновременных запросов), а блокирующие драйверы становятся неблокирующими
через runtime-хуки.

- `GET /` — 200 `ok`;
- `GET /db?n={q}` — `{q}` последовательных point-SELECT по MySQL через `PDO`
  (по умолчанию 1) — лестница по числу воркеров;
- `GET /db-rw` — `INSERT` строки + `COUNT(*)` + point-SELECT случайного id в
  пределах этого количества, JSON `{count, record}`;
- `GET /all` — MongoDB `insertOne`+`findOne` (`mongodb/mongodb`), MySQL
  `INSERT`+`SELECT 1` (`PDO`), PostgreSQL `INSERT`+`SELECT 1` (`PDO`)
  последовательно внутри запроса; та же JSON-мапа статусов с изоляцией ошибок по
  фичам;
- `GET /all-coro` — те же три фичи, но веером в `Swoole\Coroutine\WaitGroup` —
  собственный ответ Swoole на фан-аут SConcur.

Бэкенды, `.env`, имена таблицы/коллекции (`load_all`, `bench_seed`, `bench_rw`) —
те же, что у SConcur-ручек; отличается только стек драйверов и модель исполнения.
Используется для замера из [docs/benchmarks.ru.md](../../../docs/benchmarks.ru.md)
(раздел «Сравнение с RoadRunner и Swoole»).

## Что важно знать о модели

- Хуки (`hook_flags => SWOOLE_HOOK_ALL`) переводят `PDO` MySQL/PostgreSQL,
  curl, стримы и `sleep` в корутинный режим: запрос, ждущий запроса к БД,
  отдаёт воркер другим запросам.
- `ext-mongodb` хуками не покрывается — libmongoc ходит в сеть из C мимо
  PHP-стримов. Любой вызов MongoDB блокирует весь воркер на время операции. Это
  свойство модели, а не обработчика, и оно видно в строках `/all`.
- Соединение `PDO` нельзя делить между одновременными корутинами, поэтому оба
  SQL-бэкенда идут через пул на воркер (`Swoole\Database\PDOPool`) — прямой
  аналог `maxOpenConns` у фич SConcur. Размеры зеркалят SConcur: 9 для `/db*`,
  5 для `/all`.
- Ручки `/all-sconcur` (как у RoadRunner) здесь нет: SConcur построен на PHP
  Fiber и собственном планировщике, а корутины Swoole управляют стеком сами —
  два планировщика в одном процессе не совмещаются.

## Запуск

Расширение `swoole` собирается при сборке контейнера `php` (`make build`), но
намеренно не включается глобально: тесты и остальные бенчи должны идти на
стоковом PHP. Поэтому оно подгружается на запуск (`-d extension=swoole.so`).

```shell
make swoole-serve                                      # 0.0.0.0:18082, 16 воркеров
make swoole-serve SWOOLE_HTTP_PORT=18083 SWOOLE_NUM_WORKERS=8
```

Проверка: `curl http://<ip-контейнера>:18082/all` (и `/all-coro`).

Порт по умолчанию 18082: 18080 занимает пул SConcur в `http-load-stats.sh`,
18081 — RoadRunner, так что все три стека можно держать поднятыми одновременно.

## Замеры

```shell
make bench-swoole-load-stats          # /all, лестница ресурсов, как у RR и SConcur
make bench-swoole-coro-load-stats     # /all-coro — фан-аут внутри запроса
make bench-swoole-load-stats-empty    # / — пустая ручка
make bench-swoole-load-soak           # долгий прогон с трендом RSS
```

Тюнинг через env — `WORKERS`, `CONNECTIONS`, `DURATION`, `DB_POOL_SIZE`,
`ALL_POOL_SIZE` (см. шапку `tests/benchmarks/swoole-load-stats.sh`).
