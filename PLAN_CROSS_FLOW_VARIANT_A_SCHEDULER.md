# Кросс-флоу конкурентность — Вариант A: единый кооперативный Scheduler

> **СТАТУС: РЕАЛИЗОВАНО — Фазы 1–4 (выбран и внедрён этот вариант).**
> Фаза 5 (серверный режим) — на потом. Кратко по факту реализации:
> - Фаза 1 (Go `waitAny`): общий канал в `Handler` + `WaitAny()` + переходный
>   демультиплексор `Wait(flowKey)`; `Flow.OnDelivered`; экспорт `waitAny`,
>   C-glue, stub, `Extension::waitAny()`; версия `0.1.0`.
> - Фаза 2 (Scheduler): `src/Scheduler/{Scheduler,Coroutine}.php`; `WaitGroup`
>   переписан на кооперативную модель (`run`/`awaitGroup`).
> - Дополнительный багфикс: `states.go` — результат курсора несёт `FlowKey`
>   текущего `next` (иначе демультиплексор вешал sync-курсор).
> - Фаза 3 (отмена/ресурсы): покрыта реализацией Фазы 2 (unwind через
>   `Fiber::throw`, рекурсивно для вложенных; чистка `groupWaiters`).
> - Фаза 4 (тесты/доки): `testMulti` переписан на инварианты конкурентности;
>   добавлены тесты вложенной конкурентности и siblings; `.ai/README.md` обновлён.
> - Результат: `make check` зелёный (84 теста, 0 падений; 1 incomplete — пре-существующий).
>
> Задача: вложенные корутины (вложенный `WaitGroup` внутри корутины внешнего)
> должны выполняться конкурентно между собой И параллельно с задачами внешнего
> флоу, а не блокировать его до своего завершения.
>
> Это **Вариант A** (выбран и реализован). Альтернатива (не внедрена) —
> `PLAN_CROSS_FLOW_VARIANT_B_BUBBLING.md`. Сравнение — в конце обоих файлов.

---

## 0. Корень проблемы (общий для обоих вариантов)

Конкурентность на Go уже есть (каждая задача — горутина). Блокировка — в
PHP-слое:

- `WaitGroup::iterate()`/`waitAll()` — это самостоятельный мини-event-loop:
  цикл `FeatureExecutor::wait($flowKey)` → блокирующий cgo `wait(flowKey)`
  (`ext/main.go:80`, читает **per-flow** канал `Flow.Wait`) → `resume` нужного
  fiber.
- Когда корутина внешнего флоу (fiber B) внутри себя создаёт вложенный
  `WaitGroup` и зовёт `waitAll()`, вложенный цикл крутится **в стеке fiber B** и
  блокирует ОС-поток на `wait(flow_inner)`. PHP однопоточный → управление не
  возвращается во внешний `iterate()`, внешние корутины не резюмятся, пока
  вложенная группа не завершится целиком.

Симптом виден в `GeneralTest::testMulti`: `1:finish` искусственно «переезжает»
за весь внутренний флоу (ожидаемый порядок зашит под текущую блокирующую модель).

Корень: **нет единого планировщика** — каждый `WaitGroup` сам себе loop, и
вложенный монополизирует поток.

---

## 1. Идея Варианта A

Один **Scheduler** (singleton) на весь процесс:

- Владеет реестром всех живых корутин (всех вложенных `WaitGroup`).
- **Единственный**, кто делает cgo-ожидание (`waitAny`) и `resume`.
  → Все `resume` идут из main-контекста, поэтому fibers **не вкладываются** друг
  в друга по стеку вызовов: `suspend` любого fiber возвращает управление в
  Scheduler.
- Глобальное ожидание `waitAny()` (Фаза 1, Go): получить «любой готовый
  `(flowKey, taskKey, result)`» и маршрутизировать в нужный fiber через
  `State::pullFiberByTask` (он уже глобален — индекс `[flowKey][taskKey]`).
- Вложенный `waitAll()`/`iterate()` **не блокирует**, а кооперативно
  `Fiber::suspend()`, отдавая управление Scheduler, пока его группа не
  завершится. Scheduler будит ожидающую корутину, когда её вложенная группа
  закончилась.

