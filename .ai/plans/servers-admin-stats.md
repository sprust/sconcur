# Админка: статистика серверов (API)

Статус: **API реализовано** (Go + PHP + тесты зелёные), документация написана
([docs/admin-stats.ru.md](../../docs/admin-stats.ru.md)). Остаётся веб-панель —
следующий этап. Ветка: `feature/servers-admin`.
Пара к [мастеру воркеров](worker-master.md) и
[`SO_REUSEPORT`](http-server.md). Первый этап общей фичи «админка со статистикой
сервера» (см. README, раздел «Планы») — реализуем только API; веб-панель позже.

## Идея

Собрать агрегированную статистику по всему пулу HTTP-серверов, поднятых через
`SO_REUSEPORT` (с мастером или без него), и отдать её одной HTTP-ручкой.

Ключевая проблема reuse-port: каждый воркер — отдельный процесс со своим
Go-рантаймом и своими счётчиками, а ядро балансирует соединения. Запрос на общий
порт попадает ровно в один случайный воркер — он знает только свой срез. Поэтому
**нельзя отдавать стату через сам reuse-port-сокет**; агрегация идёт out-of-band.

Решение: каждый воркер пишет свой снапшот в общий каталог per-pid файлами;
admin-запрос обслуживает тот воркер, что его поймал, читая и суммируя **общие
файлы** (не лезя в сокеты соседей). Раз агрегация out-of-band — «кто поймал, тот
и ответил» полностью корректно, любой воркер отдаёт полную картину.

## Принципиальное решение: всё на Go-стороне

Вся фича живёт в расширении (Go). PHP не участвует ни при admin-запросе, ни при
сборке статы:

- `serverState.ServeHTTP` (`ext/internal/features/httpserver/server.go`) —
  единственная точка входа каждого запроса на Go-стороне, **до** перехода в PHP.
  Там и перехватываем admin-ручку, и инструментируем метрики.
- Снапшот пишет Go-горутина по таймеру; admin-запрос Go обслуживает сам, читая
  каталог. Кооперативный PHP-цикл не трогается, нет ни одного PHP→Go перехода на
  путь запроса.

Почти все метрики Go получает нативно (`/proc`, `runtime`, собственные счётчики
запросов). PHP-фид не нужен — «память без расширения» считается как
`RSS − goRuntime` (см. ниже).

## Источник данных по метрикам

| Метрика | Источник (Go) |
|---|---|
| `rssBytes` (с экстеншеном, весь процесс) | `/proc/self/status` → `VmRSS` |
| `goRuntimeBytes` (вклад Go-рантайма) | `runtime.ReadMemStats` → `Sys` |
| `nonExtensionBytes` (без экстеншена ≈ PHP+интерпретатор) | `rssBytes − goRuntimeBytes` |
| `cpuPercent` | диф `utime+stime` из `/proc/self/stat` между тиками снапшота |
| `uptimeSeconds` | `serverState.startTime` |
| `goroutines` | `runtime.NumGoroutine()` |
| `requests.completed`, `requests.avgMs` | счётчик + сумма длительностей в `defer` `ServeHTTP` |
| `requests.inFlight` + бакеты | `sync.Map` requestId→startTime, бакетим `now−start` на снапшоте |
| корутины в разрезе фич | **отложено** (нужна интроспекция `flows`) |

## Параметры (зафиксированы)

- Путь ручки — константа `/sconcur-server-api/admin/stats` (зарезервирован).
- Авторизация — `Authorization: Bearer <token>`, сравнение
  `subtle.ConstantTimeCompare`. Токен берётся из env `SCONCUR_ADMIN_TOKEN`.
  Нет токена → ручка не регается, путь идёт в PHP как обычный (fail-closed).
  Неверный/отсутствующий токен при заданном → `404` (не `401` — не раскрываем
  существование ручки). Токен **не** в URL (не течёт в access-лог).
- Период сбора снапшота — **хардкод 5s** на Go-стороне, без кастомизации.
- Бакеты in-flight **эксклюзивные** (запрос ровно в одном):
  `inFlight1to5s` = [1s,5s), `inFlight5to15s` = [5s,15s), `inFlightOver15s` = ≥15s.
- `hung` = снапшот устарел: `now − updatedAtMs > 15s` (3 пропущенных тика по 5s).
- Каталог статы — `<runtimeDir>/stats/`, имя файла `<name>-stats-<pid>.json`.
  Scope агрегации — файлы с тем же `name`, что у отвечающего воркера.

## Формат: файл статы одного воркера

`<runtimeDir>/stats/<name>-stats-<pid>.json` — атомарно (temp+rename), удаляется
на graceful-выходе; краш оставляет → пруним по живости pid.

```json
{
  "name": "sconcur-server",
  "pid": 12346,
  "updatedAtMs": 1750000000123,
  "uptimeSeconds": 312.5,
  "memory": {
    "rssBytes": 41943040,
    "goRuntimeBytes": 12582912,
    "nonExtensionBytes": 29360128
  },
  "cpuPercent": 3.7,
  "goroutines": 24,
  "requests": {
    "completed": 105432,
    "avgMs": 2.4,
    "inFlight": 7,
    "inFlight1to5s": 2,
    "inFlight5to15s": 1,
    "inFlightOver15s": 0
  }
}
```

## Формат: ответ ручки

