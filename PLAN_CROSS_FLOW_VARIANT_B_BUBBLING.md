# Кросс-флоу конкурентность — Вариант B: всплытие ожидания (bubbling)

> Задача: вложенные корутины (вложенный `WaitGroup` внутри корутины внешнего)
> должны выполняться конкурентно между собой И параллельно с задачами внешнего
> флоу, а не блокировать его до своего завершения.
>
> **СТАТУС: НЕ РЕАЛИЗОВАН.** Внедрён Вариант A
> (`PLAN_CROSS_FLOW_VARIANT_A_SCHEDULER.md`, Фазы 1–4). Этот документ сохранён как
> описание альтернативы и обоснование выбора.
>
> Это **Вариант B** (альтернатива без отдельного планировщика). Реализованный —
> `PLAN_CROSS_FLOW_VARIANT_A_SCHEDULER.md`. Сравнение — в конце обоих файлов.

---

## 0. Корень проблемы (общий для обоих вариантов)

Конкурентность на Go уже есть (каждая задача — горутина). Блокировка — в
PHP-слое: `WaitGroup::iterate()` крутит **свой** блокирующий цикл
`wait(flowKey)` (per-flow канал, `ext/main.go:80`). Вложенный `WaitGroup` внутри
корутины внешнего запускает такой цикл **в стеке fiber B** и блокирует
однопоточный PHP на `wait(flow_inner)`, пока его группа не завершится → внешний
флоу простаивает. Симптом — `GeneralTest::testMulti` (`1:finish` «переезжает» за
весь внутренний флоу).

---

## 1. Идея Варианта B

Без отдельного класса-планировщика. Роль event-loop играет **самый внешний**
`iterate()` (вызванный вне fiber). Вложенные `iterate()` не блокируют, а
«всплывают»: когда у вложенной группы нет готовых результатов, корутина-владелец
делает `Fiber::suspend()`, отдавая управление наверх. Самый внешний `iterate`
становится мультиплексором: делает глобальный `waitAny()` (Фаза 1, Go) и
маршрутизирует результаты по `taskKey` нужным корутинам через `State`.

Отличие от A — нет singleton-Scheduler: его обязанности распределены между самым
внешним `iterate()` (loop) и `State` (реестр маршрутизации и «ожидающих
владельцев групп»).

> Честная оговорка: B всё равно требует те же механизмы, что и A
> (глобальный `waitAny`, маршрутизация по `taskKey`, пробуждение владельца при
> завершении группы), но размещает их в существующих классах вместо нового
> Scheduler. Это «меньше файлов», но «больше связанности».

---

## 2. Целевой поток (трейс на `testMulti`)

- **t=0** main → `outer.iterate()` (это самый внешний loop). Старт A
  (suspend sleep60), B (suspend sleep120).
- Внешний loop: `waitAny()`.
- **t=60** sleep60(A) → найти fiber по `taskKey` (`State::pullFiberByTask`) →
  `resume(A)` → `1:woke`, suspend(sleep180).
- **t=120** sleep120(B) → `resume(B)` → B создаёт inner WG, `add(2.1)`,
  `add(2.2)` (стартуют, suspend на свои задачи), затем `inner.waitAll()`.
  inner `iterate`: готовых нет, корутины живы → **вложенный** → `Fiber::suspend(B)`
  c маркером `PendingChildren(innerFlowKey)`. Управление возвращается в внешний
  `resume(B)`.
- Внешний loop регистрирует, что B ждёт завершения `innerGroup`
  (`State::setGroupWaiter(innerKey, fiberId(B))`), и продолжает `waitAny()`:
- **t=180** sleep60(2.1) → `resume(2.1)` напрямую (fiber найден по taskKey —
  он НЕ вложен в B, т.к. все resume идут из внешнего loop) → `2.1:woke`.
- **t=240** sleep180(A) → `resume(A)` → `1:finish`, terminated. ← цель.
- … 2.1/2.2 завершаются; при завершении последней корутины innerGroup внешний
  loop видит «группа пуста» + есть groupWaiter(B) → `resume(B)` → B возвращается
  в inner `iterate` (после suspend), забирает результаты, `waitAll` возвращается,
  B: `2:woke` … `2:finish`.

Тот же результат, что и в A; разница — в том, кто «оркестрирует» (внешний
`iterate` вместо Scheduler).

---

## ФАЗА 1 — Go + протокол `waitAny`

**Идентична Фазе 1 Варианта A** (см. `PLAN_CROSS_FLOW_VARIANT_A_SCHEDULER.md`,
раздел «ФАЗА 1»). Кратко:

- `Handler` владеет **общим** каналом результатов + переходный буфер `pending`.
- `Handler.WaitAny()` — вернуть первый готовый результат любого flow;
  `Handler.Wait(flowKey)` — переходная совместимость через демультиплексор.
- `Flow.OnDelivered` (перенос пост-обработки), `Flow.Wait` удалён; общий канал
  через `NewFlow`/`InitFlow`.
- `main.go` export `waitAny`; `sconcur.c`/`stub` — `PHP_FUNCTION(waitAny)`;
  `Extension::waitAny(): TaskResultDto`.