`WaitGroup` превращается в тонкого клиента Scheduler (группа корутин + очередь
готовых результатов для выдачи через `iterate`).

---

## 2. Целевой поток управления (трейс на `testMulti`)

Корутины: внешний WG — A (sleep 60, sleep 180), B (sleep 120, [inner], sleep 240).
Inner WG (внутри B) — 2.1, 2.2.

- **t=0** main → `outer.iterate()` → `Scheduler::run(outerGroup)` (верхний loop).
  Старт A, B (их первый прогон до первого `suspend`): A→suspend(sleep60),
  B→suspend(sleep120). Обе корутины «отданы» Scheduler.
- Scheduler крутит `waitAny()`:
- **t=60** результат sleep60(A) → `resume(A)` → A: `1:woke`, suspend(sleep180).
- **t=120** результат sleep120(B) → `resume(B)` → B создаёт inner WG, `add(2.1)`
  (suspend sleep60), `add(2.2)` (suspend sleep120), затем `inner.waitAll()`.
  inner `iterate` видит: готовых результатов нет, корутины живы → **B вложенный**
  → `Scheduler::awaitGroup(innerGroup)` помечает B как «ждёт innerGroup» и
  `Fiber::suspend(B)` → управление назад в Scheduler.
- Scheduler продолжает `waitAny()` по ВСЕМ flow:
- **t=240** результат sleep180(A) → `resume(A)` → A: `1:finish`, terminated →
  результат в очередь outerGroup. **← ключ: `1:finish` на t=240, а не после inner.**
- **t=180** (раньше, чем t=240) результат sleep60(2.1) → `resume(2.1)` → `2.1:woke`,
  suspend(sleep180). … и т.д. для 2.2.
- Когда 2.1 и 2.2 завершатся, при завершении последней корутины innerGroup
  Scheduler видит «группа пуста» → `wakeGroupWaiters(innerGroup)` → `resume(B)`.
  B возвращается в inner `iterate` (после `awaitGroup`), забирает результаты,
  `inner.waitAll()` возвращается, B: `2:woke`, suspend(sleep240) → … → `2:finish`.

Внешний и вложенный флоу прогрессируют параллельно — цель достигнута.

---

## ФАЗА 1 — Go + протокол `waitAny`

Готовит Go-фундамент: общий канал результатов + `waitAny()`, **не меняя
поведение PHP** (все существующие тесты зелёные). Самодостаточна и тестируема.

### 1.1 Точки отсчёта
- `tasks/task.go` — `Task{ ctx, ctxCancel, results chan, msg }`,
  `AddResult` = `select { results<- ; <-ctx.Done() }`.
- `flows/flow.go` — `Flow` создаёт канал **per-flow** (`NewFlow`);
  `Flow.Wait()` = `select { <-ctx.Done() ; <-results }` + пост-обработка
  (delete `activeTasks`, `tasksCount--`, `task.Cancel()` с исключением для
  курсора `IsNext || !HasNext`).
- `flows/flows.go` — `Flows` (map flowKey→Flow), `InitFlow/GetFlow/DeleteFlow`.
- `handler/handler.go` — `Handler{ ctx, ctxCancel, flows }`,
  `Push/Wait/StopFlow/Destroy/GetTasksCount`.
- `main.go` — экспорты `push/next/wait/tasksCount/stopFlow/destroy/version`.
- `sconcur.c` / `sconcur.stub.php` — C-glue и сигнатуры.

### 1.2 `handler/handler.go`
Handler владеет **общим** каналом + демультиплексирующим буфером `pending`
(переходный, пока PHP ещё использует per-flow `wait`; удалить после Фазы 4).

