# Ревью ветки `feature/amqp-rabbitmq` (2026-08-23)

Ветка относительно `master`: 25 коммитов, 217 файлов, +23 621 / −1 002 строк. Охват:
ядро планировщика (дедлайны корутин), AMQP-фича (PHP и Go), супервизируемый
консьюмер, воркер-мастер с группами, телеметрия, инфраструктура и доки.

Статус: **закрыто (2026-08-23…28).** Находки исправлены в порядке §5: `2a97d90` — баги
ядра и консьюмера, данные и счётчики; `ea0c4fc` — TLS, потери сообщений на дренаже,
утечки ленивого открытия и документация повторов; `a65ea60` — четыре пункта, которые
ревью оставило под вопросом (два оказались паритетом, а не дефектом, `AMQP_JUST_CONSUME`
записан как сознательное отклонение); доки выправлены в `235caea` и `3270bd1`.
Дальнейшие заходы — [amqp-simplification.md](amqp-simplification.md) и
[amqp-code-cleanup.md](amqp-code-cleanup.md). Список ниже оставлен как есть — он
описывает состояние ветки на 2026-08-23.

Проверки на HEAD `7c73836`: `make cs-fixer-check`, `make php-stan`, `make ext-test`
(`go test -race` локально тоже), `make test` — 665 тестов, всё зелёное (1 incomplete).
Находки ниже получены чтением кода; отмеченные «воспроизведено» подтверждены
скриптами в контейнере.

## 1. Баги

### 1.1. Ядро: дедлайны корутин

1. **high — устаревший push после дедлайна подменяет результат.**
   `src/Scheduler/Scheduler.php` — `expire()` чистит `awaitedFlowKey`/`awaitedTaskKey`
   и очередь switch, но не трогает `$pendingDispatches`. Вложенный `add()`/`spawn()`
   (со стека файбера) кладёт первый push в `$pendingDispatches`, а дренаж идёт внутри
   `takeReadyResult()`, т.е. *после* `enforceDeadlines()` в `tick()`/`serve()`. Если
   дедлайн сработал первым: корутина ловит таймаут, делает новый вызов (ключ K2,
   отправлен inline), затем дренаж достаёт старый push, проверка идентичности корутины
   проходит (та же живая корутина), push #1 уходит и перезаписывает `awaitedTaskKey`
   на K1. Результат K1 будит корутину как результат K2; K2 дропается.
   Воспроизведено: `usleep(1_000_000)` в catch «вернулся» через 302 мс.
   Фикс: в `expire()` выкидывать записи этой корутины из `$pendingDispatches` (как
   `purgeSwitchedCoroutine`), либо в дренаже пропускать dispatch, чей DTO больше не
   тот, на котором корутина припаркована.

2. **medium — повторный таймаут при вложенном `Limiter::on`.**
   `Scheduler::leaveDeadlineScope` возвращает `$previousNs`, даже если он уже в
   прошлом. `enterDeadlineScope` берёт `min(outer, now+ms)`, поэтому внутренний дедлайн
   часто равен внешнему; когда он срабатывает, `expire()`/`preempt()` обнуляют поле, но
   `finally` внутреннего `Limiter::on` ставит истёкший внешний дедлайн назад, и на
   следующем await в `catch` прилетает второй `CoroutineTimeoutException`.
   Воспроизведено: `add(fn() => { try { Limiter::on(5000, sleep 3s) } catch
   (CoroutineTimeoutException) { usleep(300ms); return 'cleaned'; } }, timeoutMs: 200)`
   → группа падает с таймаутом; тот же колбэк без внутреннего `Limiter` работает.
   Фикс: дедлайн срабатывает один раз — в `leaveDeadlineScope` не перевзводить
   `$previousNs`, если `$previousNs <= hrtime(true)`.

3. **medium — `timeoutMs <= 0` валидируется слишком поздно.**
   `src/WaitGroup.php` — `add()` не проверяет; `launch()` уже записал
   `$this->members[$fiberId]` и `Scheduler::register()` до `setDeadline()`, который
   бросает. После пойманного `InvalidCoroutineTimeoutException` остаётся фантомный
   member: `isLive()` true, `waitAll()` висит вечно (воспроизведено). При заполненной
   конкурентности `0` уезжает в `$pending` и всплывает из `fillSlots()` (ловит только
   `CallbackExecutionException`); `fillSlots` вызывается из `Scheduler::forget()` →
   `completeCoroutine()` вне `try` в `resumeCoroutine` → внутри `serve()` исключение
   роняет весь сервер. Докблок исключения («raised where the group is built») неверен.
   Фикс: валидировать в `add()` первым делом; лучше — единая конвенция `0 = нет
   дедлайна` (см. §2.2).

