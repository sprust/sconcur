# Как добавить новую фичу

Инструкция по добавлению новой операции в SConcur — на обеих сторонах (PHP и Go),
в двух вариантах: **без стриминга** (один результат) и **со стримингом** (несколько
батчей). Общую архитектуру см. в [README](../README.md).

Описание абстрактное — без деталей конкретных драйверов. Конкретные операции (запрос
к БД, чтение и т.п.) скрыты за вызовом «вашей операции»; за рабочими примерами
смотрите существующие команды.

> Большинство фич — это новые **команды MongoDB** (под-фичи фичи `Mongodb`). Реже
> нужна **новая фича верхнего уровня** (как `Sleeper`) — см. отдельный раздел.

---

## ⚠️ Два обязательных требования

Любой обработчик на Go-стороне обязан соблюдать оба правила. Нарушение приводит к
утечкам ресурсов (горутины, открытые ресурсы, соединения) и к неверному поведению
`WaitGroup`.

### 1. Отмена контекста (обязательно)

Контекст задачи `task.GetContext()` **отменяется**, когда поток останавливают
(`WaitGroup::stop()`, ранний `break` в `iterate()`, разрушение `WaitGroup`, `destroy`).
Поэтому:

- Все блокирующие/IO-операции выполняются на **контексте задачи**, а не на
  `context.Background()`. Тогда при отмене операция немедленно прерывается.
- Для долгоживущих собственных операций слушайте `ctx.Done()` через `select`
  (см. `Sleeper`), иначе задачу нельзя будет остановить.
- Для стриминга освобождение удерживаемого ресурса делается **на свежем контексте**
  (`context.Background()` + таймаут): к моменту очистки контекст задачи уже отменён,
  и финальные действия по освобождению через него до сервера не дойдут.

### 2. Передача времени выполнения (обязательно)

Каждый результат обязан нести `ExecutionMs` — длительность **самой работы**,
замеренную от её старта. Это время уходит в PHP (`TaskResultDto::executionMs`) и
определяет модель конкурентности: суммарное время `WaitGroup` ≈ время самой
**медленной** задачи. Никогда не возвращайте успешный результат с `ExecutionMs == 0`.

На практике это бесплатно:

- обычные обработчики используют хелперы результата `documentResult` / `stringResult` /
  `singleResult` (`ext/internal/features/mongodb/connection/execute.go`) — они сами
  замеряют время и пробрасывают его в результат;
- стриминговые состояния используют `helpers.CalcExecutionMs(startTime)` и
  `dto.NewSuccessResult` / `dto.NewSuccessResultWithNext`.

---

## Соответствие команд PHP ↔ Go

Команда — это число, продублированное в двух enum'ах. **Оба** должны совпадать:

- PHP: `SConcur\Features\Mongodb\CommandEnum`
- Go: `ext/internal/types/mongodb.go` (`MongodbCommand`)

Добавляя команду, заводите одинаковое значение в обоих местах.

---

## Вариант A. Команда без стриминга (один результат)

### PHP

1. **`CommandEnum`** — новый кейс:
   ```php
   case Foo = 27;
   ```

2. **Payload-класс** `src/Features/Mongodb/Payloads/FooPayload.php`. Вся сборка
   параметров живёт здесь (в `getParameters()`), вызывающий код передаёт только сырой
   вход. Базовый класс сериализует параметры в единый payload (`getData()`).
   ```php
   readonly class FooPayload extends BaseMongodbPayload
   {
       /**
        * @param array<string, mixed> $filter
        */
       public function __construct(
           public Connection $connection,
           public array $filter,
       ) {
       }

       protected function getCommand(): CommandEnum
       {
           return CommandEnum::Foo;
       }

       protected function getConnection(): Connection
       {
           return $this->connection;
       }

       protected function getParameters(): Parameters
       {
           return new Parameters(
               data: [
                   'f' => $this->filter,
               ],
               isObject: true,
           );
       }
   }
   ```
   Ключи `data` (`f`, …) — это имена полей, которые читает Go. Для общих опций есть
   хелпер `encodeOptions()` в базовом классе.

3. **Метод входа** в `Collection` (или `Database`/`Client`) — собрать payload,
   выполнить, разобрать результат:
   ```php
   public function foo(array $filter): FooResult
   {
       $taskResult = $this->exec(
           payload: new FooPayload(
               connection: $this->connection,
               filter: $filter,
           ),
       );

       return new FooResult(/* разбор $taskResult->payload */);
   }
   ```

### Go

