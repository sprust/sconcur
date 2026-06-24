# Статистика серверов (HTTP + socket)

Статус: **v1 реализовано** (перехват на основном HTTP-порту, только http-сервер).
**v2 — спланировано, ждёт апрува**: выделенный stats-HTTP-сервер на своём порту
(`/api/stats`), общая логика в нейтральном пакете, поддержка socket-сервера.
Документация — [docs/admin-stats.ru.md](../../docs/admin-stats.ru.md).
Ветка: `feature/servers-admin`.

## Идея

Агрегированная статистика по всему пулу серверов (HTTP или socket), поднятому
через `SO_REUSEPORT`. Каждый воркер пишет свой снапшот в общий каталог per-pid
файлом; запрос на ручку обслуживает тот воркер, что его поймал, читая и суммируя
файлы всех воркеров своего `name`. Всё на стороне Go — PHP не участвует ни при
запросе, ни при сборе.

## v1 (реализовано)

Перехват пути `/sconcur-server-api/admin/stats` на основном HTTP reuse-порту,
Bearer-токен из `SCONCUR_ADMIN_TOKEN`. Снапшот-писатель (5s) + агрегатор живут в
`ext/internal/features/httpserver/stats/`. Только HTTP-сервер.

## v2 (этот план)

### Что меняется

1. Новая env-переменная `SCONCUR_STATS_PORT`. Если заданы **и порт, и токен** —
   каждый воркер поднимает отдельный HTTP-сервер на этом порту (`SO_REUSEPORT`,
   так все воркеры пула делят порт), маршрут `GET /api/stats`, Bearer-auth →
   агрегат. Ошибка бинда stats-порта логируется и **не роняет основной сервер**.
2. Та же возможность у **socket-сервера** — для него выделенный HTTP-сервер это
   единственный способ отдать статистику (у socket нет HTTP-маршрутов).
3. Общая логика вынесена в нейтральный пакет `ext/internal/stats/` (рядом с
   `internal/socket`, `internal/helpers`), используется обоими серверами.
4. Перехват на основном HTTP-порту (`/sconcur-server-api/admin/stats`) —
   **убирается** (заменяется выделенным портом). См. «Открытый вопрос».
5. Версию расширения **не бампаем** — уже `0.3.1` на этой ветке (правило «один
   раз на ветку»), хотя payload'ы снова меняются.

### Решение по старой ручке

Перехват `/sconcur-server-api/admin/stats` на основном HTTP-порту **убираем**
полностью. Остаётся единственный механизм — выделенный порт + `/api/stats`
(один путь для http и socket, изоляция admin-трафика).

### Нейтральный пакет `ext/internal/stats/`

- `snapshot.go` — типы `Memory`, `Requests`, `Connections`, `Snapshot`
  (опциональные `*Requests`/`*Connections` — workload-секция), типы агрегата,
  константы (интервал 5s, hung-порог 15s, `StatsPath = "/api/stats"`).
- `metrics.go` — процессные метрики (`/proc`, `runtime`) — перенос из v1.
- `collector.go` — `Collector`: процессные метрики + `WorkloadProvider`; писатель
  (5s, atomic temp+rename, удаление файла на остановке). Перенос + обобщение v1.
- `aggregate.go` — `Aggregate()` (чтение, прун мёртвых pid под flock, метка hung,
  сумма процессных метрик + сумма присутствующей workload-секции) и
  `AuthorizeBearer()`. Перенос + обобщение v1.
- `server.go` — `Server`: выделенный HTTP stats-сервер. `NewServer(address, token,
  statsDir, serverName)` биндит reuse-port, обслуживает `GET /api/stats` (Bearer,
  404 при несовпадении, 405 на не-GET) → `Aggregate`. `Close()`.
- `listen.go` — reuse-port TCP listen (self-contained ~20 строк; дублирование с
  httpserver/socketserver listen.go намеренно не трогаем — отдельный рефактор).

Workload-абстракция (feature-specific часть снапшота):

```go
type Workload struct {
    Requests    *Requests    `json:"requests,omitempty"`
    Connections *Connections `json:"connections,omitempty"`
}

type WorkloadProvider interface {
    WorkloadSnapshot() Workload
}
```

### httpserver

- Счётчики запросов (completed, сумма длительностей, in-flight реестр + бакеты)
  выносятся из `Collector` в локальный тип `requestStats` (реализует
  `WorkloadProvider`, возвращает `Workload{Requests: ...}`).
