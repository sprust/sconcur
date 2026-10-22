# Ревью ветки `feature/fork-master` перед релизом

Дата: 2026-10-20. База сравнения: `master...HEAD`, 27 коммитов (`3445360`…`6d79efc`),
161 файл, +14 277 / −2 608 строк. План: [fork-master.md](fork-master.md).

Что проверялось: оптимизация, пахнущий и мёртвый код, отклонения от правил
`.ai/README.md`, переусложнение, корректность автоматов процессов и reload,
безопасность fork-пути, журнал, приём с EPOLLEXCLUSIVE, качество тестов и бенчей,
согласованность доков с кодом.

Как проверялось: три независимых прохода по срезам (Rust-ядро `ext/`; `src/Worker/`;
остальной PHP, тесты, бенчи, сборка), каждая находка перепроверена по коду до включения
сюда. Статика и тесты на момент ревью:

| Проверка | Результат |
|---|---|
| `make php-stan` (уровень 6, 553 файла) | без ошибок |
| `make cs-fixer-check` (538 файлов) | без ошибок |
| `cargo test --lib` (в контейнере `sc-php`) | 253 прошли, 0 упали, 1 пропущен |
| `cargo fmt --check` | чисто |
| `cargo clippy` по файлам ветки | ничего нового; 15 ошибок и 33 предупреждения — все в коде до ветки |
| `make test` с фильтром `Worker\|Process\|ExtensionFork\|ListenFd\|ServeMarks` | 208 тестов, 1554 проверки, зелёные |

Вердикт: статика и тесты чистые, но релизить рано — в автомате reload
`ForkServerGroup` три реальных бага, и в ядре несколько проглоченных ошибок на путях,
где молчание стоит дорого (fork, `_exit`, приём соединений).

## Содержание