- bump версии (`main.go`, `sconcur.c`, `REQUIRED_EXTENSION_VERSION`) → `0.1.0`.
- Go-тесты (waitAny по готовности, учёт задач, отмена ctx/курсор, демультиплексор,
  Destroy разблокирует).

DoD Фазы 1 — как в Варианте A. Поведение PHP не меняется.

---

## ФАЗА 2 — Маркер всплытия + внешний `iterate` как мультиплексор

### 2.1 Ключевое решение: единая точка `resume`
Чтобы fibers не вкладывались по стеку (иначе `suspend` вложенной корутины вернёт
управление НЕ туда), **все `resume` должны идти только из самого внешнего
`iterate`**. Старт корутины в `add()` (первый прогон до первого suspend)
допустимо вложенный — он короткий и заканчивается suspend, возвращающим в `add`.
Дальнейшие `resume` — исключительно из внешнего loop.

Следствие: нужно различать «я — внешний loop» и «я — вложенный iterate».
Признак — `Fiber::getCurrent() === null` (вне fiber = верхний уровень).

### 2.2 Маркер ожидания вложенной группы
Новый тип, передаётся через `Fiber::suspend($marker)`:
```php
// src/Flow/PendingChildren.php (readonly)
final class PendingChildren
{
    public function __construct(public string $childFlowKey, public string $waiterFlowKey) {}
}
```
- Обычный async-вызов фичи (`FeatureExecutor::suspend`) по-прежнему делает
  `Fiber::suspend()` (без маркера) → семантика WaitingTask.
- Вложенный `iterate`, не имея готовых результатов, делает
  `Fiber::suspend(new PendingChildren(innerKey, outerCoroutineFlowKey))`.

### 2.3 Расширение `State` (вместо Scheduler)
`State` берёт на себя реестр маршрутизации и «ожидающих владельцев групп»:
```
- (есть) fiberTasks[flowKey][taskKey] = fiberId        // маршрутизация WaitingTask
- (есть) flowFibers[flowKey][fiberId]                  // корутины группы
+ groupWaiters[childFlowKey] = waiterFiberId            // кто ждёт завершения под-группы
+ liveCoroutineCount(flowKey): int                      // для «группа пуста?»
+ setGroupWaiter / clearGroupWaiter / getGroupWaiter
```
Также нужен глобальный реестр `fiberId → Fiber`, чтобы внешний loop мог
резюмить любой fiber (сейчас `Fiber`-объекты живут в `WaitGroup::$fibers`).
Вынести в `State` (`fibers[fiberId] = Fiber`) или в отдельный реестр.

### 2.4 Внешний `iterate` как мультиплексор
```php
public function iterate(): Generator
{
    try {
        while (true) {
            while ($this->hasReadyResults()) { yield $this->popReady(); }
            if ($this->isSettled()) { break; }

            if (Fiber::getCurrent() === null) {
                // ── самый внешний loop: единственный, кто ждёт и резюмит ──
                $this->pump();              // один шаг мультиплексора
            } else {
                // ── вложенный: всплыть наверх ──
                State::setGroupWaiter($this->flowKey, spl_object_id(Fiber::getCurrent()));
                Fiber::suspend(new PendingChildren($this->flowKey, /*waiter flow*/ ...));
                // вернёмся сюда, когда внешний loop разбудит владельца
            }
        }
    } finally { $this->stop(); }
}

private function pump(): void
{
    $result   = Extension::get()->waitAny();          // любой flow
    $fiberId  = State::pullFiberByTask($result->flowKey, $result->taskKey);
    $fiber    = State::getFiber($fiberId);
    $this->resumeFiber($fiber, $result);
}

private function resumeFiber(Fiber $fiber, mixed $value): void
{
    try { $fiber->resume($value); }
    catch (Throwable $e) { throw new CallbackExecutionException(message: $e->getMessage(), previous: $e); }

    if ($fiber->isTerminated()) {
        $this->onTerminated($fiber);                  // в очередь группы-владельца
        return;
    }
    // если fiber снова suspended с PendingChildren — это вложенный waiter:
    //   зафиксировать ожидание (setGroupWaiter уже сделан в iterate ветке) и продолжить pump
}
```

### 2.5 Завершение корутины и пробуждение владельца
```php
private function onTerminated(Fiber $fiber): void
{
    $group = State::groupOf($fiber);           // по flowKey
    $group->pushReady($callbackKey, $fiber->getReturn());
    State::unRegisterFiber($fiberId);

    // если группа опустела и её ждёт владелец (вложенный waitAll) — разбудить
    if (State::liveCoroutineCount($group->flowKey) === 0) {
        $waiterId = State::getGroupWaiter($group->flowKey);
        if ($waiterId !== null) {
            State::clearGroupWaiter($group->flowKey);
            $this->resumeFiber(State::getFiber($waiterId), null);
        }
    }
}
```
Рекурсия `resumeFiber → onTerminated → resumeFiber(waiter)` ограничена глубиной
вложенности; fiber-источник уже terminated.

