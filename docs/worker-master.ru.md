# Мастер воркеров

Супервизор, который поднимает и следит за пулом **процессов-воркеров** одного
скрипта. Это пара к [`SO_REUSEPORT`](http-server.ru.md#масштабирование-на-ядра-so_reuseport):
воркеры биндят один порт, ядро балансирует соединения между ними — масштаб
HTTP-сервера на все ядра без внешнего балансировщика. Реализация — в `src/Worker/`
(PHP) и универсальный CLI `bin/sconcur-http-server`.

> Каждый воркер — **отдельный процесс** (`pcntl_fork` после загрузки расширения
> запрещён — Go-рантайм не переживает форк), мастер запускает их через `proc_open`.
> Сам мастер расширение **не грузит** — это чистый супервизор на `pcntl`/`posix`.

## Оглавление

- [Быстрый старт](#быстрый-старт)
- [Команды: start / status / stop](#команды-start--status--stop)
- [Параметры](#параметры)
- [Политика перезапуска и `maxRequests`](#политика-перезапуска-и-maxrequests)
- [Самозавершение осиротевших воркеров](#самозавершение-осиротевших-воркеров)
- [Логирование](#логирование)
- [Единственный инстанс, состояние и перезапуск из системы](#единственный-инстанс-состояние-и-перезапуск-из-системы)
- [Graceful shutdown](#graceful-shutdown)
- [Отличия от systemd / supervisord](#отличия-от-systemd--supervisord)
- [Тестирование](#тестирование)

---

## Быстрый старт

Потребитель пишет **только свой воркер-скрипт** (тот, что строит `HttpServer` и
зовёт `serve()`), а мастер берётся готовый из `bin/`.

**worker.php** (ваш скрипт):

```php
use SConcur\Features\HttpServer\Dto\Request;
use SConcur\Features\HttpServer\Dto\Response;
use SConcur\Features\HttpServer\HttpServer;
use SConcur\Worker\Worker;

require __DIR__ . '/vendor/autoload.php';

$index = Worker::index(); // 0..N-1, выдаёт мастер

$server = new HttpServer(
    address:     $argv[1] ?? '0.0.0.0:8080',
    reusePort:   true,                       // ОБЯЗАТЕЛЬНО: иначе 2-й воркер → EADDRINUSE
    maxRequests: 10_000 + $index * 137,      // джиттер, чтобы воркеры не выходили синхронно
    masterPid:   Worker::masterPid(), // самозавершение, если мастер умер
);

$server->serve(static fn (Request $request): Response => new Response(body: 'ok'));
```

**Запуск мастера:**

```sh
vendor/bin/sconcur-http-server start \
    --workerScript=/app/worker.php \
    --workerCount=8 \                         # по умолчанию — число ядер
    --address=0.0.0.0:8080 \
    --phpArgs='-d' --phpArgs='extension=/app/ext/build/sconcur.so' \
    --runtimeDir=/run/sconcur \
    --logDir=/var/log/sconcur \
    --rotateDays=3
```

`start` блокируется (foreground) и супервизирует пул, пока не придёт
`SIGTERM`/`SIGINT` **или** не будет удалён стейт-файл (см.
[Graceful shutdown](#graceful-shutdown)).

## Команды: start / status / stop

```sh
# start — поднять пул и супервизировать (foreground)
vendor/bin/sconcur-http-server start --workerScript=/app/worker.php ...

# status — состояние мастера (exit 0 = running, 3 = stopped/stale)
vendor/bin/sconcur-http-server status --runtimeDir=/run/sconcur
#   running: pid=12345 workers=8 address=0.0.0.0:8080

# stop — удалить стейт-файл (сигнал остановки) и дождаться выхода мастера
vendor/bin/sconcur-http-server stop --runtimeDir=/run/sconcur
```

Коды возврата: `start` — код мастера на выходе; `status` — `0` (running) /
`3` (stopped/stale); `stop` — `0` по факту остановки, `1` по таймауту.

> **Один и тот же `--runtimeDir`/`--name` нужно передавать во все три команды** —
> по ним `status`/`stop` находят lock/state. Если не переопределять — берётся дефолт
> (`runtimeDir` = временный каталог, `name` = `sconcur-http-server`), и команды
> согласованы без флагов. (Планируется env-фолбэк `SCONCUR_RUNTIME_DIR`/`SCONCUR_NAME`,
> чтобы задавать один раз — см. план.)

Программный API (под капотом CLI) — класс `SConcur\Worker\WorkerMaster`:

```php
use SConcur\Worker\WorkerMaster;

new WorkerMaster(
    workerScript: __DIR__ . '/worker.php',
    runtimeDir:   '/run/sconcur',
    logDir:       '/var/log/sconcur',
    workerCount:  0, // 0 = число ядер
    phpArgs:      ['-d', 'extension=' . __DIR__ . '/ext/build/sconcur.so'],
    workerArgs:   ['0.0.0.0:8080'],
)->run();
```

## Параметры

Имя флага `--paramName` точно совпадает с именем параметра конструктора `WorkerMaster`.

| Параметр / флаг | Дефолт | Назначение |
|---|---|---|
| `--workerScript` | — (обязателен) | Скрипт-воркер потребителя. |
| `--workerCount` | `0` (= число ядер) | Сколько воркеров поднять. |
| `--address` | — | Уходит в `argv` воркера и пишется в state-файл. |
| `--phpBinary` | текущий `PHP_BINARY` | Интерпретатор для воркеров. |
| `--phpArgs` | `[]` | Флаги интерпретатора, повторяемый (напр. `-d extension=…`). |
| `--workerArgs` | `[]` | Доп. `argv` воркера, повторяемый. |
| `--runtimeDir` | temp-dir | Каталог lock- и state-файла (локальная ФС). |
| `--logDir` | `runtimeDir` | Каталог логов. |
| `--name` | `sconcur-http-server` | Префикс имён лога и state-файла. |
| `--rotateDays` | `3` | Сколько дней хранить лог-файлы. |
| `--restartPolicy` | `always` | `always` \| `on-failure` \| `never`. |
| `--shutdownTimeoutMs` | `10000` | Сколько ждать дренаж воркеров до `SIGKILL`. |
| `--restartBackoffMs` | `200` | База экспоненциального backoff при краш-лупе. |
| `--maxRestartBackoffMs` | `30000` | Потолок backoff. |

Мастер передаёт каждому воркеру env: `SCONCUR_WORKER_INDEX` (0..N-1),
`SCONCUR_WORKER_COUNT`, `SCONCUR_MASTER_PID` — читаются через `Worker::index()`,
`Worker::count()`, `Worker::masterPid()`.

## Политика перезапуска и `maxRequests`

| Политика | Поведение |
|---|---|
| `always` (дефолт) | Перезапуск при **любом** выходе — чистом или аварийном. |
| `on-failure` | Перезапуск только при ненулевом коде / гибели по сигналу. |
| `never` | One-shot: отработал — и всё. |

`always` — дефолт неслучайно: фича
[`HttpServer(maxRequests: N)`](http-server.ru.md#остановка-после-n-запросов) гасит
воркер **штатно, с кодом 0** после N запросов (мера против утечек памяти). Чтобы
мастер поднял свежий процесс на замену, нужен именно `always` — при `on-failure`
чистый выход по лимиту перезапуск **не** вызовет. Раннее закрытие листенера воркером
+ `SO_REUSEPORT` дают **rolling-рестарт без потери трафика**: пока один воркер
пересоздаётся, ядро уводит новые соединения к соседям.

**Защита от краш-лупа.** Воркер, падающий сразу на старте, не вызывает спин
«спавн-падение-спавн»: per-slot **экспоненциальный backoff** (база
`restartBackoffMs`, удваивается с каждым быстрым падением, потолок
`maxRestartBackoffMs`). Воркер, проживший дольше ~1 c, считается «здоровым» —
backoff сбрасывается.

## Самозавершение осиротевших воркеров

Если мастер умрёт внезапно (краш, `SIGKILL`, OOM), воркеры **осиротеют** и иначе
продолжили бы жить, держа порт. Чтобы этого не было, воркер передаёт `HttpServer`
pid мастера через **`masterPid`** (из env). Сервер на каждом тике серверного цикла
сверяет `posix_getppid()` с этим pid: пока мастер жив, он родитель воркера; после
его смерти ядро меняет родителя, `getppid()` перестаёт совпадать (надёжно, без
подверженности переиспользованию PID) → сервер запускает **свой graceful-дренаж**
(как по `SIGTERM`), дослуживает in-flight и выходит, освобождая порт.

```php
new HttpServer(
    // ...
    masterPid: Worker::masterPid(), // ?int: null вне мастера — проверка выключена
);
```

## Логирование

Мастер пишет журнал в **один дневной файл** в `logDir` (без индекса воркера в
имени):

```
sconcur-http-server-2026-06-18.log
```

На смене суток открывается новый файл, файлы старше `rotateDays` (по умолчанию 3)
удаляются. Формат строки — по конвенции логгера проекта (timestamp с
микросекундами, уровень, скоуп-тег, сообщение, хвостовой контекст-массив):

```
[Y-m-d H:i:s.uuuuuu] LEVEL [<scope>]: <message> [<context>]
```

`<scope>` — `master: <pid>` для записей мастера и `worker: <pid> #<index>` для
записей про воркер.

```
[2026-06-18 12:00:00.173957] INFO [master: 12345]: start workers=8 script=/app/worker.php runtimeDir=/run/sconcur []
[2026-06-18 12:00:00.180210] INFO [worker: 12346 #0]: spawned []
[2026-06-18 12:01:00.012044] ERROR [worker: 12346 #0]: exited code=1 uptime=60.0s; restarting in 200ms []
[2026-06-18 12:01:00.020000] ERROR [worker: 12346 #0]: PHP Fatal error: ... []
[2026-06-18 12:05:00.000100] INFO [master: 12345]: shutdown requested; forwarding SIGTERM to workers []
```

`stdout`/`stderr` воркеров мастер **перехватывает** и переписывает в тот же файл со
скоупом `worker: <pid> #<index>` (stderr → `ERROR`, stdout → `INFO`), поэтому вывод
падения сохраняется в едином формате рядом с записью о выходе.

## Единственный инстанс, состояние и перезапуск из системы

**Единственный инстанс — через `flock`.** На старте мастер берёт эксклюзивный
неблокирующий лок на `runtimeDir/<name>.lock`. Второй мастер с тем же
`runtimeDir`+`name` получит `MasterAlreadyRunningException` и выйдет. Ядро снимает
лок **автоматически при смерти процесса** (даже по `SIGKILL`) — нет проблемы
протухшего лока, в отличие от PID-файла.

**State-файл `runtimeDir/<name>-state.json`** (по умолчанию
`sconcur-http-server-state.json`) — наблюдаемый «флаг»: pid, время старта, число
воркеров, адрес, статус. Пишется атомарно; **удаляется на чистом выходе** и
остаётся при крахе.

**State-файл — управляющий.** Мастер на каждом тике проверяет его наличие; **его
удаление — сигнал остановки**: мастер запускает тот же graceful-дренаж, что и по
`SIGTERM` (форвардит сигнал воркерам, дожидается in-flight, выходит). Именно так
работает команда `stop` (она просто удаляет стейт-файл). Удаление логируется
с уровнем `WARN` (`state file removed; shutting down gracefully`). Следствие: если
`/tmp`-клинер сотрёт файл — мастер штатно остановится со всеми воркерами, а внешний
супервизор/guard поднимет заново (кто перезапускает — вне зоны ответственности
мастера).

`status` и `stop` определяют «жив ли мастер» **по локу**, а не по pid из
state-файла: пробуют взять тот же `flock` — не вышло, значит лок держит живой
мастер. Это иммунно к протухшему state-файлу и к **переиспользованию PID** (ядро
снимает лок только при смерти владельца; на зомби-процессе лок тоже уже снят).
`stop` берёт pid для сигнала из state, но это безопасно: лок гарантирует, что жив
ровно тот мастер, который этот state и записал.

**Перезапуск из системы.** Внешний guard (cron/таймер) поднимает мастер, если тот
не работает — проще всего через `status` (он сам проверяет лок и отдаёт exit-code):

```sh
# по таймеру:
vendor/bin/sconcur-http-server status --runtimeDir=/run/sconcur >/dev/null \
  || vendor/bin/sconcur-http-server start --workerScript=/app/worker.php --runtimeDir=/run/sconcur ...
```

`flock` страхует от гонки двух guard'ов: если один уже стартует мастер, второй
получит `MasterAlreadyRunningException` и тихо выйдет.

> **«Всегда поднят» vs намеренная остановка.** Guard-модель перезапускает мастер и
> после намеренного `stop` (state-файла нет → guard поднимает снова). Если нужна
> возможность осознанно выключить и не поднимать — используйте отдельный маркер
> «disabled» или управление через systemd.

## Graceful shutdown

Триггеры остановки — `SIGTERM`/`SIGINT` **или удаление стейт-файла** (см. выше).
В любом случае мастер:

1. перестаёт перезапускать воркеры;
2. **форвардит `SIGTERM`** всем живым воркерам (каждый дренажит свои in-flight сам —
   см. [graceful HTTP-сервера](http-server.ru.md#graceful-shutdown));
3. ждёт их выхода до `shutdownTimeoutMs`, выживших добивает `SIGKILL`;
4. чистит state-файл, освобождает лок и выходит с кодом `0`.

Требуется `ext-pcntl` и `ext-posix` (в docker-образах проекта включены); без них
мастер бросает `MissingPcntlException` на старте.

## Отличия от systemd / supervisord

Внешний супервизор — валидная альтернатива. Свой мастер удобен, когда нужно:
запускать пул «одной командой» без внешней оркестрации; иметь единый graceful,
общий лог и согласованный rolling-restart в рамках одного дерева процессов; и тесную
связку с фичами библиотеки (`maxRequests`, `masterPid`-сторож). Перезапуск самого мастера
можно отдать systemd/cron-guard'у через `status`/`stop` (см. выше) — модели
дополняют друг друга.

## Тестирование

Тесты не зависят от docker-сервиса: харнесс
`SConcur\Tests\Impl\Worker\TestWorkerMaster` запускает `bin/sconcur-http-server`
отдельным процессом на loopback-порту, а воркером выступает общий демо-сервер
(`tests/servers/http/http-server.php`) с `reusePort`, `masterPid` и маршрутом
`/pid`.

Покрытие (`tests/feature/Worker/` + `tests/feature/Features/HttpServer/`):

- **Супервизия:** спавн N воркеров и обслуживание всеми; перезапуск убитого
  (`always`, в т.ч. `signal=` от OOM/`SIGKILL`); самовыход по `maxRequests` →
  перезапуск; политики `on-failure` (чистый выход не рестартит) и `never`.
- **Остановка:** graceful drain in-flight по `SIGTERM`; то же по **удалению
  стейт-файла**; все воркеры гаснут вместе с мастером.
- **Единственность/состояние:** отказ второго инстанса (lock); `status`/`stop`;
  `status` после краха мастера → `stopped` (по локу, иммунно к PID-reuse).
- **Устойчивость:** троттлинг краш-лупа backoff'ом; валидация (нет worker-скрипта,
  отрицательный `workerCount`); самозавершение осиротевших воркеров.
- **`masterPid` (изолированно, `HttpServerMasterPidTest`):** при `masterPid` =
  родитель сервер обслуживает; при чужом — сам штатно гаснет.
- **Логгер (юнит, `MasterLoggerTest`):** формат строки, контекст-JSON, дневная
  ротация с удержанием `rotateDays`.

---

См. также: [HTTP-сервер](http-server.ru.md),
[README → Планы](../README.md#планы).