`GET /sconcur-server-api/admin/stats` + `Authorization: Bearer <token>` →
`200 application/json`. Агрегатор читает все `<name>-stats-*.json` своего `name`,
под flock (`stats/.prune.lock`, non-blocking) удаляет файлы **мёртвых** pid
(`kill(pid,0)`), живые-но-устаревшие по mtime метит `hung` и **не** удаляет.
`totals.requests.avgMs` — взвешенное по `completed`; `totals.cpuPercent` — сумма
по воркерам (может быть >100% — это сумма per-process %). `generatedAt` —
человекочитаемое дата-время момента сборки ответа (RFC3339, напр.
`2026-06-24T12:00:00+00:00`). В файле снапшота `updatedAtMs` остаётся epoch-ms —
по нему агрегатор считает `snapshotAgeMs` и `hung`.

```json
{
  "generatedAt": "2026-06-24T12:00:00+00:00",
  "name": "sconcur-server",
  "workersTotal": 8,
  "workersHung": 1,
  "totals": {
    "memory": {
      "rssBytes": 335544320,
      "goRuntimeBytes": 100663296,
      "nonExtensionBytes": 234881024
    },
    "cpuPercent": 28.4,
    "goroutines": 192,
    "requests": {
      "completed": 843210,
      "avgMs": 2.6,
      "inFlight": 41,
      "inFlight1to5s": 12,
      "inFlight5to15s": 4,
      "inFlightOver15s": 1
    }
  },
  "workers": [
    {
      "pid": 12346,
      "hung": false,
      "snapshotAgeMs": 1200,
      "uptimeSeconds": 312.5,
      "memory": { "rssBytes": 41943040, "goRuntimeBytes": 12582912, "nonExtensionBytes": 29360128 },
      "cpuPercent": 3.7,
      "goroutines": 24,
      "requests": { "completed": 105432, "avgMs": 2.4, "inFlight": 7, "inFlight1to5s": 2, "inFlight5to15s": 1, "inFlightOver15s": 0 }
    }
  ]
}
```

## Изменения по слоям

### Go (`ext/`)

1. `internal/features/httpserver/server.go` — в `serverState`: счётчики
   `requestsCompleted`/сумма длительностей (инкремент в существующем `defer`
   `ServeHTTP`), `sync.Map` in-flight стартов. В начале `ServeHTTP` — перехват
   admin-пути: токен задан, путь совпал, Bearer прошёл → агрегируем и отвечаем
   Go-стороной, в PHP не уходим.
2. Новый пакет/файлы фичи статы (напр. `internal/features/httpserver/stats/`):
   - сбор метрик процесса (`/proc/self/status`, `/proc/self/stat`, `runtime`);
   - писатель снапшота (горутина, тик 5s, atomic temp+rename, удаление своего
     файла на остановке);
   - агрегатор (чтение каталога, прун мёртвых pid под flock, метка `hung`,
     суммирование + per-worker, сериализация ответа).
3. `internal/features/httpserver/payloads` — `ServePayload` += `AdminToken`,
   `StatsDir`, `ServerName`.
4. Горутина-писатель стартует/гасится вместе с `serverState`
   (старт в `handleServe`, стоп в `Close`).

### PHP (`src/`)

5. `Features/HttpServer/Payloads/ServePayload.php` — добавить поля
   `adminToken`/`statsDir`/`serverName` + ключи `at`/`sd`/`sn` в `getData()`.
6. `Features/HttpServer/HttpServer.php` — конструктор принимает
   `adminToken`/`statsDir`/`serverName`; `fromArgs()` читает
   `SCONCUR_ADMIN_TOKEN` из env и `statsDir`/`name`; прокидывает в `Scheduler::serve`
   → `ServePayload`.
7. `Worker/MasterConfig.php` — форвардить `runtimeDir`+`name` в argv воркера
   (для `statsDir`/`serverName`), чтобы оператор не дублировал их в блоке `server`.

### Версия расширения

8. Протокольное изменение (`ServePayload`) → бамп **0.3.0 → 0.3.1** один раз на
   ветке: `ext/main.go` `version()` + `Extension::REQUIRED_EXTENSION_VERSION`.

## Тесты

- Go: снапшот пишется/обновляется/удаляется на выходе; агрегатор суммирует N
  файлов; прун мёртвого pid; метка `hung` для устаревшего; эксклюзивность бакетов.
- PHP feature (`tests/feature/...`, харнесс `TestWorkerMaster`): поднять пул,
  `GET /sconcur-server-api/admin/stats` с токеном → `200` + агрегат; без/неверный
  токен → `404`; токен не появляется в access-логе; при отсутствии env-токена
  путь обслуживается обычным PHP-хендлером.
- В тестовый конфиг HTTP-сервера нужно добавить admin-токен: воркеры тестового
  пула (`tests/servers/http/http-server.php` через `TestWorkerMaster`) должны
  получать `SCONCUR_ADMIN_TOKEN` — задать его через блок `env` мастер-конфига
  (мастер форвардит env воркерам), чтобы ручка регалась. Тест без токена проверяет
  fail-closed отдельным конфигом без этого env.

## Документация

Отдельным шагом после кода (конвенция де-AI): новый раздел в
[docs/http-server.ru.md](../../docs/http-server.ru.md) или отдельный
`docs/admin-stats.ru.md`, плюс ссылка из `.ai/README.md`. README — одна строка в
«Планах» при готовности.

## Отложено (на потом)

- Корутины в разрезе фич (отдельный показатель — нужна интроспекция `flows`).
- Перцентили длительности (p50/p95) вместо/вдобавок к `avgMs`.
- CLI `sconcur-server stats` поверх тех же файлов (для socket-only пулов и
  headless): сейчас ручка только HTTP.
- Веб-панель (следующий этап админки).