### 2.6 `add()` и старт корутины
```php
public function add(Closure $cb): string
{
    $fiber = new Fiber($cb);
    State::registerFiberFlow(fiberId, CurrentFlow(async, $this->flowKey));
    State::registerFiber($fiberId, $fiber);            // глобальный реестр
    $this->addToGroup($fiberId, $callbackKey);
    try { $fiber->start(); }                            // вложенный старт допустим
    catch (Throwable $e) { ...cleanup...; throw new CallbackExecutionException(...); }
    if ($fiber->isTerminated()) { $this->pushReady(...); cleanup; }
    return $callbackKey;
}
```

### 2.7 `FeatureExecutor`
- async `suspend()` — `Fiber::suspend()` без маркера (WaitingTask). Резюмит
  теперь внешний `pump()` (по `taskKey`).
- sync-путь — без изменений (`Extension::wait($flowKey)`; вложенности нет).

### 2.8 DoD Фазы 2
- [ ] `PendingChildren` + расширение `State` (groupWaiters, реестр fibers,
      liveCoroutineCount).
- [ ] Внешний `iterate` = `pump()`; вложенный `iterate` всплывает через suspend.
- [ ] Базовый сценарий вложенности проходит (внешняя «часовая» корутина
      завершается во время работы вложенной группы).

---

## ФАЗА 3 — Многоуровневая вложенность и маршрутизация

- Всплытие через **несколько уровней**: если waiter сам вложен, его пробуждение
  по `PendingChildren` корректно прокидывается до самого внешнего loop. Проверить
  цепочку из 3 уровней.
- Гарантия «единственного loop»: вложенный `iterate` НИКОГДА не вызывает
  `pump()`/`waitAny` (иначе вернётся блокировка). Только верхний (`getCurrent()===null`).
- Отмена/ресурсы: `stop()` группы размывает её корутины и снимает её
  `groupWaiter`; рекурсивная размотка вложенных групп при остановке внешней;
  брошенный курсор (`IteratorResult` async) через `next()` → WaitingTask.
- mem-leak + инвариант teardown «нет утёкших задач».

### DoD Фазы 3
- [ ] 3 уровня вложенности работают конкурентно.
- [ ] Нет утечек; остановка внешней группы корректно разматывает вложенные.

---

## ФАЗА 4 — Тесты, порядок событий, доки

- **Переписать `GeneralTest::testMulti`** под новый конкурентный порядок (это
  подтверждение фикса).
- Новые тесты: 2–3 уровня вложенности; характеризационный «внешняя часовая
  корутина завершается во время работы вложенной группы»; siblings на верхнем
  уровне (внешний loop первого `iterate` побочно двигает второй — описать и
  покрыть).
- Удалить переходный per-flow `wait`/`pending`, перевести sync на `waitAny`
  (если решено унифицировать).
- Обновить `.ai/README.md`.

### DoD Фазы 4
- [ ] Все тесты зелёные с новым порядком; вложенность/siblings покрыты.
- [ ] (Опц.) удалён переходный per-flow механизм; доки обновлены.

---

## Риски / тонкости (Вариант B)
- **«Кто главный loop» определяется динамически** (`Fiber::getCurrent()===null`).
  Ошибка — если вложенный случайно сделает `waitAny` → вернётся блокировка.
  Жёстко зафиксировать инвариант и покрыть тестом.
- **Состояние размазано**: `State` обретает обязанности планировщика
  (groupWaiters, реестр fibers, счётчики) → выше связанность и риск
  рассинхрона по сравнению с явным Scheduler.
- **Многоуровневое всплытие** — больше краевых случаев (waiter сам waiter).
- **Siblings**: первый внешний `iterate` побочно двигает чужие группы; их
  результаты копятся в очередях до их `iterate`. Нужно аккуратно определить
  завершение «своей» группы и не «украсть» чужие результаты безвозвратно.
- **Курсоры**: lifetime ctx initial-задачи (`IsNext || !HasNext`) — сохранить.
- **`testMulti` сломается намеренно** — переписать.

## Сравнение A vs B
| | A: Scheduler | B: всплытие |
|---|---|---|
| Новые сущности | `Scheduler`, `Coroutine` | `PendingChildren` + расширение `State` |
| Где живёт loop | явный singleton | самый внешний `iterate()` |
| Состояние групп/ожиданий | в Scheduler | размазано по `WaitGroup`+`State` |
| >2 уровней вложенности | прямолинейно | больше краевых случаев |
| Siblings на верхнем уровне | естественно | требует доп. логики |
| Связанность `FeatureExecutor`/`iterate` | низкая | высокая |
| Объём нового кода | больше | меньше по файлам, но логика хрупче |
| Риск багов | ниже | выше |

**Вывод:** оба варианта используют одинаковую Go-Фазу 1 (`waitAny`) и одинаковые
механизмы (глобальное ожидание, маршрутизация по `taskKey`, пробуждение
владельца при завершении группы). **A** выносит это в чистый планировщик и
рекомендуется. **B** не вводит новый класс, но фактически возлагает роль
планировщика на самый внешний `iterate` + `State` — меньше сущностей ценой
большей связанности и числа краевых случаев (особенно на siblings и глубокой
вложенности).