4. **medium (perf) — O(n) на каждую итерацию цикла планировщика.**
   У `HttpServer` дефолт `handlerTimeoutMs = 60_000` → `spawn(timeoutMs:)` → дедлайн у
   каждого запроса. `enforceDeadlines()` (foreach) и `boundedByDeadline()`
   (`min($this->deadlines)`) вызываются на каждый доставленный результат в `serve()` и
   в `tick()`. Комментарий «a deadline is the exception and not the rule» и фраза в
   `docs/coroutine-timeout.md` «costs a comparison» уже неверны. Не замерено — риск
   регрессии rps под нагрузкой.
   Фикс: кэшировать ближайший дедлайн (`$nearestDeadlineNs`: min при вставке,
   пересчёт лениво при удалении ближайшего); обе функции — одно сравнение.

5. low — `enforceDeadlines()` собирает fiber id, а не объекты `Coroutine`; `expire()`
   первой корутины выполняет чужой код (завершения, `fillSlots`, вложенные `add`), и
   `spl_object_id` может быть переиспользован новой корутиной в том же проходе. Тот же
   класс риска, из-за которого `$pendingDispatches` ключуется по `Coroutine`. Фикс:
   собирать объекты и проверять `($this->coroutines[$c->id] ?? null) === $c`.

6. low — `HttpServer::stream` ловит `Throwable` (включая `CoroutineTimeoutException`)
   и дёргает `onError`, в отличие от `resolveResponse`, который пробрасывает
   `FlowStoppedException`. Двойного ответа клиенту нет (Go отвечает на поздние записи
   ошибкой), но `onError` узнаёт о намеренной размотке.

7. low — `Scheduler::canAwait()` отвечает `false` в обычном (не-SConcur) файбере, хотя
   синхронный путь там работает (`State::getCurrentFlow()`); `Channel::close()` в чужом
   файбере уходит в `closeDetached`. Точный предикат: «файбер с async flow, который
   планировщик больше не отслеживает».

8. low — `FlowStoppedException`, брошенный во время *первого* прогона member'а (до
   первого suspend), оборачивается в `CallbackExecutionException`
   (`WaitGroup::launch`), вопреки «reaches its group unwrapped». Достижимо с
   преемпцией и таймаутом короче первого кванта.

9. low — `leaveDeadlineScope` пишет `$coroutine->deadlineNs` и `$this->deadlines[$id]`
   в два шага; `preempt()` читает индекс между ними. Уходит вместе с
   `Coroutine::$deadlineNs` (§2.2).

Проверено и в порядке: гонка дедлайн ↔ результат для уже отправленных задач (ключи
чистятся, stale-результат дропается в `resumeByResult`); дедлайны чистятся на всех
путях выхода (`forget`, `detach`, `shutdown`, `discard`), включая пул файберов;
`FlowStoppedException` проходит через `failCoroutine`/`suspend`/`awaitGroup`/`switch`;
`isSuspending`-гард стоит до броска в `preempt()`; `boundedByDeadline` ограничивает оба
блокирующих ожидания; Go-таймер 504 всегда раньше PHP-размотки; `stop()` чистит
`groupWaiters`, так что дедлайн на корутине, ждущей вложенную группу, безопасен.

### 1.2. AMQP, PHP-сторона

10. **medium — `Connection::connect()` после смерти соединения течёт.**
    `src/Features/Amqp/Connection.php` — `connect()` делает `close()` только при
    `internalOpen`, а `close()` ключуется на `internalId`. После connection-scope
    ошибки `AmqpResource::markFailure` оставляет `internalId` (намеренно: «close()
    still has to hand it back»). Прямой `connect()` пропускает `close()`:
    перезаписывает `internalId`, старый pooled handle не возвращается в Go (owner
    count навсегда), `forgetChannels()` не вызывается — каналы мёртвого соединения
    снова проходят `isOpen()`. Ровно то, от чего защищает
    `testChannelsOfAReconnectedConnectionReportThemselvesClosed`, но только через
    `close()+connect()`. Фикс: `if ($this->internalId !== '') { $this->close(); }`.

11. low — `ConnectionOptions::fromDsn`: `?verify=false` читается как `true`
    (`(bool) "false"`); выключают только `0`/``/`verify_none`. Докблок обещает «a plain
    boolean is accepted».

12. low — `Channel::__destruct` → `closeDetached()` шлёт `ChannelClose` для каналов,
    которые `Connection::close()` уже отпустил (`forgetChannels()` не чистит
    `internalId`) — лишнее пересечение границы и шум в логе. Фикс: чистить `internalId`
    и запись реестра в `forgetChannels()`.