```go
type Handler struct {
    ctx       context.Context
    ctxCancel context.CancelFunc
    mutex     sync.Mutex
    flows     *flows.Flows
    results   chan *dto.Result          // общий на все flow
    pending   map[string][]*dto.Result  // доставленные, но не востребованные per-flow Wait
}

func (h *Handler) fresh() {
    ctx, cancel := context.WithCancel(context.Background())
    h.ctx = ctx; h.ctxCancel = cancel
    h.results = make(chan *dto.Result)         // небуферизованный — сохраняем backpressure
    h.pending = make(map[string][]*dto.Result)
    h.flows = flows.NewFlows()
}

// deliver — пост-обработка извлечённого из канала результата; РОВНО ОДИН РАЗ.
func (h *Handler) deliver(r *dto.Result) {
    if flow, ok := h.flows.GetFlow(r.FlowKey); ok {
        flow.OnDelivered(r)
    }
}

func (h *Handler) WaitAny() (*dto.Result, error) {
    if r := h.popAnyPending(); r != nil { return r, nil }
    select {
    case <-h.ctx.Done(): return nil, h.ctx.Err()
    case r, ok := <-h.results:
        if !ok { return nil, errors.New("results channel closed") }
        h.deliver(r); return r, nil
    }
}

// Wait(flowKey) — ПЕРЕХОДНАЯ совместимость (текущий PHP + sync-путь).
func (h *Handler) Wait(flowKey string) (*dto.Result, error) {
    if r := h.popPending(flowKey); r != nil { return r, nil }
    for {
        select {
        case <-h.ctx.Done(): return nil, h.ctx.Err()
        case r, ok := <-h.results:
            if !ok { return nil, errors.New("results channel closed") }
            h.deliver(r)
            if r.FlowKey == flowKey { return r, nil }
            h.pushPending(r)
        }
    }
}
```

> ⚠️ Инвариант однопоточности: `Wait/WaitAny` и `StopFlow/Destroy` не
> пересекаются во времени (PHP однопоточен; в блокирующем cgo другой PHP-код не
> исполняется). Пробуждение ждущего — только приходом результата либо
> `h.ctx.Done()` (Destroy). Поэтому отдельная отмена ждущего по `flow.ctx` не
> нужна.

### 1.3 `flows/flow.go`
- `NewFlow(handlerCtx, key, results chan)` — канал передаётся сверху.
- Пост-обработку вынести в `OnDelivered`; `Flow.Wait()` **удалить**.

```go
func (f *Flow) OnDelivered(result *dto.Result) {
    f.mutex.Lock()
    task := f.activeTasks[result.TaskKey]
    delete(f.activeTasks, result.TaskKey)
    f.tasksCount.Add(-1)
    f.mutex.Unlock()
    if task != nil && (task.GetMessage().IsNext || !result.HasNext) {
        task.Cancel() // initial-задача курсора живёт, пока HasNext
    }
}
```

### 1.4 `flows/flows.go`
`InitFlow(handlerCtx, flowKey, results chan)` — пробрасывает общий канал в `NewFlow`.

### 1.5 `main.go` — экспорт `waitAny`
```go
//export waitAny
func waitAny() C.buffer_result_t {
    res, err := handler.WaitAny()
    if err != nil { return C.buffer_result_t{err: C.CString("error: " + err.Error())} }
    serialized, err := msgpack.Marshal(res)
    if err != nil { return C.buffer_result_t{err: C.CString("error: marshal msgpack: " + err.Error())} }
    data := C.CBytes(serialized)
    return C.buffer_result_t{data: data, len: C.int(len(serialized)), err: nil}
}
```

### 1.6 `sconcur.c` / `sconcur.stub.php`
- `PHP_FUNCTION(waitAny)` (без аргументов, тело как `wait`), `arginfo_sconcur_waitAny`,
  регистрация `ZEND_NS_FE("SConcur\\Extension", waitAny, ...)`.
- stub: `function waitAny(): string {}`.

### 1.7 `src/Connection/Extension.php`
- `use function SConcur\Extension\waitAny;`
- Метод `waitAny(): TaskResultDto` — копия `wait()`, дёргает cgo `waitAny()`,
  тот же парсинг msgpack (`fk, md, tk, er, ek, pl, hn, ems`) и ветка `error:`.
- В этой фазе ещё не используется в `WaitGroup`.

### 1.8 Версия протокола
- Свести `main.go::version()` и `sconcur.c` module-версию к одному значению
  (сейчас рассинхрон `"0.0.1"`/`"0.1"`) → поднять до `"0.1.0"`.