- [Баги](#баги)
- [Устаревшие докблоки](#устаревшие-докблоки)
- [Мёртвый код](#мёртвый-код)
- [Дублирование и переусложнение](#дублирование-и-переусложнение)
- [Отклонения от правил](#отклонения-от-правил)
- [Мелочи](#мелочи)
- [Проверено и в порядке](#проверено-и-в-порядке)
- [Порядок починки](#порядок-починки)

## Баги

Нумерация сохраняется в коммитах починки.

### 1. Откат reload теряет слоты, если умер fork-сервер нового поколения

`src/Worker/ForkServerGroup.php`, `handleExit()` и `driveReload()`.

При смерти процесса поколения `next` `handleExit()` сначала делает `$this->next = null`,
потом зовёт `abortReload()`. Тот ставит `reverting = true` и кладёт в `$this->steps`
шаги «вернуть уже перенесённые слоты (`$this->moved`) в `current`». На следующем тике
`driveReload()` видит `$next === null` и сразу зовёт `finishReload()`, который стирает
`steps`. Слоты, которые `current` уже отпустил по команде `release` (его `WorkerGroup`
снял слот и отменил респаун), никто не поднимает. Группа навсегда работает с меньшим
числом воркеров, журнал при этом пишет «reload given up … keep running».

Фикс: ранний выход в `driveReload()` только когда не откатываемся —
`if ($next === null && !$this->reverting)`. Шаги отката с `from === null` уже корректно
обрабатываются в `driveStep()`/`startRelease()`. Теста на этот путь нет:
`BootstrapWorkerMasterTest` проверяет только отказ при загрузке, без перенесённых слотов.

### 2. Смерть `current` во время отката оставляет группу без единого поколения

`src/Worker/ForkServerGroup.php`, `handleExit()`, ветка `$generation === $this->current`.

При `reverting` шаги имеют `to = current`. Если `current` умирает, `handleExit()` ставит
`$this->current = null` и выходит по условию `$this->reloading` — комментарий «the next
generation takes every slot anyway» для отката неверен. `driveStep()` продолжает с
устаревшим объектом в `$step['to']`: `spawn()` → `send()` молча ничего не пишет (пайп
закрыт в `close()`), ждёт `readyTimeoutMs` на каждый слот, затем, раз `reverting`,
делает `startRelease()` — отпускает слот у `next`, единственного живого поколения. В
конце `finishReload()` останавливает `next`; `respawnAtNs` остаётся `null`. Итог: ни
одного поколения, `allSlotsEmpty()` = true, ничего не запланировано.

Фикс: в `handleExit()` для `$generation === $this->current && $this->reverting` делать
то же, что ветка `current === null` в `abortReload()`: сбросить `reverting`, очистить
шаги, поднять недостающие слоты в `next`, `finishReload()`. Плюс в фазе `spawn`
проверять `!$to->isRunning()`.

### 3. Fork-сервер, упавший после `loaded`, респаунится без бэкоффа

`src/Worker/ForkServerGroup.php`, `handleEvent()` (`EVENT_LOADED`) и `handleExit()`.

`failedLoads` растёт только при смерти до `EVENT_LOADED` и сбрасывается в 0 по
`EVENT_LOADED`. Поколение, которое загрузилось и умерло через секунду (OOM, segfault в
цикле), получает `backoffMs = 0` — новый php плюс загрузка bootstrap каждые 100 мс,
бесконечно. `WorkerGroup` для воркеров считает «быстрый провал» по аптайму
(`HEALTHY_UPTIME_SECONDS`); здесь аналога нет.

Фикс: считать провалом смерть не только до загрузки, но и до первого `EVENT_READY`
поколения или при аптайме процесса меньше порога; сбрасывать счётчик по READY (или по
аптайму), а не по LOADED.

### 4. `kill` и `reap` не проверяют `pid > 0`

`src/Process/ProcessControl.php` (`kill()`, `reap()`), `ext/src/process/mod.rs`
(`libc::kill`, `libc::waitpid`).

Ни PHP, ни Rust не отказывают на `pid <= 0`. `kill(0, TERM)` сигналит всей группе
процессов вызывающего, включая мастер; `kill(-1, TERM)` — всем процессам пользователя;
`reap([0])`/`reap([-1])` ждёт любого ребёнка (например, `proc_open`'нутый CLI) и
отчитывается о нём под запрошенным pid. Сегодня все вызовы с pid из `ForkResult`,
`proc_get_status` и отчётов fork-сервера, живого пути нет, но `ForkResult` ребёнка
(pid 0), попавший не на ту сторону, — ровно тот баг, который этот API обязан отвергать.

Фикс: в обоих методах (и в Rust) `pid <= 0` → `ProcessException` с текстом вида
`process: pid %d is not a child`; для `reap` — на каждый элемент.

### 5. Ребёнок без `/dev/null` молча наследует все дескрипторы родителя

`ext/src/process/fork.rs`, `prepare_child()`.

Если `open("/dev/null")` вернул `< 0`, `neutralize()` не вызывается: ребёнок оставляет
stdin и каждый дескриптор родителя — lock-файл, пайпы соседних воркеров, events-пайп —
ровно то, от чего `neutralize` защищает. Родитель об этом не узнаёт.

Фикс: `prepare_child` возвращает `Result`; при ошибке — одна строка на fd 2 и `_exit`
(ребёнок, которого нельзя изолировать, не должен запускать скрипт воркера).

### 6. Флаш журнала перед `_exit` через `try_send`

`ext/src/logger/mod.rs`, `Logger::flush()`; `ext/src/process/mod.rs`, `exit()`.

`flush()` шлёт `Message::Flush` через `try_send`; при полной очереди (4096 строк) `Flush`
отбрасывается и вызывающий идёт дальше. Новое на ветке: `process::exit()` опирается на
этот флаш прямо перед `_exit`, так что воркер, выходящий под нагрузкой на журнал, молча
теряет до 4096 строк плюс накопленную пачку. В `master` был тот же `try_send`, но за ним
не следовал `_exit`.

Фикс: `sender.send_timeout(Message::Flush(done), Duration::from_secs(1))`, с прежним
`recv_timeout` в 1 с.

### 7. Поток приёма с EPOLLEXCLUSIVE умирает молча

`ext/src/features/httpserver/exclusive.rs`, цикл потока: ветки с
`control(epoll, EPOLL_CTL_ADD, …).is_err()` и `EPOLL_CTL_DEL`.

При ошибке `epoll_ctl` поток делает `return`; отправитель канала дропается,
`Acceptor::accept()` получает `None` и уходит в `pending().await` навсегда — без строки
в журнале и без ошибки серверу. Циклы приёма узнают об этом только через
`stop_accepting`.

Фикс: перед выходом отправить ошибку в канал (`accepted.blocking_send(Err(error))`), чтобы
`accept()` хотя бы раз вернул `Err`, и/или `crate::logger::write` с диагностикой.

### 8. Ошибка записи access-файла проглочена

`ext/src/logger/mod.rs`, запись пачки в `self.file`.

`let _ = file.write_all(&self.access_buffer)` — `ENOSPC`/`EIO` на файле дня теряет пачку
молча, тогда как ошибка *открытия* сообщается один раз и уходит в fallback на stdout.

Фикс: на `Err` — `writeln!(self.errors, "access log: cannot write {path}: {error}; access
lines go to stdout")`, `self.file = None`, пачку в `self.output` (тот же fallback, что
при ошибке открытия; следующий день переоткроет).

## Устаревшие докблоки

После появления fork-сервера (коммит `cec4974`) часть комментариев описывает мир, где
форкал сам мастер.

- `src/Worker/LaunchMode.php`, докблок enum: «Fork copies the master … runs on the
  master's interpreter and php.ini, and a reload picks up new application code only —
  the library and the extension stay as they were». Противоречит коду: fork-сервер —
  отдельный php с `phpBinary`/`phpArgs` группы (`ForkServerGroup::launch`), а
  `bin/sconcur-fork-server` прямо говорит, что reload грузит новый релиз «the
  application and this library both». Публичный enum с неверным контрактом.
- `src/Worker/ForkedWorkerProcess.php`, докблок класса: «forking the master», «In the
  master this object…». `src/Worker/WorkerProcess.php`, докблок класса.
  `src/Worker/WorkerGroup.php`, параметр `$inheritedDescriptors` («the master found
  open»). Везде должно быть «fork server». Комментарий в
  `tests/feature/Worker/ForkedWorkerMasterTest.php` («the copy of the master») — туда же.
- `src/Worker/ForkServerGroup.php`, около `guardsStartup`: «Only the first generation
  … can stop the master», а код даёт `guardsStartup` каждому поколению из `spawnAll`,
  пока `!cameUp`. Поведение разумное, комментарий нет.
- Докблоки лимитов не знают про разброс под мастером:
  `src/Features/HttpServer/HttpServer.php` (`$maxRequests` «stop the server after it
  has handled this many requests»), `src/Features/SocketServer/SocketServer.php` и
  `src/Features/WsServer/WsServer.php` (`$maxConnections`),
  `src/Features/Amqp/Consumer/QueueConsumer.php` (`$maxMessages`).
  `Scheduler::serve()` пропускает их через `spreadLimit()`, и под мастером консьюмер с
  `maxMessages=6` остановится где-то в 6–9 (диф `AmqpConsumerPoolTest` обходит ровно
  это). Говорят об этом только докблок `serve()` и `docs/worker-master.md`. Фикс: одна
  фраза в каждом из четырёх докблоков.
- `src/Features/HttpServer/HttpServer.php`, `SocketServer.php`, `WsServer.php` — над
  `watchForStop()` всё ещё «Install handlers before starting the listener … restore the
  previous ones when serving ends»; в `QueueConsumer.php` текст обновили
  («Watched before the stream opens…»), в серверах нет.
- `src/Worker/MasterConfig.php`, `toWorkerMaster()`: два докблока подряд, первый
  («Builds the supervisor.») мёртвый.

## Мёртвый код

- `src/Process/ProcessSignalEnum.php`: `Hangup`, `Quit`, `User2`, `Child` без единой
  ссылки в `src/`, `tests/`, `bin/`; `User1` только в `ProcessControlTest`. Таблица в
  `ext/src/process/signals.rs` знает все восемь, так что мёртвые — PHP-кейсы. Либо
  убрать четыре (и `User1`, если тестовый кейс не нужен), либо в докблоке enum сказать,
  зачем экспортирована полная таблица.
- `src/Process/ProcessControl.php`, `threadCount()` + `ProcessCommandEnum::ThreadCount`
  + арм `b"thr"` в `ext/src/process/mod.rs`: в продакшене не вызывается (`ForkServer`
  использует `assertForkable()`); единственный пользователь —
  `ProcessControlTest::testAProcessThatNeverStartedTheRuntimeRunsOneThread`. Целый
  экспорт расширения ради одного теста. Убрать, или вставить число потоков в сообщение
  `ForkRefusedException`, чтобы отказ говорил, сколько нашёл.
- `src/Process/Dto/ProcessExit.php`, `isClean()` — нет вызовов.
- `src/Worker/SupervisedGroup.php`, `startReload()` — мастер его не вызывает (только
  `reconfigure()`); `ForkServerGroup::startReload()` мёртв целиком,
  `WorkerGroup::startReload()` нужен лишь как внутренний шаг `reconfigure()`. Убрать из
  интерфейса, в `WorkerGroup` сделать `protected`.
- `src/Worker/WorkerGroup.php`, `listenFd()` и `slots()` — вызовов нет ни в `src`, ни в
  `tests`.
- `src/Worker/ForkServerGeneration.php`, `public readonly WorkerGroupConfig $config` —
  нигде не читается.
- Ветки «reconfigure/retire во время reload»: `ForkServerGroup::reconfigure()` и
  `retire()` при `reloading`, `WorkerGroup::reconfigure()` и `retire()` то же —
  недостижимы, потому что `WorkerMaster::checkReloadSignal()` не читает триггер, пока
  `anyPoolReloading()`. Защитный код, который нельзя проверить через мастер; либо
  убрать, либо оставить одну явную проверку в мастере.
- `ext/src/features/httpserver/listen.rs`, `pub fn listen` — используется только `open()`
  в том же модуле (все три сервера перешли на `open`). Сделать приватной.

## Дублирование и переусложнение

Про главный вопрос — не переизобретает ли `ForkServerGroup` (975 строк) `WorkerGroup`:
нет. Слоты, бэкофф и сторож воркеров делает `WorkerGroup` внутри fork-сервера,
композиция через процессную границу невозможна. Но автомат «поколений»
(`planSteps`/`driveStep`/`startRelease`/`abortReload`/`finishReload`, ~250 строк)
структурно параллелен `reloadQueue`/`reloadCandidate`/`promoteCandidate`/`abortReload`/
`finishReload` в `WorkerGroup`, и мелкие куски скопированы буквально.

- `$workerCount > 0 ? $workerCount : Cpu::count()` — девять раз по `src/`
  (`ForkServerGroup` ×4, `WorkerGroup` ×3, `MasterCli`, `MasterConfig`). Нужен
  `WorkerGroupConfig::resolvedWorkerCount()`.
- Формула бэкоффа `restartBackoffMs * 2 ** (n-1)` с капом `maxRestartBackoffMs` —
  `ForkServerGroup::handleExit()` и `WorkerGroup` (респаун воркера). Метод на
  `WorkerGroupConfig`.
- `reportWatchdogEvent()` с try/catch — `ForkServerGroup` и `WorkerGroup`; try/catch
  логичнее один раз в `WorkerMaster::watchdogReporter()`, тогда группы просто зовут
  замыкание.
- Формат причины выхода `signal=%d`/`code=%d` — `ForkServerGroup::handleExit()` против
  `WorkerGroup::exitReason()`. Статический хелпер на `WorkerProcess`.
- `TICK_MICROSECONDS`, `SIGKILL_GRACE_SECONDS` и последовательность «TERM → дедлайн →
  KILL → grace → выход» — `ForkServer::loop()` и `WorkerMaster::driveShutdown()`.
- `src/Worker/SupervisedGroup.php` — 20 методов, из них `driveDraining()` и
  `driveWatchdog()` у `ForkServerGroup` пустые, `respawnDue()` вызывается рядом. Один
  `tick()` вместо трёх сократит интерфейс и уберёт no-op'ы.
- `src/Worker/ForkServerGroup.php`: шаг reload — `array{slot, from, to}` (тип повторён
  трижды в PHPDoc), фаза — строка `'spawn'|'release'`. Readonly-DTO `SlotMove` плюс
  enum фазы дали бы типизацию, которую требует гайд, и читаемый `driveStep()`.
- `src/Worker/ForkServer.php`: восемь фиктивных значений `MasterDefaults` только чтобы
  прогнать `toArray()` через `fromArray()`. Лучше
  `WorkerGroupConfig::fromResolvedArray()` без defaults.
- Три сервера: описание слушателя `$this->listenFd === null ? $this->address : 'fd ' .
  $this->listenFd` и замыкание `shouldStop: static fn(): bool =>
  $stopRequest->isRequested()` — по три копии (`HttpServer`, `SocketServer`,
  `WsServer`). `protected function describeListener(): string` в трейте (там уже есть
  `logServerEvent`) и first-class callable `$stopRequest->isRequested(...)`.
- `src/Features/Server/ServerRuntimeSupportTrait.php`, `watchForStop()` —
  однострочная статическая обёртка над `StopRequest::watch()`; докблок трейта всё ещё
  утверждает, что слежение за остановкой живёт в нём. Либо серверы зовут
  `StopRequest::watch()` напрямую, либо убрать фразу из докблока.
- `freePort()` скопирован ещё четыре раза на ветке
  (`tests/feature/Features/HttpServer/HttpServerListenFdTest.php`,
  `tests/feature/Scheduler/SchedulerServeMarksTest.php`,
  `tests/feature/Connection/ExtensionForkTest.php`, `tests/benchmarks/worker/memory.php`),
  хотя `TestWorkerMaster::freePort()` в этом же дифе сделан публичным ради этого
  (8 копий в `master`, 12 на HEAD). Использовать его или перенести в
  `tests/impl/PhpScript.php` рядом с `projectRoot()`/`extensionPath()`.
- `tests/feature/Features/Amqp/AmqpConsumerTelemetryTest.php` заново выводит
  `getenv('SCONCUR_EXT') ?: dirname(__DIR__, 4) . '/ext/build/sconcur.so'` и
  `dirname(__DIR__, 4)`; `PhpScript::extensionPath()` и `PhpScript::projectRoot()`
  добавлены на этой ветке ровно для этого.
- `ext/src/features/socketserver/listen.rs` — модуль из одной функции-форварда на
  `httpserver::listen::open`, тогда как `wsserver/mod.rs` зовёт
  `httpserver::listen::open` напрямую. Форма старая, но ветка трогала оба; выбрать одно.

## Отклонения от правил

- `src/Process/ProcessControl.php`, докблок `assertForkable()`: `@throws
  ForkRefusedException` — запрещён гайдом («No `@throws` anywhere»).
- `tests/feature/Process/ProcessControlTest.php`: полное имя
  `SConcur\Features\Sleeper\Sleeper::usleep(...)` внутри heredoc сценария, хотя
  `PRELUDE` уже содержит блок `use`.
- `src/Worker/ForkServer.php`, `loop()`: `$deadline` хранит наносекунды без единицы в
  имени; мастер те же величины зовёт `stopDeadlineNs`/`killDeadlineNs`.
- Позиционные вызовы проектных методов с двумя и более параметрами в новом коде:
  `$this->logger->master(MasterLogger::X, '...')` — `ForkServer.php` (четыре места),
  `WorkerMaster.php` (одно); `MasterConfig::readyTimeoutMs($data, …, $name)` и
  `::accessLog(...)` — `WorkerGroupConfig.php`; `ProcessControl::kill($pid, $signal)` —
  `ForkServerGroup.php`, `WorkerProcess.php`. У всех есть прецедент в `master`
  (семь таких `->master(` уже было), так что вопрос — «узаконить исключение в
  `.ai/README.md` или чинить везде».
- `ext/src/process/signals.rs`: неизвестное имя сигнала даёт `Error::Failed`
  (→ `ProcessException`, RuntimeException), хотя по определению самого модуля это
  ошибка вызывающего (`Refused` → LogicException).
- `.ai/README.md`, раздел Tests: список бенч-каталогов не содержит новый
  `tests/benchmarks/worker/` (`bench-worker-memory`).
- `ProcessControl.php`: `static::close()` среди сплошных `self::` в том же файле.
- `tests/impl/Worker/TestWorkerMaster.php`: `private const array GROUP_KEYS` и
  `tests/impl/PhpScript.php`: `private static function open` — правило про `protected`
  сформулировано для свойств, но дух тот же; в `master` прецеденты `private` в тестах
  есть. Низкий приоритет.

## Мелочи

- `tests/feature/Scheduler/SchedulerServeMarksTest.php`: клиент — один раз
  `usleep(300000)` и `@file_get_contents(...)`. Если сервер не успел за 300 мс, запрос
  молча падает, `maxRequests: 1` не достигается и `serve()` виснет навсегда — это
  вешает весь прогон PHPUnit (per-test таймаута нет). Нужен цикл с дедлайном, как
  `$ask()` в `HttpServerListenFdTest`.
- `src/Scheduler/Scheduler.php`, `serve()`: готовность выводится из «первый
  `takeReadyResult()` не вернул ошибку серверной задачи». При `SERVE_POLL_INTERVAL_MS
  = 250` ошибка bind почти всегда приходит первой, но результат чужого флоу (корутина,
  запущенная до `serve()`) или таймаут поллинга тоже пометят воркер готовым, пока
  слушатель ещё может падать. Комментарий в коде это признаёт. Точным сделал бы
  положительный сигнал — первое событие потока «listening».
- `ForkServerGroup::aliveSlotCount()` считает сам fork-сервер как +1 → «shutdown
  timeout; sending SIGKILL to N worker(s)» и «N worker(s) still alive after SIGKILL»
  завышены на число поколений.
- `WorkerMaster::checkNeverCameUp()`: «%d failed before any said it was up» подставляет
  `workerCount()`; для fork-группы, чей bootstrap не загрузился, ни один воркер не
  падал.
- `ForkServerGroup::abortReload()`: «the workers it had not reached keep running» — для
  fork-группы ещё и «the ones it reached are moved back»; сообщение скопировано из
  exec-ветки.
- `MasterCli::reloadTimeoutMs()` не учитывает загрузку нового поколения (ещё один
  `readyTimeoutMs`) и `RELEASE_GRACE_MS` на слот — в худшем случае CLI сдастся раньше
  мастера.
- `ForkServerGroup::spec()`: если автозагрузчик не найден, `autoload = ''` и
  `bin/sconcur-fork-server` падает с «Failed opening required ''». Стоит сообщить явно.
- `ForkServerGroup::launch()` при `WorkerSpawnException` использует плоский
  `restartBackoffMs` и не выставляет `failedToStart` — расхождение с `handleExit()`;
  на практике недостижимо (несуществующий `phpBinary` даёт процесс с кодом 127, а не
  исключение).
- `ext/src/logger/mod.rs`: `let _ = std::thread::Builder::new()...spawn(...)` — если
  спавн провалился, приёмник дропается, каждая следующая строка считается в `DROPPED`,
  и никто об этом не сообщит (репортёр — тот поток, что не стартовал). На `Err` одна
  строка на fd 2 и `sender` не выставлять.
- `ext/src/process/fork.rs`, комментарий у heartbeat: «only the write end stays,
  close-on-exec, so nothing this worker execs can mark it alive» — верно для fd, который
  отдаёт Rust, но `ProcessControl::adopt()` тут же переоткрывает его через
  `fopen('php://fd/N')` (это `dup`) и закрывает N; несёт ли дубль `FD_CLOEXEC`, зависит
  от слоя потоков PHP. Проверить на стороне PHP или смягчить комментарий.
- `ext/src/logger/mod.rs`, ротация: по дате на момент записи (проверка раз на пачку);
  строка со штампом 23:59:59.99, записанная в 00:00:00.05, попадёт в файл следующего
  дня, а файл, удалённый или переименованный среди дня, пишется через старый inode до
  смены даты (reopen по сигналу нет). Согласуется с докблоком модуля; просто знать, что
  logrotate внутри дня не поддержан. Не дефект.
- `tests/benchmarks/worker/memory.php`: `proc_terminate($master, SIGTERM)` зависит от
  константы pcntl в ветке, смысл которой «pcntl не нужен»; `proc_terminate($master)`
  и так шлёт 15.
- `tests/feature/Process/ProcessControlTest.php`, `'deliveredAgain'`: проверка, что
  `kill()` по только что собранному pid вернёт false — при переиспользовании pid на
  занятом хосте иногда будет true (или `EPERM` → исключение). Убрать или принять флак.

## Проверено и в порядке

- Правила: четыре источника версии равны `0.17.0` (`ext/src/lib.rs`, `ext/Cargo.toml`,
  `src/Connection/Extension.php`, `composer.json`), бамп ровно один (`ee1244e`).
  Ссылок на `.ai/plans` вне `.ai/` нет. Кириллицы в коде нет (хиты — старые тестовые
  фикстуры `files/pattern.rs`, `files/content.rs`). `private`/`final` в `src/` нет,
  ведущих `\` нет, `throw new RuntimeException/LogicException` нет, сокращённых
  переменных нет (`$pid`, `$env`, `$args` — устоявшиеся имена проекта), DTO `readonly`.
  `pcntl_*`/`posix_*` из `src/` и `bin/` ушли полностью; `MissingPcntlException` нигде
  не остался. Все изменённые `.md` — парами (единственное одиночное изменение,
  `docs/coroutine-context.ru.md`, — правка русской формулировки без смены смысла).
- Ядро: `process` синхронен и без рантайма — везде `core::built()`, не `core::get()`;
  fork отказывает при собранном ядре **или** `num_threads > 1` из `/proc/self/stat`.
  Статики переехали в `Core`: `States`, `stats::Registry`, `Logger`, `Telemetry` AMQP,
  флаги чистильщиков redis/sql. Оставшиеся статики — неизменяемые фабрики, монотонные
  счётчики id, состояние сигналов, явно сбрасываемое в ребёнке (`reset_in_child`), и
  `logger::DROPPED` (писатель снимает его на старте). `CORE` на `AtomicPtr`:
  `Acquire`/`Release` парные, `BUILD` сериализует сборку, `FORKED_IN_CHILD.swap` и
  null-store перед пересборкой, `Once` для `pthread_atfork` наследуется завершённым.
- Fork-путь: пайпы `O_CLOEXEC`, владение до передачи (путь ошибки закрывает); родитель
  закрывает write-концы, ребёнок read-концы до `neutralize`; dirfd листинга
  `/proc/self/fd` исключён через `F_GETFD`; край `null <= 2` обработан; наблюдаемые
  сигналы заблокированы на время `fork()`, прежние действия восстановлены в ребёнке;
  `fflush(NULL)` до форка; `reap` обрабатывает `ECHILD`; `kill` мапит `ESRCH` в
  `false`. TOCTOU между проверкой потоков и `fork()` расширение само вызвать не может.
- Сигнальный обработчик: один lock-free `fetch_or` — async-signal-safe; `SA_RESTART`,
  `sa_mask` пуст.
- Приём с EPOLLEXCLUSIVE: ADD-до-ожидания и DEL-до-отправки строго чередуются, потому
  что `demand` звенит только при `asked == false`, а `asked` сбрасывается после приёма —
  `EEXIST` невозможен; полуброшенный `accept()` (проигравший select) обработан флагом
  `asked` плюс каналом на один слот; `WouldBlock`/`ConnectionAborted`/`Interrupted`
  сохраняют регистрацию; level-triggered eventfd — lost-wakeup нет; `Drop` звенит
  `stop`, поток выходит из `epoll_wait` или по неудавшемуся `blocking_send`, копия
  сокета закрыта (тест `a_dropped_acceptor_lets_go_of_the_socket`). Блокирующих
  вызовов на рантайме нет. Принятое соединение `SOCK_CLOEXEC`. Цена на соединение —
  ~6 сисколлов плюс передача между потоками, это осознанный размен на честность,
  задокументированный с замерами.
- `listen::adopt`: копия через `F_DUPFD_CLOEXEC`, проверка `is_listener()`, каждый путь
  ошибки дропает копию. `set_nonblocking` на общем описании касается других держателей,
  но все они принимают неблокирующе, а мастер не принимает вовсе.
- Журнал: пачка ограничена `BURST_BYTES` плюс одна строка, очередь 4096; fd 1/2 через
  `ManuallyDrop<File>` (нет процессной блокировки `stdout()`, унаследованной посреди
  форка); метка дня дописывается после снятия `\n`; ошибка открытия сообщается раз в
  день с fallback на stdout; отчёт о потерях не чаще раза в 60 с; `flush()` на
  незапущенном стоке потока не создаёт (тест есть).
- Stats/пулы/состояния: механические переносы; блокировка реестра отпускается до опроса
  провайдеров; `Pusher::drop` отменяет цикл на последней отписке; чистильщики стартуют
  раз на экземпляр `Pools` через `AtomicBool::swap`.
- Клей: `sconcur_finish_wait` бросает общую ошибку, если экспорт упал без исключения;
  `process()` мапит `Refused` → `ForkRefusedException` (LogicException), `Failed` →
  `ProcessException` (RuntimeException); `lfd: null` декодируется в `None`.
- Rust-тесты: process, signals, threads, logger (7), exclusive (3) — модули
  `#[cfg(test)]` рядом с предметом, как требует гайд.
- Горячий путь `Scheduler`: `$this->heartbeat` → `Heartbeat::current()` в
  `takeReadyResult()` — один статический вызов плюс два чтения статических свойств на
  проход для каждого пользователя, включая тех, кто без мастера; после первого
  разрешения возвращает `null` и шорткатит. Пренебрежимо рядом с FFI-переходом
  `waitAnyBatch` на том же проходе, причина (планировщик, собранный до форка, должен
  писать в пайп ребёнка) здравая. `serve()` добавляет один `random_int` на старт и
  одно сравнение `bool` на итерацию; аллокаций и сисколлов на запрос не добавлено.
  `spreadLimit(1)` → `random_int(1, 1)`; `0` остаётся `0`.
- `StopRequest`: дроссель 100 мс через `hrtime`; первый вызов спрашивает сразу
  (`checkedAtNs === 0`); без мастера спрашивается только `takeSignals()`; `release()`
  возвращает прежние обработчики в `finally` у всех четырёх пользователей. Единицы в
  именах (`CHECK_INTERVAL_NS`, `checkedAtNs`) верные.
- `ProcessControl::adopt()` корректно закрывает исходный fd после `php://fd`-дубля;
  неблокирующий режим на каждом принятом потоке, как и говорит докблок `ForkResult`.
- `ServePayload` ×3: ключ `lfd` добавлен, докблоки типов расширены до `|null`; новое
  промоутированное свойство `protected` по правилу (соседи — старые `private`, массово
  не ретрофитить). `fromArgs()` приводит `?int`, так что `--listenFd=N` из
  `load-stats.sh` работает.
- `Extension::process()` — один вызывающий, как сказано в докблоке. `suggest ext-pcntl`
  убран верно. `phpstan.neon`: к существующему шаблону игнорирования функций
  расширения без стаба добавлен только `process` — ничего нового не прячет.
- `docker/release/Dockerfile`: правка только комментария; обе картинки по-прежнему
  ставят pcntl, он нужен тестам (`SIGTERM`, `pcntl_fork` в `ExtensionForkTest`,
  `posix_kill` в `WorkerMasterTest`). `docker/servers/config/supervisord.conf` грузит
  расширение для мастера — обязательно, мастер зовёт `ProcessControl`.
- `makefile`: `bench-worker-memory` по схеме `bench-<tech>-<operation>`;
  `fork-pool.php`/`shared-socket.php` — режимы существующих `bench-http-load-*` через
  `LAUNCH`/`SHARED_SOCKET`, новых таргетов не нужно. `tests/benchmarks/docs/run.py`:
  `section_shared_socket` и `section_worker_memory` согласованы с сигнатурами и
  зарегистрированы в `SECTIONS`. Весь вывод скриптов английский.
- Процессы в тестах: форкнутые воркеры жнутся `reap([$pid])` по конкретному pid;
  `proc_close` только после `!isRunning()`; `signal()` проверяет `isRunning()` перед
  `kill` (защита от переиспользованного pid); двойной SIGKILL fork-серверу и pid'ам из
  последнего `ALIVE` — осознанно. `PhpScript::run()` использует `timeout -k 5 N` без
  `--foreground`, так что GNU timeout сигналит всей группе процессов; `fork.rs` не
  зовёт `setsid`/`setpgid`, значит внуки застрявшего сценария тоже убиваются — утечки
  процессов из `ProcessControlTest`/`HttpServerListenFdTest` нет. Временные файлы
  удаляются на успешном пути, `BootstrapWorkerMasterTest` чистит в `tearDown`.
  Захардкоженных портов нет — везде `freePort()`.
- Обработчики ошибок: один push в `ForkServer::run()`, один pop в
  `ForkedWorker::prepare()` — стек сходится; `set_exception_handler(null)` в
  `reportUncaught()` корректен. Снимок fd в fork-сервере берётся после bootstrap,
  исключает events fd и listen fd и сделан до `watchSignals` — self-pipe сигналов в
  воркеры не утечёт.
- Пайпы мастера и fork-сервера: все чтения неблокирующие, `fread` до пустой строки,
  разбор по `\n` с буфером остатка; heartbeat побайтово. SIGPIPE в PHP CLI
  игнорируется, запись в закрытый пайп безопасна. Дедлайны на `hrtime`, `microtime`
  только для отображения `startedAt`. Цикл — 100 мс `usleep`/`telemetry->poll`,
  busy-loop нет; `ALIVE` эмитится только при изменении списка pid.
- Протокол команд/событий fork-сервера: `release` снимает `awaited`/`reportedReady`,
  `spawn` чистит `ready`/`failed`/`gone` — повторных READY на старый pid не будет;
  `releaseSlot` отменяет респаун слота в fork-сервере. Передача listen-сокета через
  `php://fd` плюс `dup2` в `proc_open` снимает CLOEXEC корректно.
- `ForkedWorkerMasterTest extends WorkerMasterTest` прогоняет все exec-сценарии под
  fork с `launchWith()` в `setUp`/`tearDown` — корректно. Каждый новый тестовый хелпер
  имеет вызывающих.

## Порядок починки

1. Баги 1–3 с двумя тестами: «замена в новом поколении не поднялась после N
   перенесённых слотов» и «fork-сервер нового поколения убит посреди reload». Оба пути
   сейчас не покрыты.
2. Баги 4–8 (Rust и `ProcessControl`), с Rust-тестами там, где поведение проверяется
   без PHP (флаш под полной очередью, ошибка `epoll_ctl`, отказ `pid <= 0`).
3. Устаревшие докблоки и мёртвый код одним коммитом.
4. Дублирование и переусложнение — отдельно, после зелёного `make check`.

## Статус (2026-10-20)

Закрыто, с коммитами:

| Что | Коммит |
|---|---|
| Баги 1–3, тесты `ForkServerGroupTest` | `6c7a4e8` |
| Баги 4–8, Rust- и PHP-тесты | `c14c185` |
| Докблоки, мёртвый код, дублирование и правила в `src/Worker/` | `148f7dc` |
| То же в `src/Process`, серверах, тестах, Rust; попутно найденная утечка `FD_CLOEXEC` у `php://fd` и команда `adp` | `32c09d5` |
| `try/finally` вокруг `proc_open` в `ExecWorkerProcess::open()`, чтобы копии дескрипторов закрывались и при исключении | `f564955` |
| Heartbeat у расширения: класс `Heartbeat` удалён, команды `hbq`/`hbw`, захват пайпа на `MINIT`; утечка в exec-режиме закрыта | `d029a2f` |

Открыто:

- Events-пайп `ForkServer` через `php://fd` — теоретическая утечка, fork-сервер не
  exec-ает; см. [план](fork-master.md#heartbeat-дескриптор-у-расширения), «Что остаётся».

Оставлено осознанно:

- Общий хелпер «TERM → дедлайн → KILL → grace» для `ForkServer::loop()` и
  `WorkerMaster::driveShutdown()`: циклы различаются по существу (несколько триггеров и
  пулов с общим дедлайном у мастера против одной группы у fork-сервера), общего там
  несколько строк арифметики.
- Эвристика готовности в `Scheduler::serve()`: проектное решение, см. план.
- `private` в тестах (`MasterCliTest`, `MasterLoggerTest`, `MasterReloadFileTest`,
  `WorkerGroupConfigTest`, `BootstrapWorkerMasterTest`): правило сформулировано для
  свойств в `src/`, прецеденты в тестах старые.