13. low / дизайн — `Channel::__destruct` и `Connection::__destruct` зовут
    `Extension::get()->push(flowKey: '')` напрямую; локальная `$channel`, вышедшая из
    области видимости внутри живой корутины, делает cgo со стека файбера — против
    правила «cgo is never called from a coroutine's stack». Варианты: задокументировать
    как исключение, либо дать планировщику хук «отложенный detached push на следующем
    тике». Путь размотанной корутины (`!canAwait()`) альтернативы не имеет.

14. low — `TableCodec`: `Timestamp->seconds > PHP_INT_MAX` сравнивает с `(float)
    2^63`, ровно `2^63` проходит и `(int)` заворачивается в `PHP_INT_MIN` (`>=`).
    `Decimal::SIGNIFICAND_MAX = 4294967295` — Go/спецификация несут significand как
    signed int32; туда-обратно через SConcur бит-в-бит, но чужой клиент прочитает
    3e9 как отрицательное, а отрицательный decimal не выразить вовсе. Скорее заметка в
    док, чем фикс.

Проверено и в порядке: wire-контракт всех `*PayloadParameters::getData()` с Go-структурами
(`payloads.go`), тегированные `Decimal`/`Timestamp`, `null`→`""` и `[]`→nil-Table в
msgpack v5; маппинг `FailureScopeEnum` → исключения; пометка канала/соединения в
`markFailure`; защита от двойного settle в `Delivery`; teardown `consume()`; порядок
confirm/return; `QueueInfo`; `publishConfirmed` с `mandatory: true`; `PropertiesCodec`.

### 1.3. AMQP, Go-сторона

15. **medium — `classify` принимает закрывающийся канал за умершее соединение.**
    `ext-go-legacy/internal/features/amqp/feature.go` — `ErrClosed` классифицируется по
    `entry.isClosed()`, т.е. по тому, отработал ли наш `watch` (`channels.go`). Драйвер
    помечает канал closed в reader'е соединения до `NotifyClose`; в этом окне любая
    команда на канале получает `ErrClosed` при `isClosed() == false` → `scopeNetwork` →
    PHP бросает `ConnectionException` и помечает мёртвым всё соединение со всеми
    каналами из-за канального 404/406. Сценарий: `publish()` в отсутствующий exchange
    и сразу второй `publish()`/`waitForConfirms`. Фикс: классифицировать по
    `entry.handle.pooled.connection.IsClosed()` (ставится в `shutdown()` до разбора
    каналов — без гонки).