- `Extension::REQUIRED_EXTENSION_VERSION` → `'0.1.0'`.

### 1.9 Go-тесты
1. WaitAny отдаёт результаты двух flow по мере готовности (по времени), верны `fk/tk`.
2. Учёт задач уменьшается; `activeTasks` чистится.
3. Отмена ctx после доставки; ctx initial-курсора (`HasNext`) жив; ctx `next` — отменён.
4. Демультиплексор `Wait(flowA)` буферизует результат `flowB`.
5. `Destroy()` разблокирует висящий `WaitAny()` ошибкой.
- Адаптировать `flow_test.go` под новые сигнатуры `NewFlow/NewTask`.

### 1.10 DoD Фазы 1
- [ ] Общий канал + pending + `deliver`; `WaitAny` + переходный `Wait`.
- [ ] `Flow.OnDelivered`, удалён `Flow.Wait`; `InitFlow` пробрасывает канал.
- [ ] export `waitAny` + C-glue + stub + `Extension::waitAny()`.
- [ ] bump версии (3 места).
- [ ] Go-тесты + адаптация. `make check` зелёный, поведение PHP не изменилось.

---

## ФАЗА 2 — PHP `Scheduler` (ядро Варианта A)

### 2.1 Новые сущности

**`src/Scheduler/Coroutine.php`** — обёртка над Fiber:
```
- int $id (spl_object_id)
- Fiber $fiber
- WaitGroup $group           // владелец
- string $callbackKey
- enum State: Running | WaitingTask | WaitingGroup | Terminated
- ?WaitGroup $awaitedGroup    // если WaitingGroup
- mixed $return
```

**`src/Scheduler/Scheduler.php`** (singleton) — реестр + единственный loop:
```
- array<int, Coroutine> $coroutines           // все живые
- array<string, int> $groupWaiters            // groupKey → fiberId, ждущий завершения группы
- register(Coroutine): void
- unregister(int $fiberId): void
- run(WaitGroup $group): void                 // верхний loop: tick() пока $group не завершена
- awaitGroup(WaitGroup $group): void          // вложенный: пометить текущую корутину WaitingGroup + Fiber::suspend()
- private tick(): void                        // один шаг: waitAny + маршрутизация
- private resumeForTask(TaskResultDto): void  // resume по taskKey
- private onCoroutineTerminated(Coroutine): void // в очередь группы; wakeGroupWaiters если группа пуста
- private wakeGroupWaiters(WaitGroup): void
```

### 2.2 Главный цикл
```php
public function run(WaitGroup $group): void
{
    while (!$group->isSettled()) {       // остались незавершённые корутины ИЛИ непустая очередь
        if ($group->hasReadyResults()) { return; } // дать iterate выдать готовое
        $this->tick();
    }
}

private function tick(): void
{
    $result = Extension::get()->waitAny();          // блокирующий cgo, любой flow
    $fiberId = State::pullFiberByTask($result->flowKey, $result->taskKey);
    $coroutine = $this->coroutines[$fiberId] ?? throw new FiberStateException(...);
    $this->resumeCoroutine($coroutine, $result);
}

private function resumeCoroutine(Coroutine $co, mixed $resumeValue): void
{
    try {
        $co->fiber->resume($resumeValue);            // как уже сделано — оборачиваем Throwable
    } catch (Throwable $e) {
        throw new CallbackExecutionException(message: $e->getMessage(), previous: $e);
    }
    if ($co->fiber->isTerminated()) {
        $co->return = $co->fiber->getReturn();
        $this->onCoroutineTerminated($co);
    }
    // иначе корутина снова suspended (WaitingTask на следующей задаче) — ждём waitAny
}

private function onCoroutineTerminated(Coroutine $co): void
{
    $co->group->pushResult($co->callbackKey, $co->return);  // в очередь группы
    State::unRegisterFiber($co->id);
    $this->unregister($co->id);
    if ($co->group->isEmptyOfLiveCoroutines()) {
        $this->wakeGroupWaiters($co->group);
    }
}

private function wakeGroupWaiters(WaitGroup $group): void
{
    $fiberId = $this->groupWaiters[$group->key()] ?? null;
    if ($fiberId === null) { return; }
    unset($this->groupWaiters[$group->key()]);
    $waiter = $this->coroutines[$fiberId];
    $this->resumeCoroutine($waiter, null);  // вернётся в inner iterate (после awaitGroup)
}
```