1. **`types/mongodb.go`** — та же константа:
   ```go
   MongodbFoo MongodbCommand = 27
   ```

2. **`objects/objects.go`** — структура параметров (если полей несколько). Теги
   `msgpack` совпадают с ключами `data` из PHP:
   ```go
   type FooParams struct {
       Filter []byte `json:"f" msgpack:"f"`
   }
   ```
   > Если параметр — ровно один объект, структура не нужна: читайте `payload.Data`
   > напрямую.

3. **Обработчик** в `connection/collection.go` (или `database.go`): разобрать
   параметры и **обернуть вызов в хелпер результата**. Хелпер сам пробрасывает
   контекст-результат и замеряет `ExecutionMs` — оба обязательных требования
   выполняются автоматически.
   ```go
   func (c *Collection) Foo(
       ctx context.Context,
       message *dto.Message,
       payload *objects.Payload,
   ) *dto.Result {
       var params objects.FooParams

       if err := objects.UnmarshalParams(payload.Data, &params); err != nil {
           return dto.NewErrorResult(message, errFactory.ByErr("parse foo params", err))
       }

       // ctx обязательно уходит в вашу операцию → отмена работает.
       return documentResult(message, "foo", func() (interface{}, error) {
           return /* ваша операция на ctx, возвращает (результат, error) */
       })
   }
   ```
   Какой хелпер выбрать (сигнатуры — в `execute.go`):
   - `documentResult` — результат-объект (сериализуется автоматически);
   - `stringResult` — готовая строка (счётчики, идентификаторы, пустой ответ);
   - `singleResult` — одиночный результат, где «не найдено» = пустой успех.

4. **Регистрация в диспетчере** `features/collection/feature.go` — одна запись в карте:
   ```go
   var collectionHandlers = map[types.MongodbCommand]collectionHandler{
       // ...
       types.MongodbFoo: (*connection.Collection).Foo,
   }
   ```
   Для команд уровня БД — в `databaseHandlers`.

---

## Вариант B. Команда со стримингом

Стриминг отдаёт результат **батчами**: Go держит «состояние» (открытый ресурс), а PHP
тянет следующие батчи. На PHP это инкапсулирует `IteratorResult`.

### PHP

1. **`CommandEnum`** + **Payload** — как в варианте A (payload несёт фильтр, размер
   батча и т.п.).

2. **Метод входа** возвращает `IteratorResult`, обёрнутый вокруг payload — он сам
   запросит первый и последующие батчи:
   ```php
   /**
    * @return Iterator<int, array<int|string, mixed>>
    */
   public function foo(array $filter, int $batchSize = 50): Iterator
   {
       return new IteratorResult(
           payload: new FooPayload(
               connection: $this->connection,
               filter: $filter,
               batchSize: $batchSize,
           ),
       );
   }
   ```

### Go

1. **`types/mongodb.go`** — константа (как в A).

2. **Состояние** `states/foo_state/foo.go`, реализующее `contracts.StateContract`
   (`Next() *dto.Result`, `Close()`). Оба обязательных требования здесь критичны:
   ```go
   type FooState struct {
       // mutex сериализует Next и Close: Close может прийти из отмены контекста,
       // пока Next ещё использует удерживаемый ресурс.
       mutex     sync.Mutex
       ctx       context.Context
       message   *dto.Message
       startTime time.Time
       // удерживаемый ресурс + параметры (фильтр, batchSize, ...)
   }

   func (s *FooState) Next() *dto.Result {
       s.mutex.Lock()
       defer s.mutex.Unlock()

       // при первом вызове — лениво открыть ресурс на s.ctx, запомнить s.startTime
       // прочитать следующий батч на s.ctx

       // есть ещё данные → батч с флагом «будет ещё»:
       return dto.NewSuccessResultWithNext(s.message, response, helpers.CalcExecutionMs(s.startTime))
       // последний батч → без флага (состояние удалится, Close() вызовется):
       // return dto.NewSuccessResult(s.message, response, helpers.CalcExecutionMs(s.startTime))
   }

   // Close освобождает ресурс на СВЕЖЕМ контексте: контекст задачи к этому моменту
   // уже отменён, и освобождение через него до сервера не дошло бы.
   func (s *FooState) Close() {
       s.mutex.Lock()
       defer s.mutex.Unlock()

       closeCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
       defer cancel()

       // освободить удерживаемый ресурс на closeCtx
   }
   ```