16. **medium — двойной `NotifyPublish` listener при ретрае `enableConfirms`.**
    `channels.go` `startConfirmMode`: при ошибке `do()` (таймаут контекста) claim
    отдаётся назад, если `confirming` ещё false — но closure #1 может ещё стоять за
    `channelMutex`. Ретрай из PHP (`$confirming` там false) ставит closure #2; #1
    доезжает (`confirming=true`, listener #1 → `confirmsReady`), #2 вызывает `Confirm`
    снова и регистрирует listener #2; `collect` переключается на #2, #1 никто не
    читает. Драйвер веером шлёт каждое подтверждение в оба; после 1024 буфера каждое
    подтверждение стоит reader'у соединения `notifyTimeout` — стопорятся все каналы
    соединения. Фикс: внутри closure перепроверить `e.confirming` под `channelMutex` и
    вернуть nil, если уже включён; `confirmClaimed` после этого удалить.

17. low — `pending` залипает, если publish истёк до захвата мьютекса
    (`publish.go`: `publishing()` до `do()`, `PublishWithContext` видит `ctx.Done()` и
    не публикует, результат выброшен `doAbandoning`, `publishFailed()` не вызван) →
    следующий `waitForConfirms` ждёт до дедлайна. Фикс: `publishing()` внутри closure
    после лока, декремент на ошибке драйвера там же.

18. low — после смерти соединения `dropHandle` убирает записи; следующий `channelOf`
    отвечает `chn:0: No channel available.` → PHP `ChannelException`, `isOpen()` у
    соединения остаётся true; consume-стрим говорит «cancelled by the broker» →
    `QueueException`. `docs/amqp.md` обещает `ConnectionException`. Частичный фикс:
    в `resultFromDelivery` (`!ok`) и `wait` (`<-e.gone`) проверять
    `connection.IsClosed()` → `scopeNetwork`.

19. low — `watch` выбрасывает `*amqp091.Error` (причину закрытия от брокера: 404 «no
    exchange …»); PHP видит только `No channel available.` Хранить причину в entry и
    использовать в `wait`/`classify`.

20. low — `handleChannelOpen` на неизвестном handle отвечает нескоупленной ошибкой
    (`errFactory.ByText`) → PHP считает `Command`, соединение не помечается. Нужен
    `networkErrorPayload("No connection available.")`.

21. low — `cancelDetached` (`feature.go`) шлёт cancel *до* claim через
    `forgetConsumer`, может задвоить отправку с путём `AfterFunc`; `cancelConsumer`
    делает наоборот.

Проверено и в порядке: порядок локов (handle → channels → pool), идемпотентность
`entry.close()`, пробуждение `gone`/waiters, закрытие `NotifyClose`-listener'ов на
graceful close (нет утечки горутин), очистка abandoned-результатов в
`dial`/`openBounded`, teardown consume при stopFlow, idempotent double-cancel,
detached push не блокирует PHP-поток, декодирование таблиц, порядок confirm (returns
дренируются до записи confirm), multiple-ack статистика, auto-ack, `Get`.

### 1.4. Консьюмер, воркер-мастер, телеметрия

22. **medium — дренаж может остановить группу, пока последний обработчик ждёт ack.**
    `src/Features/Amqp/Consumer/QueueConsumer.php` `handleDelivery`: в `finally`
    `$state->messageFinished($failed)` (busy−1) идёт *до* `settle()`, который
    суспендится на расширении. Супервизор (`superviseDrain`, опрос 20 мс) видит
    `busyConsumers() == 0`, делает `$waitGroup->stop()`, размотка бьёт по suspend'у
    ack: `Delivery::settled` уже true, push мог не дойти до брокера, flow отменяется,
    канал отдаётся с неподтверждённой доставкой → успешно обработанное сообщение
    уедет на повтор. Фикс: `messageFinished()` после `settle()` (settle — часть
    сообщения).

23. **medium — `reload` не проверяет `workerScript`.** `WorkerMaster::checkReloadSignal`
    ловит только `InvalidConfigException`; `is_file($group->workerScript)` проверяется
    только на старте (`ensureDirectories`). Опечатка в пути при reload → `applyGroups()`
    → каждый слот роллится (SIGTERM хорошему воркеру, spawn `php /wrong/path` → exit 1)
    → пул в crash-loop с backoff. `docs/worker-master.md`: «A typo must never take a
    working pool down». Фикс: та же проверка существования скрипта до
    `applyGroups`/`applyOneGroup`.

24. **medium — CLI пишет сырой (относительный) `--configPath` в reload-файл.**
    `MasterCli` → `MasterReloadFile::request($configPath, $group)`; мастер в
    `MasterReloadFile::configPath()` делает `is_file($configPath) ? … : ''` в *своём*
    cwd → `''` → `checkReloadSignal` берёт `$this->groups` (старый конфиг), логирует
    «reload requested»/«reload complete», CLI выходит с 0. Правка оператора не
    применена. Фикс: `realpath()` в CLI (и ошибка, если не резолвится) или warning у
    мастера.

25. low — размотанное дренажом сообщение считается handled
    (`ConsumerState::messageFinished` всегда `++handledCount`, вызывается и на
    `$unwound`-пути). Коммит ea0c4fc чинил refusal/onError, счётчик — нет.

26. low — `QueueConsumer` не умеет арминг преемпции (у серверов есть
    `preemptionQuantumMs`, в `Scheduler::serve`); `handlerTimeoutMs` и SIGTERM ждут
    следующего suspend у CPU-bound обработчика, супервизор при этом тоже не бежит.
    `docs/amqp.md` («only cut if preemption is armed») не говорит, что консьюмер её не
    включает. Предложение: параметр `preemptionQuantumMs` как у серверов.

27. low — смерть всех консьюмеров (потеря соединения, удалённые очереди) → exit 0:
    `consumeQueue` глотает `AmqpException` на консьюмер, `superviseDrain` возвращается,
    `consume()` завершается штатно. С `restartPolicy: on-failure` пул пустеет навсегда
    после сбоя брокера; скрипт не может отличить «лимит» от «все умерли».

28. low — повторное добавление группы, которая ещё ретируется после удаления,
    теряется: `WorkerGroup::spawn()` no-op при `$retiring`, `applyGroups` находит пул
    → `reconfigure()` → `startReload()` → все spawn пустые → `retireDrainedPools()`
    удаляет. Группа вернётся только на следующем reload. `reconfigure()` должен
    сбрасывать `$retiring` или `applyGroups` считать ретирующийся пул отсутствующим.

29. low — `reload --group=X` для группы, которую мастер не ведёт, всё равно мутирует
    `$this->groups` (`applyOneGroup` пишет до проверки `$this->pools[$name]`); позже
    голый `reload` её поднимет.

30. low — telemetry socket для реложенных групп берётся из *нового* файла
    (`MasterConfig::withRuntimeEnvironment` строит `SCONCUR_TELEMETRY_SOCKET` из
    `runtimeDir`/`name` нового конфига), а коллектор слушает старый путь. Уходит с
    упрощением §2.5.

31. low — второй reload-запрос, записанный во время roll'а, теряется:
    `checkReloadSignal` пропускает при `anyPoolReloading()`, `finishReload` безусловно
    чистит триггер. `stop` во время reload-тика может быть отменён:
    `checkStateFileStopSignal()` → `checkReloadSignal()` → `writeState()` пересоздаёт
    state-файл. `maxMessages` перелетает до (coroutines−1) — задокументировать.

Проверено и в порядке: двухфазный дренаж (break → finally генератора → release стрима
и awaited `basic.cancel`; размотанный путь — detached), канал всегда закрывается;
путь исключения обработчика (self-settled уважается, ошибки settle логируются);
prefetch; `QueueSpecParser` (дубликаты, weight < 1, пустой/не-список, лишние ключи,
бюджет каналов); учёт слотов, backoff, эскалация SIGKILL, атомарная запись state,
lock, проверка сироты; телеметрия — деление на ноль, экранирование имён групп в HTML
и Prometheus.

## 2. Переусложнение

Цель — проще и чище. По убыванию выигрыша.

### 2.1. AMQP PHP

- **49 файлов `XxxPayload` + `XxxPayloadParameters` — шаблон.** Каждый `XxxPayload`
  одинаков с точностью до enum-case; каждый `XxxPayloadParameters` — мап именованных
  аргументов в короткие ключи. Sql-фича держит мап inline в одном классе на команду;
  HttpClient/WsClient используют пару на 3 команды, а не на 24. Предложение: один
  `readonly class AmqpPayload extends BaseAmqpPayload { __construct(AmqpCommandEnum
  $command, array $data) }` и массив коротких ключей на месте вызова (там и так все
  поля названы) — −48 файлов, ~−1700 строк. Ссылки на Go-структуры — комментарием в
  докблоках enum-кейсов. Минимум — стиль Sql (один класс на команду, вдвое меньше).
- `Queue`/`Exchange` не должны наследовать `AmqpResource`: тянут `internalId`,
  `internalOpen`, `internalChannels`, `forgetChannels()`, `markFailure()`, третью копию
  `timeoutMs()`, а пользуются только `runCommand()` и `$this->channel->internalId`.
  Достаточно `@internal Channel::run(AmqpCommandEnum $command, array $data, string
  $exceptionClass, string $operation): array`, который сам подставляет `chid`/`to` и
  делает guard + marking. `Queue`/`Exchange` — обычные классы с `Channel` внутри.
- `CommandRunner` (три статики) + `CommandFailure` → один protected
  `AmqpResource::translate(TaskErrorException|TaskExecutionException, string $class,
  ?Channel): AmqpException` (~35 строк вместо 105+19); `FailureScopeEnum` оставить.
- `Channel.php` 770 строк: ~45 % — докблоки-история «calque»; абзац про «cancel still
  in flight» дважды. С generic payload каждое тело команды ужимается с ~15 до ~8
  строк; `failOnReturns`/`failOnNacks`/`deliveryFrom` — в `DeliveryCodec` рядом с
  `PropertiesCodec`.
- `Channel::$selfReference` не нужен: `WeakReference::create($obj)` возвращает тот же
  экземпляр для того же объекта (гарантия PHP); комментарий про аллокацию неверен.
- Мёртвый/незадокументированный публичный API: `Connection::maxChannels()/
  maxFrameSize()/heartbeatInterval()` и три `negotiated*`, `Decimal::toFloat()`,
  `Timestamp::__toString()`, интерфейс `AmqpValue` + `toAmqpValue()` (оба возвращают
  `$this`, `TableCodec` проверяет классы напрямую). Задокументировать или удалить.
- `rpcTimeoutMs()`/`Channel::timeoutMs()`/копия в Queue/Exchange — одно и то же в
  трёх классах; один accessor на `Connection`.

### 2.2. Ядро: дедлайны

- **Убрать `Coroutine::$deadlineNs`** — дублирует `$this->deadlines[$id]`, читается в
  одном месте (`enterDeadlineScope`), пишется в шести. Заменить чтение на
  `$this->deadlines[$fiberId] ?? 0`. Уходят баг 9 и ложь «three mutable fields» в
  докблоке `Coroutine` (их четыре).
- **Один примитив вместо трёх публичных входов.** Сейчас: `setDeadline()`,
  `enterDeadlineScope()`, `leaveDeadlineScope()` (публичные, с двойным сентинелом
  `null`-vs-`0`), плюс `Limiter::on`, `add(timeoutMs:)`, `spawn(timeoutMs:)`.
  Предложение: один `Scheduler::withDeadline(int $timeoutMs, Closure $callback): mixed`
  (enter/try/finally/leave внутри, сентинел невидим), `Limiter::on` делегирует,
  `add(timeoutMs:)`/`spawn(timeoutMs:)` оборачивают колбэк (`fn() => withDeadline(…)`)
  — «часы стартуют в launch» получается само, `setDeadline` исчезает. `Limiter::on`
  нужен (консьюмеру — скоуп на сообщение внутри долгоживущей корутины);
  `add(timeoutMs:)` по плану чистый сахар — можно и убрать.
- **Одна конвенция «нет дедлайна».** `spawn()` молча игнорирует `<= 0`, `serve()` —
  `0 = disabled`, `add()` — `?int null` и бросает на `0`. Предложение: везде `int
  $timeoutMs = 0`, `0 = none`, отказывать только отрицательным (или никому) — тогда
  `InvalidCoroutineTimeoutException` ужимается до одной проверки в `add()` или
  исчезает, и баг 3 уходит.
- `Limiter::on(ms:)`: `ms` — не осмысленное имя и без юнит-суффикса (`timeoutMs`);
  «Limiter» читается как лимитер конкурентности. Вариант: `Deadline::run(timeoutMs:,
  callback:)`.
- `enforceDeadlines` + `boundedByDeadline`: общий тест `deadlines === []` и два
  `hrtime()` на итерацию; с кэшем ближайшего дедлайна — одно сравнение (баг 4).
- Осиротевшие докблоки в `Scheduler.php`: «Resumes the oldest coroutine parked by
  switch()…» сидит над `enforceDeadlines` (принадлежит `resumeNextSwitched`);
  комментарий про poll в `serve()` описывает `takeReadyResult`, но стоит над
  `enforceDeadlines`.
- Докблоки по 15 строк (`enterDeadlineScope`, `canAwait`, `enforceDeadlines`,
  `failCoroutine` inline) — сократить до 2–4: обоснование уже в коммитах и доке.
- `resumeCoroutine(..., ?Throwable $throwable = null)` — третий опциональный параметр
  ради `expire()`; позиционные `resumeCoroutine($coroutine, null)` рядом с именованным
  читаются странно. Либо именовать все, либо `throwInto()` отдельно.

### 2.3. AMQP Go

- `channels.go` (892) → `channels.go` (реестр: open/find/close/dropHandle/sweep, ~250),
  `channel_entry.go` (entry, `do`, консьюмеры, `close`, ~200), а
  `startConfirmMode`/`collect`/`wait`/drain — в существующий `confirms.go`.
  `connections.go`: `dial`/`dialConfig`/`tlsConfigFromParams`/`connectionUri` →
  `dial.go`.
- Список waiters (`waiters []chan struct{}` + `waiterLocked` + `dropWaiter` + `wake`)
  → один broadcast-канал `changed chan struct{}`, подменяемый под локом (`wake`:
  close old, make new; `wait`: select на нём). Нечего дерегистрировать, исчезает класс
  багов «мёртвый waiter», ~−45 строк; `TestATimedOutWaitLeavesNoWaiterBehind`
  становится лишним.
- Одна горутина на канал вместо двух: слить `watch` в `collect` (select и на
  `NotifyClose`) — заодно даёт `collect` причину закрытия (баг 19).
- Убрать `confirmClaimed` (баг 16): проверка под `channelMutex` и есть claim.
- Убрать per-handle `channels` map (`connectionHandle.channels` — только для
  `usedChannels` и `dropHandle`): скан глобального реестра по `entry.handle == handle`
  убирает `forget()`, двойную регистрацию под двумя локами и комментарий о порядке
  локов. `channelCounter` оставить на handle.
- Один helper `bounded(ctx, call func() error, abandon func(error)) error` вместо пяти
  ручных «goroutine + buffered chan + select ctx» (`dial`, `openBounded`,
  `closeConnection`, `entry.close`, `doAbandoning`) — ~−60 строк.
- `values.go`: ветки `int/int8/…/uint32/float32/[]byte` в `toTableValue`/`intFromValue`
  и `map[string]any` в `fromTableValue` недостижимы с провода (`DecodeInterfaceLoose`
  даёт только `int64/uint64/float64/string/bool/nil` + вложенные; драйвер отдаёт
  `amqp091.Table`).
- Мелочи: `maxPendingConfirmations`/`maxPendingReturns` — алиасы размеров очередей;
  второй `errors.As(*net.OpError)` в `isNetworkError` лишний; `commandContext` +
  `commandContextWithDefault` → одна функция; `connectionUri` заново вычисляет
  `usesTls`; `normalizeWaitResult` → в `drainLocked`; `Confirmation.Multiple/Requeue`
  никогда не ставятся, PHP читает только `ak`/`dt`; `ConnectionParams.TimeoutMs` не
  используется Go; `waitForConfirms` вне confirm-режима парковать до дедлайна (ветка
  недостижима — PHP гардит).

### 2.4. Консьюмер

- `consumeQueue`: флаг `$ended` + двойной `close()` лишние — `Channel::close()` сам
  выбирает awaited/detached по `canAwait()`; `finally { consumerFinished();
  $channel->close(); }` эквивалентен.
- `ConsumerState`: `failedCount()` никто не читает (мёртвый, `messageFinished(bool
  $failed)` только его кормит); `startedConsumers`/`liveConsumers` нужны только для
  гонки первого пробуждения супервизора — хватит одного `finishedConsumers` против
  `$consumerCount`.
- ~45 % `QueueConsumer.php` — комментарии про удалённый «calque»-код.

### 2.5. Мастер и телеметрия

- `MasterDefaults` — DTO с одним пользователем и ложным обоснованием («reload
  re-reads groups against the defaults of the master already running» — reload
  перечитывает весь файл). Top-level `restartPolicy`/таймауты парсятся в
  `MasterConfig::fromArray` и заново валидируются в `WorkerGroupConfig::fromArray`.
  Проще: `WorkerGroupConfig::fromArray(array $data, array $parent, int $index)` с
  `$parent['x'] ?? default`.
- `MasterConfig::withRuntimeEnvironment` + `toWorkerMaster()` + throwaway
  `WorkerMaster` в reload ради одной env-переменной: положить
  `SCONCUR_TELEMETRY_SOCKET` в `WorkerGroup::buildEnv()` (он уже получает `masterPid`,
  `cwd`). Reload становится `MasterConfig::fromFile($path)->groups()`, баг 30
  исчезает.
- `Aggregator`: hung считается дважды (цикл и `countHung`); `sum()` — 120 строк на 22
  аккумулятора → per-section helpers. `MasterReloadFile` читает/декодит файл дважды
  (`configPath()`, `group()`) — один value object. Комментарий
  `WorkerGroup::reconfigure` «new slots come up immediately» неверен (в конце roll'а);
  то же в `.ai/plans/queue-consumer-pools.md` §5.6.
- Разделение `WorkerMaster` ↔ `WorkerGroup` само по себе правильное.

## 3. Стиль (по `.ai/README.md`)

- Единицы в именах: `ConnectionOptions::$connectTimeout/$readTimeout/$writeTimeout/
  $rpcTimeout` (float секунды), `$heartbeat`, `$frameMax`; `Channel::publishConfirmed(
  float $timeout)`, `waitForConfirms(float $timeout)`, `prefetch(int $count, int
  $size)`; `Limiter::on(ms:)`. Докблок `ConnectionOptions` ссылается на
  `HttpClientOptions` как образец — там `*Ms` int.
- Именованные аргументы не вертикально: ~38 однострочных вызовов в `Amqp/`
  (`Connection`, `Channel`, `Queue`, `Exchange`, `ConnectionOptions::assertLength`,
  `markFailure(failure:, channel:)`, `TableCodec::encodeValue(value:, depth:)`).
- Позиционные многоаргументные вызовы проектных методов: `WorkerGroup`
  (`logWorkerLines`, `logWorker`, `nextBackoffMs`, смешанный стиль), `WorkerMaster`
  (`applyOneGroup`, `master(...)`), `MasterCli` (`flag`, `request`, `status`),
  `MasterConfig::nonNegativeInt`, `Aggregator::countHung`, `HtmlRenderer::workloadCell`;
  `Scheduler::resumeCoroutine($coroutine, null)` (сигнатура менялась на ветке).
- `private int $handlerTimeoutMs` в `SocketServer`/`WsServer` (следует локальному
  стилю файла, но правило — `protected`).
- Валидация опций бросает `ConnectionException` (Runtime) — по README инварианты/usage
  → Logic-наследник (`InvalidQueueSpecException` уже так). `Channel::assertPrefetch`
  то же.
- Аббревиатуры: `$meta`, `$tag` в `Channel::consume`.
- Устаревшие/испорченные докблоки: `HtmlRenderer.php` (начало класса),
  `WorkerMasterTest.php` (осиротевший «Polls $condition…»), `WorkerMaster::run
  @throws InvalidWorkerCountException` (теперь «no groups»),
  `ServerRuntimeSupportTrait` «(HttpServer, SocketServer)», `Totals.php` «weighted by
  settled» (код — по `timed`), `Coroutine.php` «Set by WaitGroup::launch», 
  `CoroutineTimeoutException` «the deadline its `WaitGroup::add` gave it» (ещё
  `Limiter`, `spawn`, серверы), `SocketServer`/`WsServer` «closed by the Go side»
  (закрывает PHP `finally { $connection->close(); }`).
- Go: фрейминг «calque/ext-amqp» после 055012a в `feature.go`, `channels.go`,
  `consume_state.go`, `values.go`, `types/amqp.go`, `payloads.go`; устаревшие факты
  (`payloads.go` про транзакции и «два wait loop», `ChannelNumber` — счётчик, а не
  AMQP-номер); два докблока подряд на `connectionUri`; константы в трёх блоках;
  `consumerStatsInstance` eager при ленивых остальных; `"Wait timeout exceed"` —
  неграмотно, PHP по тексту не матчит.
- Чисто: нет `final`, нет ведущих `\`, нет кириллицы в коде/скриптах, нет
  TODO/var_dump; версии `0.11.0` равны во всех трёх местах, бамп один раз на ветке.

## 4. Доки

- `docs/http-server.md` «Running in Docker» (и `.ru.md`): «три мастера», `make
  *-server-stop` — теперь один `servers`-мастер с группами + `rabbitmq`-мастер;
  `*-server-stop` удалены, `*-server-status/reload` — через `--group=`.
- `docker-compose.yml`: комментарий над `servers` про «both masters (HTTP and socket)»
  и удалённые make-цели.
- `docs/cli.md`: «a single `--configPath` flag» — есть `--group=NAME`; формат лога
  `[worker: 12346 #0]` → `worker: <pid> <group> #<index>`. `docs/worker-master.md`:
  «Every command takes a single flag»; «Changing them is logged as ignored» — ничего не
  логируется.
- `docs/admin-stats.md`: quick-start конфиг старой плоской формы (`workerScript`/
  `workerCount`/`server` сверху) — `MasterConfig::assertKnownKeys` его отвергает;
  Prometheus-пример без `group=`, семейства `sconcur_group_*`/
  `sconcur_pool_deliveries_*` не описаны; в Prometheus нет per-group workload и
  per-worker consumer-серий.
- `README.md`/`README.ru.md` roadmap: дедлайн на корутину всё ещё как несделанное.
- `.ai/README.md`: нет `docs/coroutine-timeout.md` в Further reading, нет `Limiter`
  в списке классов. `docs/architecture.md` — одна фраза со ссылкой на
  coroutine-timeout.
- `docs/coroutine-timeout.md` (+ru): «costs a comparison» (O(n), баг 4); «reaches its
  group unwrapped» (кроме первого прогона, баг 8); «When the clock starts» — для
  вложенного `add()` первый push уходит на следующем дренаже, allowance может съесть
  CPU родителя.
- `docs/amqp.md`: «connection died → code 0» — драйвер даёт 501 (`FrameError`);
  команды после смерти → `ChannelException`/`QueueException` (баг 18); «the broker
  still holds the consumer» после read timeout — консьюмер отменяется `finally`,
  держится очередь; 404 при publish в отсутствующий exchange не всплывает (баг 19);
  «only cut if preemption is armed» — консьюмер её не включает.
- `tests/servers/http/http-server.php`: докблок маршрутов без `GET
  /rabbitmq/{count}/sleep/{ms}`.
- `.ai/plans/queue-consumer-pools.md`: §8а `autostart=false` (в `supervisord.conf`
  true), §5.6 «слоты сразу».
- `.ai/plans/coroutine-lifetime.md` — не закоммичен (untracked).

## 5. Предлагаемый порядок починки

1. Баги 1, 22, 10 (подмена результата; дренаж режет ack; утечка `connect()`).
2. Баги 2, 3, 4 через упрощение §2.2 (один примитив дедлайна, `0 = нет`, кэш
   ближайшего дедлайна, без `Coroutine::$deadlineNs`).
3. Баги 15, 16, 17 (Go classify, confirm listener, `pending`).
4. Баги 23, 24 (reload: проверка скрипта, `realpath`).
5. Упрощение payload-ов AMQP (§2.1) и `Queue`/`Exchange`/`CommandRunner`.
6. Остальные low, стиль, доки (вместе с `.ru.md`).