### 2.3 Протокол двух видов `suspend` (ядро сложности)

Корутина приостанавливается по двум причинам — Scheduler должен знать, чем
будить:

| Причина | Где ставится | Чем будится |
|---|---|---|
| **WaitingTask** — ждёт результат своей Go-задачи | `FeatureExecutor::suspend()` (как сейчас, `Fiber::suspend()`) | `tick()` по приходу `waitAny` с её `taskKey` (через `State::pullFiberByTask`) |
| **WaitingGroup** — вложенный `waitAll`/`iterate` ждёт завершения под-группы | `Scheduler::awaitGroup()` | `wakeGroupWaiters()` при опустошении группы |

`State::addFiberTask(flowKey, taskKey, fiberId)` (уже есть) обслуживает
маршрутизацию WaitingTask. Для WaitingGroup — `groupWaiters[groupKey]=fiberId`.

> Корутина одновременно может быть только в одном состоянии: либо ждёт свою
> задачу, либо ждёт под-группу (вложенный `waitAll` — это синхронная точка в её
> коде). Поэтому одного поля `awaitedGroup` достаточно.

### 2.4 Рефактор `WaitGroup`
- Состояние группы: набор fiberId своих корутин + **очередь готовых**
  (`callbackKey → return`). `$fibers`/`$fiberCallbackKeys`/`$syncResults`
  переезжают/оборачиваются.
- `add($cb)`:
  ```
  fiber = new Fiber($cb); co = new Coroutine(...);
  State::registerFiberFlow(fiberId, CurrentFlow(async, flowKey));
  Scheduler::register(co); this.addCoroutine(co);
  fiber->start();                 // первичный прогон до 1-го suspend (может быть вложенным — ок)
  if fiber->isTerminated(): group.pushResult(...); cleanup;
  return callbackKey;
  ```
- `iterate()` (генератор):
  ```
  try {
    while (true) {
      while (group.hasReadyResults()) yield group.popReady();
      if (group.isSettled()) break;
      if (Fiber::getCurrent() === null) {
        Scheduler::get()->run($this);     // верхний уровень: крутим планировщик
      } else {
        Scheduler::get()->awaitGroup($this); // вложенный: отдать управление наверх
      }
    }
  } finally { $this->stop(); }
  ```
- `waitAll()`/`waitResults()` — поверх `iterate()` (как сейчас).
- `stop()` — размотать свои корутины (`Fiber::throw(FlowStoppedException)`),
  снять с Scheduler и `groupWaiters`, `State::deleteFlow`,
  `Extension::stopFlow`.

### 2.5 `FeatureExecutor`
- `exec()/next()` для async: `suspend()` остаётся `Fiber::suspend()`; меняется
  лишь то, что резюмит теперь Scheduler (а не `WaitGroup::iterate`). Семантика
  WaitingTask та же — возвращается `TaskResultDto`.
- sync-путь (`handleSync`, вне fiber) — без Scheduler; продолжает использовать
  `Extension::wait($flowKey)` (Фаза 1 сохранила) либо `waitAny` в цикле до
  своего `taskKey` (в sync-контексте конкуренции нет). Вложенности в sync нет.

### 2.6 DoD Фазы 2
- [ ] `Coroutine`, `Scheduler` (singleton, единый loop).
- [ ] `WaitGroup` делегирует add/iterate Scheduler; верхний уровень крутит `run`,
      вложенный — `awaitGroup`.
- [ ] Протокол WaitingTask/WaitingGroup; маршрутизация по `State`.
- [ ] Базовый сценарий вложенности проходит (внешняя «часовая» корутина
      завершается во время работы вложенной группы).

---

## ФАЗА 3 — Отмена и ресурсы под планировщик

- `stop()`/`stopFlow` для группы: размотка корутин + рекурсивная размотка
  вложенных групп (если внешняя остановлена, пока вложенная жива).