- `serverState`: `*stats.Collector` (provider = `requestStats`) + опционально
  `*stats.Server` (если порт+токен). Старт в `newServerState`/`handleServe`, стоп
  в `Close`.
- Удаляются `serveAdminStats` и перехват admin-пути в `ServeHTTP`.
- `ServePayload` += `sp` (statsPort); `at`/`sd`/`sn` остаются.

### socketserver

- Новый локальный тип `connectionStats` (реализует `WorkloadProvider`):
  `active` = текущее число соединений (`len` по `s.conns`), `totalAccepted` =
  atomic-счётчик принятых. Инкремент в `handleConn`.
- `serverState`: `*stats.Collector` (provider = `connectionStats`) + опционально
  `*stats.Server`. Старт/стоп как у httpserver.
- `ServePayload` (socket) += `at`/`sd`/`sn`/`sp`.

### PHP

- `HttpServer`: += `statsPort` параметр; `fromArgs` читает `SCONCUR_STATS_PORT`;
  `ServePayload` += `sp`.
- `SocketServer`: += `adminToken`/`statsDir`/`serverName`/`statsPort`; `fromArgs`
  читает те же 4 env; `serve` резолвит дефолт `statsDir`; `ServePayload` (socket)
  += `at`/`sd`/`sn`/`sp`.
- `MasterConfig`: по порту изменений нет — оператор задаёт `SCONCUR_STATS_PORT` (и
  `SCONCUR_ADMIN_TOKEN`) в блоке `env`; `SCONCUR_STATS_DIR`/`SCONCUR_SERVER_NAME`
  мастер уже инжектит из `runtimeDir`/`name`.

### Конфигурация (env, читают оба сервера)

| Переменная | Назначение | Кто задаёт |
|---|---|---|
| `SCONCUR_ADMIN_TOKEN` | токен ручки | оператор (env-блок) |
| `SCONCUR_STATS_PORT` | порт выделенного stats-сервера | оператор (env-блок) |
| `SCONCUR_STATS_DIR` | каталог снапшотов | мастер (из runtimeDir) |
| `SCONCUR_SERVER_NAME` | имя/scope агрегации | мастер (из name) |

Снапшот-писатель работает при заданном `statsDir`. Выделенный сервер — при
заданных порте **и** токене. Разные пулы (http и socket) должны использовать
**разные** stats-порты и `SCONCUR_SERVER_NAME` — иначе reuse-port отдаст запрос
случайному воркеру чужого пула.

### Формат снапшота (добавка для socket)

К общим процессным метрикам добавляется одна из workload-секций (http → первая,
socket → вторая):

```text
"requests":    { "completed": 105432, "avgMs": 2.4, "inFlight": 7, "inFlight1to5s": 2, "inFlight5to15s": 1, "inFlightOver15s": 0 }
"connections": { "active": 12, "totalAccepted": 34567 }
```

Агрегат `totals` содержит ту секцию(и), что присутствует в снапшотах пула.

## Тесты

- Go `internal/stats`: перенос v1-тестов; новый тест `Server` (`NewServer` →
  `GET /api/stats` с Bearer → 200/404/405); агрегация с socket-workload.
- PHP feature: `TestWorkerMaster` параметризовать воркер-скриптом (сейчас хардкод
  http demo), чтобы поднять и socket-пул (`tests/servers/socket/socket-server.php`).
  Тест: http-пул и socket-пул с `SCONCUR_STATS_PORT`+`SCONCUR_ADMIN_TOKEN` →
  `GET http://<stats-port>/api/stats` с Bearer → 200 + агрегат (для socket в
  ответе `connections`); без/неверный токен → 404.

## Документация

- Переписать `docs/admin-stats.ru.md` под v2: выделенный порт, `/api/stats`,
  `SCONCUR_STATS_PORT`, поддержка socket-сервера, секция `connections`.
- Обновить `docs/adding-a-server.ru.md` (гайд по добавлению нового сервера):
  добавить шаг про подключение статистики — новый сервер должен создавать
  `stats.Collector` со своим `WorkloadProvider` и поднимать `stats.Server` при
  заданных `SCONCUR_STATS_PORT`+`SCONCUR_ADMIN_TOKEN` (как это делают http и
  socket), чтобы любой новый сервер из коробки собирал и отдавал стату.

## Отложено

- Корутины в разрезе фич.
- Перцентили длительности (p50/p95).
- Извлечение reuse-port listen в общий хелпер (сейчас три копии).
- CLI `sconcur-server stats`.
