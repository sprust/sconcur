# Мастер воркеров

Статус: **реализовано** (этапы 1–10; стретч-пункт 9 — опционально, не сделан).
Пользовательская документация — [docs/worker-master.ru.md](../../docs/worker-master.ru.md).
Пара к
[`SO_REUSEPORT`](http-server.md) и к фиче
[«остановка после N запросов»](../../docs/http-server.ru.md#остановка-после-n-запросов).

## Идея

Долгоживущий **мастер-процесс**-супервизор поднимает и следит за несколькими
**процессами-воркерами** одного скрипта. Каждый воркер — **отдельный процесс**
(`pcntl_fork` после загрузки расширения запрещён — Go-рантайм не переживает форк),
поэтому мастер запускает их через `proc_open`. Это пара к
[`SO_REUSEPORT`](http-server.md): воркеры биндят один порт, ядро балансирует
соединения между ними — масштаб HTTP-сервера на все ядра без внешнего
балансировщика.

Мастер **самодостаточен и универсален**: он умеет супервизировать любой
скрипт-воркер (не только HTTP-сервер). HTTP + `reusePort` — основной, но не
единственный сценарий.

### Зачем свой мастер, если есть systemd/supervisord

Внешний супервизор (systemd template-юнит, `supervisord`, docker `--scale`) —
валидная альтернатива, и план её не отменяет. Свой мастер нужен там, где хочется:

- **единая точка запуска** «одной командой» без внешней оркестрации (dev, простой
  прод, контейнер с одним процессом-точкой-входа);
- **знание о воркерах в рамках одного дерева процессов** — единый graceful, общий
  лог, согласованный rolling-restart;
- **тесная связка с фичами библиотеки** — в частности с
  [`maxRequests`](#связь-с-maxrequests-перезапуск-по-исчерпанию-лимита): воркер сам
  выходит по лимиту, мастер мгновенно поднимает свежий процесс (см. ниже).

## API (решение по «открытому вопросу»)

Два уровня, библиотека поставляет оба:

1. **Универсальный CLI `bin/sconcur-server`** (основной интерфейс) — готовый
   мастер-скрипт с командами `start | status | stop`. Потребитель **не пишет
   мастер** — реализует только **свой воркер-скрипт** (тот, что строит `HttpServer`
   и зовёт `serve()`), и указывает путь к нему. Регистрируется в `composer.json`
   (`"bin"`), доступен как `vendor/bin/sconcur-server`.
2. **Класс `WorkerMaster`** (программный API под капотом CLI) — для тех, кому нужен
   мастер внутри своего кода. CLI — тонкая обёртка над ним.

Мастеру **не нужно** расширение `sconcur.so` (он супервизор на
`pcntl`/`posix`/`proc_open`); расширение грузит **воркер**.

Размещение: `bin/sconcur-server` (CLI) + `src/Worker/` → namespace
`SConcur\Worker\`:

- `MasterCli` — разбор `argv`, диспетчер `start|status|stop` (логика — здесь, чтобы
  была тестируемой; bin-файл лишь резолвит автозагрузчик и зовёт её).
- `WorkerMaster` — супервизор (`run()` = `start`).
- `RestartPolicy` — enum (`Always` | `OnFailure` | `Never`).
- `HttpServer::fromArgs($argv)` — воркер-скрипт собирает сервер из `argv`; мастер
  передаёт параметры `server` и свой pid флагом `--masterPid`.
- (внутреннее) `MasterConfig`, `WorkerProcess`, `Cpu`, `MasterState`, `MasterLock`, `MasterLogger`.

### CLI: `start | status | stop`

Все команды принимают **один** флаг — `--configPath` с путём к JSON-конфигу мастера.
Конфиг содержит параметры `WorkerMaster` плюс вложенный объект `server`, который
мастер разворачивает в `argv` воркера (`address` — позиционный `$argv[1]`, прочие
ключи — `--ключ=значение`, булевы → `1`/`0`). Незаданные ключи берут дефолт.

```jsonc
// /app/master.json
{
  "workerScript": "/app/worker.php",   // ОБЯЗАТЕЛЬНО
  "workerCount": 8,                     // по умолчанию = число ядер
  "phpArgs": ["-d", "extension=/app/ext/build/sconcur.so"],
  "runtimeDir": "/run/sconcur",         // lock + state
  "logDir": "/var/log/sconcur",
  "rotateDays": 3,
  "restartPolicy": "always",            // always | on-failure | never
  "shutdownTimeoutMs": 10000,
  "server": { "address": "0.0.0.0:8080", "reusePort": true, "maxRequests": 10000 }
}
```

```sh
# start — захватить lock, поднять воркеры, супервизировать (foreground; блокируется)
vendor/bin/sconcur-server start --configPath=/app/master.json

# status — жив ли мастер (по локу), напечатать состояние
vendor/bin/sconcur-server status --configPath=/app/master.json
#   → "running: pid=12345 workers=8 address=0.0.0.0:8080"  (код 0)
#   → "stopped"                                            (код 3)

# stop — удалить стейт-файл (сигнал остановки), дождаться выхода мастера
vendor/bin/sconcur-server stop --configPath=/app/master.json
```

Коды возврата: `start` — код мастера на выходе; `status` — `0` если running,
`3` если stopped/stale (для скриптов-guard'ов); `stop` — `0` по факту остановки.

- **`start` — foreground** (блокируется и супервизирует): удобно для systemd
  (`ExecStart`), docker (entrypoint) и guard'а. Демонизация (`--daemon`, через
  `pcntl_fork` — мастеру это можно, он без Go-рантайма) — опционально/стретч.
- **`stop`/`status`** не поднимают мастер: читают `runtime-dir/<name>-state.json` и
  определяют живость по локу (`flock`); `stop` удаляет стейт-файл — мастер следит за
  ним и сам инициирует graceful-стоп.
- bin-файл резолвит автозагрузчик и как зависимость (`vendor/autoload.php`), и
  in-repo; шебанг `#!/usr/bin/env php`.

### Программный API (под капотом CLI)

```php
use SConcur\Worker\WorkerMaster;
use SConcur\Worker\RestartPolicy;

$master = new WorkerMaster(
    workerScript:      __DIR__ . '/worker.php', // что запускать (обязателен)
    runtimeDir:        '/run/sconcur',           // lock + state-файл (единственный инстанс, перезапуск)
    logDir:            '/var/log/sconcur',       // каталог логов (см. «Логирование»)
    name:              'sconcur-server',    // префикс лога/стейта
    rotateDays:        3,                         // хранить N дней лог-файлов
    workerCount:       0,                        // 0 = число ядер (nproc)
    phpBinary:         PHP_BINARY,               // чем запускать (по умолч. — текущий интерпретатор)
    phpArgs:           ['-d', 'extension=' . __DIR__ . '/ext/build/sconcur.so'],
    workerArgs:        ['0.0.0.0:8080'],         // argv воркер-скрипта
    env:               [],                        // доп. env (мержится поверх унаследованного)
    restartPolicy:     RestartPolicy::Always,    // см. «Политика перезапуска»
    shutdownTimeoutMs: 10_000,                    // сколько ждать дренаж воркеров на остановке
    restartBackoffMs:  200,                       // база экспоненциального backoff при краш-лупе
    maxRestartBackoffMs: 30_000,                  // потолок backoff
);

$master->run(); // блокируется: lock → state → спавн → супервизия → сигналы → выход
```

Воркер-скрипт (`worker.php`) — **единственное, что пишет потребитель**; для HTTP:

```php
// fromArgs() собирает HttpServer из argv: параметры блока `server` приходят
// флагами --имя=значение, pid мастера — флагом --masterPid (сторож: на каждом тике
// serve() сверяем posix_getppid() с ним; осиротели → тот же graceful-дренаж, что и
// по SIGTERM, см. «Самозавершение осиротевших воркеров»).
$server = HttpServer::fromArgs($_SERVER['argv']);

$server->serve($handler);
```

## Поток

```
master.run():
  assertPcntl()                                  // без pcntl/posix нет graceful → исключение
  lock = flock(runtimeDir/master.lock, EX|NB)    // занято → MasterAlreadyRunningException
  count = workerCount ?: Cpu::count()            // по умолчанию = число ядер
  log("master start", pid, count); writeState(status=running)
  installSignalHandlers()                        // SIGTERM/SIGINT → stop; SIGHUP → reload (опц.)
  slots[0..count-1] = spawn()                    // отдельный процесс-воркер на слот; env += MASTER_PID/INDEX/COUNT

  loop (tick ~100мс):
    dispatchSignals()
    foreach slot:
      drainOutput(slot) -> log each line (scope worker: pid #index, stderr=ERROR/stdout=INFO)
      if slot.process.exited():
        drainOutput(slot)   // дочитать хвост вывода падения
        reap(slot); log("worker exit", slot, code|signal, uptime)
        if !stopping && shouldRestart(policy, exit):
          slot.respawnAt = now + backoff(slot)   // backoff растёт при быстрых падениях
    foreach slot where respawnAt<=now && !stopping:
      slot = spawn(); log("worker spawn", slot, pid); updateState()
    if stopping:
      if firstStopTick: signalAll(SIGTERM); deadline = now + shutdownTimeoutMs
      if now > deadline: signalAll(SIGKILL)       // добиваем зависших
      if allExited(): break
    sleep(tick)

  reapAll(); clearState(); releaseLock(); restoreSignals(); exit(0)

worker (script):
  HttpServer::fromArgs($_SERVER['argv'])->serve($handler)   // адрес/server-флаги + --masterPid
  // стоп по: SIGTERM (graceful, реализовано) | maxRequests (сам выходит 0) |
  //          masterPid осиротел (getppid != masterPid) → graceful drain → выход
```

## Сущности

### PHP (`bin/` + `src/Worker/`)

- **`bin/sconcur-server`** — шебанг-скрипт: резолвит автозагрузчик и
  делегирует в `MasterCli::run($argv)`. Регистрируется в `composer.json` `"bin"`.
- **`WorkerMaster`** (`readonly`-конфиг в конструкторе; `run()` — изменяемый цикл).
  Хранит слоты, ставит/снимает обработчики сигналов, гоняет супервизионный цикл.
- **`WorkerProcess`** — один воркер: `proc_open`-handle, `pid()`, `isRunning()`,
  `exitInfo()` (код/сигнал, **кэшируется**: `proc_get_status` отдаёт `exitcode`
  только при первом переходе `running→false`), `signal(int)`, `close()` (reap).
  Держит **неблокирующие** stdout/stderr-пайпы (`stream_set_blocking(false)`) и
  отдаёт накопленные строки мастеру для перезаписи в общий лог; пайпы дочитываются
  до конца после выхода процесса (не потерять хвост вывода падения).
- **`RestartPolicy`** — enum: `Always`, `OnFailure`, `Never`.
- **`Cpu::count()`** — число ядер с фолбэками (см. ниже).
- **`HttpServer::fromArgs($argv)`** — фабрика воркер-скрипта: сопоставляет
  `--имя=значение` со скалярными параметрами конструктора (включая `masterPid` из
  флага `--masterPid`, который инжектит мастер для сирота-чека).
- **`MasterConfig`** — DTO + загрузчик JSON-конфига (`fromFile`): параметры
  `WorkerMaster` + блок `server`; `toWorkerMaster()` разворачивает `server` в `argv`
  воркера. Битый/неполный конфиг → `InvalidConfigException`.
- **`MasterCli`** — единственный флаг `--configPath`; диспетчер `start|status|stop` (тестируемая
  логика; bin-файл — тонкая обёртка).
- **`MasterState`** — чтение/запись JSON state-файла `<name>-state.json` (по умолч.
  `sconcur-server-state.json`): pid, `startedAt`, `workerCount`, `workerScript`,
  `address`, `status`; атомарная запись (temp+rename).
- **`MasterLock`** — обёртка над `flock(LOCK_EX|LOCK_NB)`: захват при `run()`,
  удержание хэндла на всё время жизни мастера (ядро снимает лок при смерти).
- **`MasterLogger`** — один **дневной** файл `<name>-Y-m-d.log` (по умолч.
  `sconcur-server-2000-01-01.log`); формат строки
  `[Y-m-d H:i:s.uuuuuu] LEVEL [<scope>]: <message> [<context>]`
  (`scope` = `master: pid` | `worker: pid #index`); на смене суток —
  новый файл + **подчистка** старше `rotateDays` (по умолч. 3). Методы вроде
  `master(level, msg)` / `worker(level, pid, index, msg)`.
- Исключения в `src/Exceptions/Worker/`:
  - `MissingPcntlException` (`RuntimeException`) — нет `ext-pcntl`/`ext-posix`;
  - `InvalidWorkerCountException` (`LogicException`) — `workerCount < 0`;
  - `WorkerSpawnException` (`RuntimeException`) — `proc_open` не смог стартовать
    процесс (оборачивает контекст: бинарь/скрипт/argv);
  - `MasterAlreadyRunningException` (`RuntimeException`) — lock занят другим мастером;
  - `RuntimePathException` (`RuntimeException`) — `runtimeDir`/`logDir` недоступны
    для записи.

Мастер **не использует** `Extension`/`Scheduler`/корутины — это вне Go-рантайма.

### Зависимость от `HttpServer` (новый параметр)

Для самозавершения осиротевших воркеров `HttpServer` получает опциональный
**`masterPid: ?int`**. Если задан, на каждом тике серверного цикла
(`Scheduler::serve` уже поллит `waitAnyTimeout(250мс)`) сервер сверяет
`posix_getppid()` с `masterPid`; не совпало (мастер умер, воркер переподвешен) →
запускается **тот же** graceful-дренаж, что и по сигналу (складывается в
существующий `shouldStop`: сигнал **или** осиротел). `getppid` иммунен к
переиспользованию PID. Pid приходит argv-флагом `--masterPid` (его подставляет
`HttpServer::fromArgs()`), а сам сирота-чек — внутри `HttpServer`. `null` (вне
мастера) — проверка выключена. Чистый PHP, протокол не меняется.

> Для не-HTTP воркеров тот же приём: прочитать `--masterPid` из `argv` и периодически
> сверять с `posix_getppid()`, по расхождению — корректно завершаться.

## Ключевые решения и обоснования

- **`exec`, не `fork`.** Воркер — самостоятельный процесс; свой Go-рантайм/
  `Scheduler` инициализируется уже в потомке. Мастер не грузит расширение.
- **Команда — массивом, не строкой.** `proc_open([$php, ...$phpArgs, $script,
  ...$workerArgs], ...)`. Без `sh -c`-обёртки, иначе PID из `proc_get_status` —
  это PID шелла, и `posix_kill` не дойдёт до `php` (graceful сломается). Тот же
  приём уже применён в тест-харнессе `TestHttpServer`.
- **PID и reaping.** Живость — через `proc_get_status()['running']`; код выхода
  фиксируем при первом `running=false` и кэшируем; затем `proc_close()` (reap,
  иначе зомби). На зомби-детей `proc_close` обязателен.
- **Число ядер.** `Cpu::count()`: сначала `nproc` (`(int) shell_exec('nproc')`),
  фолбэк — подсчёт `processor` в `/proc/cpuinfo`, затем `1`. Linux-only (как и весь
  проект). Учитывать cgroup-квоты (контейнеры) — опционально, на будущее.
- **`reusePort` — обязанность воркера.** Мастер не навязывает его (он универсален),
  но в доке к HTTP-сценарию это требование: без `reusePort: true` второй воркер
  получит `EADDRINUSE`. Мастер передаёт воркеру свой pid флагом `--masterPid` (для
  сторожа); индекс воркера в текущей версии не прокидывается.

### Политика перезапуска

`RestartPolicy`:

- **`Always`** (по умолчанию) — перезапуск при **любом** выходе (чистом или
  аварийном). Естественное поведение супервизора долгоживущего сервера и
  **обязательное** для связки с `maxRequests` (ниже).
- **`OnFailure`** — перезапуск только при ненулевом коде или гибели по сигналу;
  чистый выход (код 0) считается «воркер закончил, не поднимать».
- **`Never`** — one-shot: отработал — и всё (мастер ждёт остальных и выходит).

#### Связь с `maxRequests` (перезапуск по исчерпанию лимита)

Фича [`HttpServer(maxRequests: N)`](../../docs/http-server.ru.md#остановка-после-n-запросов)
гасит воркер **штатно, с кодом 0** после N запросов (против утечек памяти). Чтобы
мастер поднял свежий процесс, нужна политика **`Always`** — поэтому она и дефолт.
При `OnFailure` чистый выход по лимиту **не** перезапустит воркер (это другой,
осознанный сценарий — «отработал пачку и хватит»).

Раннее закрытие листенера воркером (уже реализовано) + `SO_REUSEPORT` дают
**rolling-рестарт без потери трафика**: пока один воркер допросляживает in-flight и
перезапускается, ядро уводит новые соединения на соседей.

### Защита от краш-лупа (backoff)

`Always` + воркер, падающий сразу на старте (ошибка bind, синтаксис, отсутствие
`.so`), без защиты дал бы спин «спавн-падение-спавн». Поэтому **per-slot
экспоненциальный backoff**:

- считаем `uptime = exitedAt - startedAt`;
- `uptime < healthyUptimeMs` (≈1с) → `consecutiveFastFails++`, иначе сброс в 0;
- `backoff = min(maxRestartBackoffMs, restartBackoffMs * 2^(fails-1))`;
- backoff **не блокирует** цикл: ставим `slot.respawnAt = now + backoff`, остальные
  слоты продолжают супервизироваться;
- (опц.) после порога подряд-падений — пометить слот «сломан» и/или мастеру выйти с
  ненулевым кодом, чтобы внешний супервизор/человек заметил «воркер не поднимается».

### Сигналы и graceful shutdown

- **`pcntl_async_signals(true)`** + обработчики `SIGTERM`/`SIGINT` → взводят флаг
  `stopping`; ставятся **до** первого спавна и **восстанавливаются** на выходе
  (по образцу `HttpServer::installSignalHandlers`).
- **Остановка:** `stopping=true` → перестаём перезапускать → один раз шлём `SIGTERM`
  всем живым → ждём дренажа до `shutdownTimeoutMs` → выживших добиваем `SIGKILL` →
  `proc_close` всех → `exit(0)`. Идемпотентно к повторному `SIGTERM` во время
  остановки.
- **Воркеры дренажатся сами:** каждый получает `SIGTERM`, закрывает листенер,
  дослуживает in-flight, выходит — мастер лишь ждёт.
- **Группа процессов.** По умолчанию воркеры — в группе мастера, поэтому
  терминальный `Ctrl-C` (SIGINT всей группе) долетает и до них напрямую (двойная
  доставка безвредна — идемпотентно). При `kill -TERM <master-pid>` доставку
  обеспечивает форвардинг мастера. (Изоляция воркеров в свою группу/сессию —
  опционально, на будущее; `proc_open` из коробки этого не делает.)
- **`SIGHUP` → rolling reload** (опционально/стретч): перезапуск воркеров по очереди
  (TERM одному → дождаться → поднять новый) для горячей перезагрузки кода без
  простоя. В v1 можно не делать.

### Проброс окружения и аргументов

- `phpArgs` — флаги интерпретатора воркера (главное — `-d extension=…/sconcur.so`).
- `workerArgs` — `argv` скрипта (адрес, путь к конфигу).
- `env` — мержится поверх унаследованного (никаких служебных переменных мастер не
  добавляет — свои данные он передаёт через argv, см. ниже).
- мастер дописывает в `argv` воркера **`--masterPid`** — pid мастера (для сторожа;
  см. ниже), который `HttpServer::fromArgs()` подставляет в конструктор.

### Самозавершение осиротевших воркеров

Если мастер умрёт внезапно (краш, `SIGKILL`, OOM), воркеры **осиротеют**: ядро
переподвесит их к init/subreaper, и они продолжат жить, держа порт, — без надзора.
(Строго говоря, это не «зомби» — зомби это мёртвый-но-не-reaped; здесь живой-но-
бесхозный, что и есть проблема.) Чтобы такого не было, **воркер сам проверяет
живость мастера**:

- надёжный признак — **`posix_getppid()`**: воркер запущен прямым потомком мастера
  (команда массивом, без шелла), значит изначально `getppid() === masterPid`;
  после смерти мастера ядро меняет родителя → `getppid()` становится `1` (или pid
  subreaper). Это **не подвержено переиспользованию PID**, в отличие от
  `posix_kill($masterPid, 0)`;
- pid мастера передаётся argv-флагом `--masterPid`; `HttpServer::fromArgs()` кладёт
  его в `HttpServer(masterPid:)`, который сверяет `posix_getppid() === masterPid`;
- осиротев (getppid != masterPid), `HttpServer` запускает **свой graceful-дренаж**,
  дослуживает in-flight и выходит. Порт освобождается.

### Логирование (`logDir`, дневная ротация)

**Один дневной файл** на весь пул, без индекса воркера в имени — префикс `name`
(по умолчанию `sconcur-server`) + дата:

```
sconcur-server-2026-06-18.log
```

На **смене суток** мастер открывает новый дневной файл и **удаляет** файлы старше
`rotateDays` (по умолчанию **3**) — встроенная ротация по удержанию, без
`logrotate`. `rotateDays` передаётся параметром (`--rotateDays`); недоступный
`logDir` → `RuntimePathException`.

**Формат строки** — по конвенции логгера проекта: bracketed timestamp с
микросекундами, уровень, скоуп-тег, сообщение и хвостовой контекст-массив:

```
[Y-m-d H:i:s.uuuuuu] LEVEL [<scope>]: <message> [<context>]
```

- `LEVEL` — `INFO` | `WARN` | `ERROR`;
- `<scope>` — `master: <pid>` (записи мастера) или `worker: <pid> #<index>`
  (записи про конкретный воркер);
- `<context>` — JSON контекста (по умолчанию пустой `[]`).

```
[2026-06-18 12:00:00.173957] INFO [master: 12345]: start workers=8 script=/app/worker.php []
[2026-06-18 12:00:00.180210] INFO [worker: 12346 #0]: spawned []
[2026-06-18 12:00:00.181050] INFO [worker: 12347 #1]: spawned []
[2026-06-18 12:01:00.012044] ERROR [worker: 12346 #0]: exited code=1 uptime=60.0s; restarting in 200ms []
[2026-06-18 12:01:00.020000] ERROR [worker: 12346 #0]: PHP Fatal error: ... []   ← перехваченный stderr воркера
[2026-06-18 12:05:00.000100] INFO [master: 12345]: SIGTERM received, draining []
```

Что пишется:

- старт мастера (pid, число воркеров, `workerScript`, `runtimeDir`);
- спавн воркера (pid, индекс, команда);
- выход воркера: **код** или **сигнал** + **аптайм** (штатный выход по
  `maxRequests`, краш или убит сигналом) + решение о рестарте/`backoff` (или «не
  перезапускаю»);
- ошибки мастера (`proc_open` не стартовал, недоступен путь, лок занят);
- остановка (сигнал, дренаж, добивание, выход);
- **вывод падения воркера**: мастер **перехватывает stdout/stderr** воркера через
  пайпы `proc_open` и **переписывает построчно в тот же файл** со скоупом
  `worker: <pid> #<index>` (stderr → `ERROR`, stdout → `INFO`), чтобы трейс/фатал
  сохранялся в едином формате и сопоставлялся с записью о выходе. Пайпы дренажатся
  неблокирующе в супервизионном цикле. (Access-лог HTTP-сервера воркер пишет как
  настроено в его коде.)

### Состояние, единственный инстанс и перезапуск из системы

Три связанных требования решаются парой «эксклюзивный лок + state-файл» в
`runtimeDir`:

**Единственный инстанс — `flock`, не PID-файл.** На старте `run()` открывает
`runtimeDir/master.lock` и берёт `flock(LOCK_EX | LOCK_NB)`. Не удалось → уже есть
живой мастер → `MasterAlreadyRunningException` и выход. Хэндл лока **держится
открытым** всю жизнь мастера; ядро **снимает лок автоматически при смерти процесса**
(в т.ч. по `SIGKILL`) — поэтому **нет проблемы протухшего лока**, в отличие от
«проверить pid в файле». (PID-файл сам по себе подвержен гонке TOCTOU и протуханию;
`flock` — корректный примитив взаимного исключения. Linux/один хост; NFS-нюансы —
в открытых вопросах.)

**State-файл (наблюдаемый «флаг») — `runtimeDir/<name>-state.json`** (по умолчанию
`sconcur-server-state.json`). Пишется атомарно (temp + `rename`) на старте и
обновляется при изменениях (рестарты):

```json
{
  "pid": 12345,
  "startedAt": 1718700000.12,
  "workerCount": 8,
  "workerScript": "/app/worker.php",
  "address": "0.0.0.0:8080",
  "status": "running"
}
```

На **чистом выходе** мастер удаляет state-файл (или ставит `"status":"stopped"`).
На крахе файл остаётся «протухшим» (pid внутри уже мёртв).

**Перезапуск из системы.** Внешний guard (cron/таймер) поднимает мастер, если тот
не работает. Проще всего — командой `status` самого CLI (живость по локу,
exit-code):

```sh
# master-guard.sh, по таймеру (cron */1, systemd timer):
vendor/bin/sconcur-server status --configPath=/app/master.json >/dev/null \
  || exec vendor/bin/sconcur-server start --configPath=/app/master.json
# status != 0 (stopped/stale) → поднять.
```

`status` под капотом: пробует взять `flock` — держит живой мастер → код `0`
(running), свободен → код `3` (stopped). Иммунно к протухшему стейту и PID-reuse.
Сам `flock` страхует и от гонки двух guard'ов: второй получит
`MasterAlreadyRunningException` и тихо выйдет.

**Стоп = удаление стейт-файла.** Стейт-файл — управляющий: мастер на каждом тике
проверяет его наличие и при пропаже запускает graceful-стоп (как по `SIGTERM`).
`stop` именно это и делает — удаляет файл и ждёт освобождения лока. `/tmp`-клинер,
стерев файл, тоже остановит мастер (внешний супервизор поднимет). Логируется `WARN`.

> **Семантика «всегда поднят».** Guard-модель перезапускает мастер и после
> **намеренной** остановки (чистый выход → файла нет → guard поднимает снова). Если
> нужна возможность осознанно «выключить и не поднимать» — нужен отдельный маркер
> «disabled» или управление через systemd (`enable`/`disable`/`stop`). Это
> осознанный компромисс flag-файловой модели, отметить в доке.

## Нюансы

- **Синхронный выход по `maxRequests` («thundering herd»).** При ровной нагрузке
  воркеры считают запросы независимо и могут достичь лимита почти одновременно →
  одновременный выход/перезапуск → кратковременный провал ёмкости. Митигации:
  (1) **джиттер** лимита на воркер (`maxRequests + index*k`, см. пример воркера);
  (2) мастер слегка **разносит** перезапуски (per-slot stagger). С `SO_REUSEPORT`
  провал и так смягчён (трафик уходит к живым), но джиттер желателен.
- **Без `pcntl`/`posix` graceful невозможен** — мастер бросает `MissingPcntlException`
  на старте (в docker-образах проекта оба включены).
- **`workerCount` валидируется** (`>= 0`); `0` → число ядер. Слишком большое N
  имеет смысл только при наличии ядер — иначе оверхед контекст-свитчей.
- **Воркер, не сумевший забиндиться** (нет `reusePort`, занятый порт), упадёт сразу
  → сработает backoff. Логи мастера должны это показать (код выхода/последний
  stderr воркера).
- **stdout/stderr воркеров** мастер перехватывает через неблокирующие пайпы и
  переписывает построчно в общий дневной лог со скоупом `worker: <pid> #<index>`
  (см. «Логирование»). Частичные строки буферизуются до `\n`; пайпы дочитываются
  после выхода процесса, чтобы не потерять хвост падения.
- **`vendor/bin` и пути.** bin-файл резолвит автозагрузчик из обоих расположений
  (как зависимость и in-repo); пути воркера/каталогов потребитель передаёт
  абсолютными (cwd мастера под systemd не гарантирован).
- **Только Linux/NTS/CLI.** Как и вся библиотека.

## Этапы внедрения

1. [x] `Cpu::count()` + `WorkerProcess` (proc_open массивом, статус/код/сигнал/reap)
   + исключения `src/Exceptions/Worker/`.
2. [x] `WorkerMaster`: спавн N слотов, супервизионный цикл, `RestartPolicy::Always`,
   проброс `phpArgs`/`workerArgs`/`env` + argv-флаг `--masterPid`.
3. [x] Сигналы: установка/восстановление обработчиков, `stopping`-флаг, форвардинг
   `SIGTERM`, дренаж до `shutdownTimeoutMs`, добивание `SIGKILL`, чистый выход.
4. [x] Backoff против краш-лупа (per-slot, неблокирующий).
5. [x] **Единственный инстанс + state:** `MasterLock` (`flock EX|NB`),
   `MasterState` (`<name>-state.json`, атомарно write/update/clear), `runtimeDir`.
6. [x] **Логирование:** `MasterLogger` → один дневной `<name>-Y-m-d.log` с
   ротацией по `rotateDays` (3) и форматом
   `[Y-m-d H:i:s.uuuuuu] LEVEL [scope]: msg [ctx]`; перехват stdout/stderr
   воркеров (неблокирующие пайпы) → построчно в тот же файл.
7. [x] **CLI `bin/sconcur-server`** + `MasterCli`: разбор `argv`, команды
   `start` (= `WorkerMaster::run`), `status` (живость по `flock`, exit-codes),
   `stop` (удаление стейт-файла + ожидание освобождения лока). Регистрация в
   `composer.json` `"bin"`,
   резолв автозагрузчика.
8. [x] **Самозавершение осиротевших воркеров:** `masterPid: ?int` в `HttpServer`
   (сирота-чек через `posix_getppid` в `shouldStop`); хелпер
   `SConcur\Worker\Worker` (`masterPid`/`index`/`count`).
9. [ ] (опц.) `SIGHUP` rolling reload; (опц.) `--daemon` (форк мастера); (опц.)
   изоляция группы процессов; (опц.) порог краш-лупа → выход мастера с ошибкой.
10. [x] Документация `docs/worker-master.ru.md` (CLI `start/status/stop`, параметры,
    связка с `reusePort`/`maxRequests`, сирота-чек, lock+state+guard, ротация логов,
    отличия от systemd) + пункт в `README` «Планы» → «реализовано» и ссылки из
    `.ai/README.md`/`docs/http-server.ru.md`. Параметр `masterPid` — также в
    `docs/http-server.ru.md`.

Версию расширения **не трогаем** — мастер целиком на PHP, протокол PHP↔Go не
меняется.

## Тестирование

Без зависимости от docker-сервиса — как HTTP-тесты (свои процессы на loopback):

- **`Cpu::count()`** — возвращает `>= 1`.
- **`WorkerProcess`** — спавн короткого скрипта, `pid()` живой, `exitInfo()` после
  выхода отдаёт код; `signal()` доходит (PID — это `php`, не шелл).
- **`MasterCli`** — юнит: нет `--configPath` → usage; битый/отсутствующий конфиг,
  нет `workerScript`, неизвестный `restartPolicy` → понятная ошибка + ненулевой код;
  `status`/`stop` при отсутствии стейта.
- **Харнесс `TestWorkerMaster`** (по образцу `TestHttpServer`): пишет JSON-конфиг и
  `proc_open` `bin/sconcur-server start --configPath=…`; `pid()`, `signal()`, `waitForExit()`,
  чтение лога. Воркером выступает общий демо-сервер
  `tests/servers/http/http-server.php` — `HttpServer::fromArgs($_SERVER['argv'])` с
  `reusePort`, `--masterPid` и маршрутом `/pid` (отдаёт `getmypid()`), чтобы тест
  считал уникальные PID за пулом.
- **Сценарии:**
  - N воркеров стартуют и **все** обслуживают (набрать ≥N уникальных PID через `/pid`
    по купле соединений).
  - **`Always`-рестарт:** убить один воркер (`SIGKILL` по PID из `/pid`) → пул снова
    выдаёт полный набор PID (появился новый).
  - **Самовыход по `maxRequests`:** малый лимит → воркер выходит сам → мастер поднял
    новый (PID сменился), трафик не прерывался (`SO_REUSEPORT`).
  - **Graceful:** `SIGTERM` мастеру с in-flight `/msleep/...` → запрос дослужен (200),
    все воркеры вышли, мастер вышел `0` в пределах `shutdownTimeoutMs`.
  - **Backoff:** воркер-скрипт, падающий мгновенно → мастер **не спинит** (частота
    спавнов растёт по backoff; проверяем по таймстампам в дневном логе), и не
    упирается в CPU.
  - **Единственный инстанс:** второй `start` с тем же `runtimeDir` →
    `MasterAlreadyRunningException` (ненулевой код); после выхода первого — второй
    стартует.
  - **CLI `status`/`stop`:** при работающем мастере `status` → код `0` + «running»;
    `stop` → удаление стейт-файла, дренаж, выход; после — `status` → код `3`.
    После `SIGKILL` мастера `status` → код `3` («stale», pid мёртв).
  - **State-файл:** на старте появляется `sconcur-http-server-state.json` с живым
    pid; на чистом выходе — удаляется; после `SIGKILL` остаётся «протухшим».
  - **Сирота-чек:** `SIGKILL` **мастеру** (не воркерам) → воркеры замечают смену
    `getppid()` и **сами** дренажатся и выходят (порт освобождается); проверяем, что
    не остаётся живых бесхозных процессов и порт снова свободен.
  - **Логи и ротация:** единый дневной `sconcur-http-server-Y-m-d.log` содержит
    строки формата `[ts] LEVEL [master: pid]: …` для старта/спавна/выхода/рестарта, а
    вывод падения воркера — строкой `[ts] LEVEL [worker: pid #index]: …` (перехвачен из stderr);
    файлы старше `rotateDays` подчищаются (тест с подсунутыми «старыми» датами).
  - (опц.) **`SIGHUP`** rolling reload: PID-ы сменяются по одному, обслуживание не
    прерывается.

## Открытые вопросы

- ~~**Env-фолбэк для `runtimeDir`/`name`**~~ (**решено**). Все команды берут один
  `--configPath`, а `runtimeDir`/`name` читаются из конфига — `status`/`stop`
  автоматически согласованы со `start` без дублирования флагов.
- **`--daemon`** (стретч, не сделано) — форк мастера в фон (мастеру это можно, он без
  Go-рантайма). Сейчас `start` только foreground (под systemd/docker/guard — норм).
- **Rolling reload** (**запланировано, отложено** — делать в следующей итерации).
  Горячая перезагрузка кода воркеров без простоя: перезапуск воркеров по одному
  (по мере дренажа), полагаясь на ранее закрываемый листенер + `SO_REUSEPORT`, как и
  при `maxRequests`-ротации. Механизм: новая CLI-команда `reload` (через тот же
  `--configPath`) — снимает текущий мастер сигналом и/или толкает его перезапустить
  пул; на стороне мастера — обработчик `SIGHUP`, который инициирует rolling-рестарт
  слотов вместо полного выхода. Требует аккуратной координации с backoff/`respawnAt`
  и graceful-дренажом каждого слота.
- **Изоляция группы процессов** воркеров (`setsid`-аналог) — нужно ли, и как
  без нативного `setsid` в `proc_open` (возможен крошечный launcher-шим).
- **Порог краш-лупа** и поведение при его достижении: «сломанный» слот vs выход
  мастера с ошибкой (чтобы заметил внешний супервизор/guard). Сейчас backoff растёт
  до потолка, но мастер не сдаётся.
- **cgroup-aware число ядер** в контейнерах (квоты CPU vs физические ядра).
- **`flock` на NFS/разных ФС** — на сетевых ФС семантика блокировок ненадёжна;
  фиксировать «`runtimeDir` — локальная ФС (tmpfs/`/run`)».
- **Интервал сирота-чека** (тик = период поллинга `serve()`, ~250мс) —
  достаточно ли; не нужен ли отдельный, более частый таймер.
- **Ротация логов по размеру.** Ротация **по дням** + удержание `rotateDays`
  реализованы; лимит по размеру/число строк — нет (можно отдать `logrotate`).
- **Health-check/метрики воркеров** (живость, RPS, рестарты) — стык с state-файлом и
  roadmap-пунктом «Админка со статистикой сервера».