- Снятие `groupWaiters` и `coroutines` при остановке; гарантия отсутствия
  «осиротевших» ожидающих.
- Брошенный курсор (`IteratorResult` в async) — `next()` идёт через Scheduler как
  WaitingTask; `finally`/`__destruct` итератора закрывает поток.
- Перегон `tests/mem-leak/` + инвариант teardown «нет утёкших задач»
  (`BaseTestCase::assertNoTasksCount`).

### DoD Фазы 3
- [ ] Остановка внешней группы корректно разматывает вложенные.
- [ ] Нет утечек задач/курсоров; mem-leak стабилен.

---

## ФАЗА 4 — Тесты, порядок событий, доки

- **Переписать `GeneralTest::testMulti`** под новый конкурентный порядок
  (`1:finish` теперь раньше окончания внутреннего флоу) — это и есть
  подтверждение фикса.
- Новые тесты:
  - 2 уровня вложенности (внутри inner-корутины ещё один WG).
  - Параллелизм внешней/вложенной групп (характеризационный: внешняя «часовая»
    корутина обязана завершиться, пока вложенная группа с длинными задачами ещё
    работает).
  - **Siblings**: два независимых `WaitGroup` на верхнем уровне — проверить, что
    Scheduler корректно двигает оба (бонус-конкурентность; см. REVIEW «два
    сиблинг-WaitGroup не покрыты»).
- Обновить `BaseAsyncTestCase` (фреймворк проверки порядка) при необходимости.
- Удалить переходный `Handler.Wait`/`pending` и per-flow `wait` из C-glue/stub,
  перевести sync-путь на `waitAny`-в-цикле (если решено унифицировать).
- Отметить поведение в `.ai/README.md` (раздел про конкурентность/вложенность).

### DoD Фазы 4
- [ ] Все тесты зелёные с новым порядком; добавлены вложенность/siblings/2 уровня.
- [ ] (Опц.) удалён переходный per-flow механизм.
- [ ] Доки обновлены.

---

## ФАЗА 5 — Серверный режим (HTTP/socket-сервер с обработкой запросов в корутинах)

> Цель будущего применения: долгоживущий процесс-сервер, где **каждый входящий
> запрос обрабатывается в отдельной корутине**, конкурентно с другими и с
> вложенными корутинами. Это надстройка над `Scheduler` из Фаз 1–4.

Серверу нужны три вещи, которых нет в «группа + waitAll»-модели: динамический
**spawn** независимых корутин, **бесконечный** loop, и ожидание **двух
источников сразу** (сеть + Go-результаты).

### 5.1 `Scheduler::spawn(callback): Coroutine` — fire-and-forget корутина
- Регистрирует корутину БЕЗ привязки к `WaitGroup`-группе/`waitAll`.
- Свой служебный flowKey (или общий «серверный» flow). Результат/исключение —
  не собираются в очередь группы, а обрабатываются изолированно (логируются;
  падение одной не роняет остальные — переиспользуем `CallbackExecutionException`
  и recover-границу).
- Это основа «корутина = запрос».

### 5.2 `Scheduler::runForever(stopCondition)` — постоянный loop
- Вместо `run($group)` (крутить до завершения группы) — крутить, пока сервер
  жив; поддержать graceful shutdown (флаг + дренаж активных корутин).
- В этот loop в любой момент можно `spawn()` новые корутины (из обработчика
  accept).

### 5.3 Интеграция сетевого I/O (ключевая проблема)
Сейчас `waitAny` — **блокирующий cgo**: пока PHP-поток в cgo, он не может делать
`stream_select` на слушающем сокете и не диспатчит pcntl-сигналы (см. REVIEW2).
Серверу нужно ждать одновременно: (1) новые соединения/данные на сокете, (2)
готовые результаты Go-задач. Два пути:

- **(предпочтительно) fd-уведомления.** Go пишет байт в pipe/eventfd при
  готовности результата; PHP делает единый `stream_select` и по слушающему
  сокету, **и** по этому readiness-fd; когда fd сигналит — дренирует готовые
  результаты неблокирующим `waitAny` (или `tryWaitAny`). Требует на Go:
  экспорт readiness-fd (создать pipe, отдать его fd в PHP) + писать в него из
  `deliver`/при отправке в общий канал.