3. **Обработчик** создаёт состояние и запускает его. `states.Get().Start` сам
   регистрирует отмену: при отмене контекста задачи будет вызван `Close()`. Возвращается
   первый батч:
   ```go
   func (c *Collection) Foo(
       ctx context.Context,
       message *dto.Message,
       payload *objects.Payload,
   ) *dto.Result {
       // ... разбор параметров ...

       state := foo_state.New(ctx, message /*, параметры */)

       result, err := states.Get().Start(ctx, message.TaskKey, state)
       if err != nil {
           return dto.NewErrorResult(message, errFactory.ByErr("foo", err))
       }

       return result
   }
   ```

4. **Регистрация в диспетчере** — как в A.

> Недотянутый до конца поток (ранний `break` на PHP) закрывается автоматически: PHP
> освобождает поток, контекст задачи отменяется, и хук `states.Start` зовёт `Close()`.
> Именно поэтому `Close()` обязан работать на свежем контексте.

---

## Новая фича верхнего уровня (как `Sleeper`)

Если нужна не команда MongoDB, а отдельный домен (новый `Method`):

1. **`types/method.go`** — новый `Method` + та же константа в PHP `MethodEnum`.
2. **Go-фича** в `ext/internal/features/<name>/` — реализует `contracts.FeatureContract`
   (`Handle(task *tasks.Task)`). Внутри: разобрать payload; выполнять работу на
   `task.GetContext()` и слушать `ctx.Done()` для отмены; вернуть результат с
   `ExecutionMs`.
3. **`features/factory.go`** — добавить кейс в `DetectMessageHandler`.
4. **PHP**: payload-класс, реализующий `PayloadInterface` (`getMethod()` → новый
   `MethodEnum`, `getData()`), и точка входа в `FeatureExecutor::exec()`.

`Sleeper` — минимальный эталон: приём параметра, проверка, отмена через `ctx.Done()`,
возврат времени выполнения.

---

## Тесты (обязательно)

Тесты пишутся обязательно. Правила:

- **Под каждую фичу и каждую её под-фичу — отдельный тест.** Для MongoDB это значит:
  **на каждую команду — свой тест** (`insertOne`, `find`, `bulkWrite`, … — отдельные
  тест-классы).
- **Все тесты наследуются от `BaseTestCase`** (напрямую или через `BaseAsyncTestCase`).
  `BaseTestCase` управляет жизненным циклом расширения и в `tearDown` проверяет, что
  после теста **не осталось «висящих» задач** (`assertNoTasksCount`) — это ловит утечки
  и забытую отмену контекста.
- **Тест под-фичи пишется с родителем `BaseAsyncTestCase`** — он задаёт асинхронный
  паттерн: запускает два конкурентных таска через `WaitGroup`, проверяет порядок
  событий и конкурентность, а также путь с исключением (синхронный и асинхронный).
  Вы реализуете хуки:
  - `on_1_start` / `on_1_middle` и `on_2_start` / `on_2_middle` — шаги двух тасков
    (внутри вызывайте свою операцию);
  - `on_iterate` — действие на каждой итерации результата;
  - `on_exception` — вызов, который обязан бросить исключение (например невалидный
    ввод);
  - `assertException(Throwable)` — проверка исключения;
  - `assertResult(array $results)` — проверка результатов; здесь же проверяют
    конкурентность (общее время ≈ самой медленной операции, а не сумме).

  Эталон — `tests/feature/Features/Sleeper/SleeperTest.php`.

- Дополнительные синхронные/краевые проверки (опции, ошибки, форматы) добавляйте
  отдельными методами/классами от `BaseTestCase`.
- Go-логику (сериализация, состояния) покрывайте Go-тестами в `ext/...` (`make ext-test`).

---

## Чеклист

PHP:
- [ ] `CommandEnum` / `MethodEnum` — новое значение.
- [ ] Payload-класс; вся сборка параметров — внутри него.
- [ ] Точка входа в `Collection`/`Database`/`Client` (для стриминга — возвращает `IteratorResult`).
- [ ] (опц.) Result-DTO.
- [ ] Тест от `BaseAsyncTestCase` на под-фичу + краевые тесты от `BaseTestCase`.

Go:
- [ ] Та же константа в `types/`.
- [ ] (опц.) `objects.*Params` с тегами под ключи `data`.
- [ ] Обработчик: контекст задачи во все вызовы; результат через хелпер (без стрима)
      или через состояние (`StateContract` + `Close()` на свежем контексте, стрим).
- [ ] Запись в карте `collectionHandlers`/`databaseHandlers`.
- [ ] (опц.) Go-тесты.

Проверка: `make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test`.