- **(простой) `waitAny(timeoutMs)`.** Неблокирующий вариант с таймаутом; loop
  чередует `stream_select(sockets, 0)` и `waitAny(small timeout)`. Проще, но
  поллинг (компромисс латентность/CPU).

Благодаря Варианту A точка ожидания одна (`Scheduler::tick`), поэтому интеграция
локализована в одном месте — в отличие от B, где ожидание размазано по `iterate`.

### 5.4 Отмена по дедлайну запроса
- Серверу нужны таймауты запросов → отмена отдельной корутины (см. ранее
  обсуждавшуюся задачу «остановка корутины из любой точки»): отменить связанную
  Go-задачу (`cancelTask`/`task.Cancel`) + размотать fiber (`Fiber::throw`) +
  снять с `Scheduler`. Реализуется поверх реестра корутин Scheduler.

### 5.5 Ограничения среды (обязательно задокументировать)
- Только CLI/worker, не FPM.
- `pcntl_fork` после загрузки расширения = смерть (Go-рантайм/горутины не
  переживают fork) — форкать воркеры ДО загрузки `.so` либо не форкать.
- ZTS не поддержан; поведение при `exit()` посреди активных задач не определено.

### DoD Фазы 5
- [ ] `Scheduler::spawn()` + `runForever()` + graceful shutdown.
- [ ] Интеграция сети: readiness-fd (предпочтительно) или `waitAny(timeoutMs)`.
- [ ] Отмена корутины по дедлайну (Go `cancelTask` + размотка fiber).
- [ ] Пример echo/HTTP-сервера в `tests/` или `examples/`; нагрузочный smoke.
- [ ] Ограничения среды описаны в `.ai/README.md`.

> Примечание: Фаза 5 — отдельный крупный блок, имеет смысл только после Фаз 1–4.
> `spawn()`/`runForever()` стоит заложить в API `Scheduler` уже в Фазе 2 (даже
> если пока используются только из `WaitGroup`), чтобы не ломать структуру позже.

---

## Риски / тонкости (Вариант A)
- **Двойной `suspend`** (WaitingTask vs WaitingGroup) — центральная сложность;
  держать строго через состояние `Coroutine` + `State`/`groupWaiters`.
- **Рекурсивные пробуждения**: `wakeGroupWaiters` вызывается внутри
  `resumeCoroutine` завершившейся корутины — глубина ограничена уровнем
  вложенности, fiber-источник уже terminated (не реентерабельно опасно).
- **Курсоры**: сохранить lifetime ctx initial-задачи (`IsNext || !HasNext`).
- **Однопоточность PHP не меняется**: планировщик не параллелит PHP-код; он лишь
  не даёт вложенному ожиданию монополизировать cgo-`wait`. Реальный параллелизм
  — на Go-горутинах.
- **`testMulti` сломается намеренно** — переписать под новый порядок.

## Сравнение A vs B
| | A: Scheduler | B: всплытие |
|---|---|---|
| Новые сущности | `Scheduler`, `Coroutine` | нет отдельного класса |
| Где живёт loop | явный singleton | самый внешний `iterate()` |
| Состояние групп/ожиданий | в Scheduler | размазано по `WaitGroup`+`State` |
| >2 уровней вложенности | прямолинейно | больше краевых случаев |
| Siblings на верхнем уровне | естественно | требует доп. логики |
| Связанность `FeatureExecutor`/`iterate` | низкая | высокая |
| Объём нового кода | больше | меньше по файлам, но логика хрупче |
| Риск багов | ниже | выше |

**Вывод:** оба требуют одинаковую Go-Фазу 1 (`waitAny`) и одинаковые механизмы
(глобальное ожидание, маршрутизация по `taskKey`, пробуждение владельца при
завершении группы). A выносит это в чистый планировщик; B размазывает по
существующим классам. Рекомендуется **A** — чище и расширяемее; B — если важно
минимизировать число новых сущностей и набор изменений.
