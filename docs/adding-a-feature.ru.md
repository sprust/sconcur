[English](adding-a-feature.md) | Русский

# Как добавить новую фичу верхнего уровня

Фича верхнего уровня — это новый домен со своим `Method` (как `Sleeper`). Эталон
для копирования — `Sleeper`: PHP в `src/Features/Sleeper/` (payloads — в
`src/Features/Sleeper/Payloads/`), Go в `ext/internal/features/sleeper/` (payloads — в
`ext/internal/features/sleeper/payloads/`).

Ниже — пошагово, в двух вариантах: без стриминга (один результат) и
со стримингом (несколько батчей). Конкретная работа фичи скрыта за «вашей
операцией». Общую архитектуру см. в [README](../README.ru.md).

> Делаете долгоживущий сетевой сервер (как `HttpServer`)? Это особый вид
> стриминговой фичи со своим слушателем и циклом обслуживания — см. отдельное
> руководство [Как добавить новый сервер](adding-a-server.ru.md).

---

## ⚠️ Два обязательных требования

Любой обработчик на Go-стороне обязан соблюдать оба правила. Нарушение приводит к
утечкам ресурсов и к неверному поведению `WaitGroup`.

1. **Отмена контекста.** Контекст задачи `task.GetContext()` отменяется при остановке
   потока (`WaitGroup::stop()`, ранний `break`, разрушение `WaitGroup`, `destroy`).
   Выполняйте работу на этом контексте; для долгих операций слушайте `ctx.Done()`
   через `select` — иначе задачу нельзя остановить. Для стриминга освобождайте ресурс
   на **свежем** контексте (`context.Background()` + таймаут): контекст задачи к
   моменту очистки уже отменён.

2. **Передача максимального времени выполнения.** При пуше задачи из PHP нужно
   передавать предельное время выполнения, а Go-сторона обязана им ограничить
   операцию — задача не должна выполняться неограниченно долго. Заложите этот параметр
   в payload фичи. Как его применяют:
   - иногда время и есть суть операции — `Sleeper` (длительность сна);
   - иногда таймаут прикладывается нативно — MongoDB передаёт
     `SConcur\Features\Mongodb\Connection\Client::$timeoutMs` (предельное время операции,
     CSOT) и `::$serverSelectionTimeoutMs` (сколько ждать доступный сервер, чтобы
     недоступный MongoDB не подвешивал задачу), а Go применяет их как
     `options.Client().ApplyURI(url).SetTimeout(...).SetServerSelectionTimeout(...)`;
   - общий способ — ограничить контекст задачи:
     `ctx, cancel := context.WithTimeout(task.GetContext(), timeout)`.

   (`ExecutionMs` в результате — это уже фактическое время работы, его проставляет
   `dto.NewSuccessResult`; с пунктом про таймаут не путать.)

---

## Соответствие `Method` PHP ↔ Go

Домен — это число, продублированное в двух местах; оба должны совпадать:

- PHP: `SConcur\Features\MethodEnum`
- Go: `ext/internal/types/method.go` (`Method`)

---

## Оформление payloads (PHP ↔ Go)

Payload — это контракт обмена между PHP и Go. Он оформляется зеркально с обеих
сторон, чтобы конвертация «PHP → Go» читалась наглядно.

Расположение:
- PHP: `src/Features/<Feature>/Payloads/` — по классу на каждый payload.
- Go: `ext/internal/features/<feature>/payloads/payloads.go` — все типы в одном файле
  пакета `payloads`.

Каталог фичи на Go называется так же, как PHP-домен (`Sleeper` → `sleeper`,
`Mongodb` → `mongodb`).

Соответствие 1:1: каждый PHP `*Payload` имеет Go-структуру с тем же именем.
Поля Go-структуры — это ключи, которые отдаёт `getData()`; теги `msgpack` (и `json`)
равны этим коротким ключам. Go декодирует payload именно по `msgpack`-тегам.

```go
// SleeperPayload is the payload of a sleep command.
// PHP: SConcur\Features\Sleeper\Payloads\SleeperPayload.
type SleeperPayload struct {
    Microseconds int64 `json:"us" msgpack:"us"`
}
```

Кросс-ссылки обязательны в обе стороны (комментарием):
- на Go-структуре: `// PHP: SConcur\Features\<Feature>\Payloads\<Class>`;
- на PHP-классе (docblock): `Go: payloads.<Type> (ext/internal/features/<feature>/payloads/payloads.go)`.

Мульти-командные фичи (эталон — `Mongodb`). Когда один `Method` обслуживает много
команд, payload двухуровневый:
- общий конверт (envelope) с полем команды и `dt` (сериализованное тело) —
  на Go это один тип `Payload`, на PHP его строит `Base\BaseMongodbPayload`;
- содержимое `dt` — по структуре на команду, имена зеркалят PHP `*Payload`.

Правила для таких фич:
- PHP-классы `*PayloadParameters` — это PHP-only удобство для сборки `dt`; на Go их
  не переносят. Их поля раскрывают прямо в соответствующую `*Payload`-структуру на Go
  (поля опций — инлайн).
- Если `dt` команды — это произвольный пользовательский документ/массив (insert, count,
  runCommand, …) или пусто (drop, list…), Go-структуры у неё нет: `dt` читается как
  raw BSON в обработчике. Такой случай помечают комментарием в `payloads.go`, чтобы
  каждому PHP `*Payload` соответствовала либо Go-структура, либо явная пометка.

Прочее: payload несёт предельное время выполнения (см. требование 2). PHP-payload —
`readonly`, поля типизированы, имена не сокращаются.

Эталоны: `Sleeper` (одна команда) и `Mongodb` (конверт + команды).

---

## Вариант A. Без стриминга (один результат)

### PHP

1. `MethodEnum` — новый кейс (строковое значение из 2-3 букв должно быть
   свободным и узнаваемым):
   ```php
   case Foo = 'foo';
   ```

2. Payload-класс `src/Features/Foo/Payloads/FooPayload.php`, реализующий
   `PayloadInterface` (оформление — см. «Оформление payloads» выше). `getMethod()`
   возвращает новый `Method`, `getData()` — параметры массивом (сериализуются в
   MessagePack):
   ```php
   /**
    * Go: payloads.FooPayload (ext/internal/features/foo/payloads/payloads.go).
    */
   readonly class FooPayload implements PayloadInterface
   {
       public function __construct(
           protected int $someParam,
           protected int $timeoutMs, // обязательный предельный срок выполнения
       ) {
       }

       public function getMethod(): MethodEnum
       {
           return MethodEnum::Foo;
       }

       /**
        * @return array<string, int>
        */
       public function getData(): array
       {
           return [
               'p'  => $this->someParam,
               'to' => $this->timeoutMs,
           ];
       }
   }
   ```

3. Публичный API `src/Features/Foo/Foo.php` — собрать payload и выполнить:
   ```php
   readonly class Foo
   {
       public function doFoo(int $someParam, int $timeoutMs): void
       {
           $taskResult = FeatureExecutor::exec(
               payload: new FooPayload(someParam: $someParam, timeoutMs: $timeoutMs),
           );

           // при необходимости — разбор $taskResult->payload
       }
   }
   ```

### Go

1. `types/method.go` — та же константа:
   ```go
   MethodFoo Method = "foo"
   ```

2. Пакет фичи `ext/internal/features/foo/feature.go`, реализующий
   `contracts.FeatureContract` (`Handle(task *tasks.Task)`). Внутри: разобрать
   `message.Payload`, выполнить работу на `task.GetContext()`, вернуть результат с
   `ExecutionMs`:
   ```go
   var errFactory = errs.NewErrorsFactory("foo")

   type FooFeature struct{}

   func (f *FooFeature) Handle(task *tasks.Task) {
       start := time.Now()
       message := task.GetMessage()

       var payload payloads.FooPayload // payloads.FooPayload зеркалит PHP FooPayload; TimeoutMs с тегом msgpack:"to"

       if err := msgpack.Unmarshal(message.Payload, &payload); err != nil {
           task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse error", err)))
           return
       }

       // Ограничиваем работу переданным таймаутом; этот же ctx отменяется при стопе.
       ctx, cancel := context.WithTimeout(
           task.GetContext(),
           time.Duration(payload.TimeoutMs)*time.Millisecond,
       )
       defer cancel()

       result, err := doFoo(ctx) // ваша операция; обязана уважать ctx

       if err != nil {
           task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("foo error", err)))
           return
       }

       task.AddResult(dto.NewSuccessResult(message, result, helpers.CalcExecutionMs(start)))
   }
   ```
   (как у `Sleeper`, фичу обычно делают синглтоном через `sync.Once` + `Get()`.)

3. Регистрация в `ext/internal/features/factory.go` — кейс в `DetectMessageHandler`:
   ```go
   case types.MethodFoo:
       return foo_feature.Get(), nil
   ```

---

## Вариант B. Со стримингом (батчами)

Стриминг отдаёт результат частями: Go держит «состояние», PHP тянет следующие батчи.
Маршрутизация `next` к состоянию — общая для всех фич, отдельно её настраивать не нужно.

### PHP

1. `MethodEnum` + Payload — как в варианте A.

2. Публичный API возвращает `IteratorResult`, обёрнутый вокруг payload — он сам
   запросит первый и последующие батчи:
   ```php
   /**
    * @return Iterator<int, mixed>
    */
   public function doFoo(int $someParam): Iterator
   {
       return new IteratorResult(
           payload: new FooPayload(someParam: $someParam),
       );
   }
   ```

### Go

1. `types/method.go` — константа (как в A).

2. Состояние `ext/internal/features/foo/state/foo.go`, реализующее
   `contracts.StateContract` (`Next() *dto.Result`, `Close()`):
   ```go
   type FooState struct {
       // mutex сериализует Next и Close: Close может прийти из отмены контекста,
       // пока Next ещё использует ресурс.
       mutex     sync.Mutex
       ctx       context.Context
       message   *dto.Message
       startTime time.Time
       // удерживаемый ресурс + параметры
   }

   func (s *FooState) Next() *dto.Result {
       s.mutex.Lock()
       defer s.mutex.Unlock()

       // лениво инициализировать ресурс на s.ctx при первом вызове, прочитать батч

       // есть ещё данные → батч с флагом «будет ещё»:
       return dto.NewSuccessResultWithNext(s.message, response, helpers.CalcExecutionMs(s.startTime))
       // последний батч → без флага (состояние удалится, Close() вызовется):
       // return dto.NewSuccessResult(s.message, response, helpers.CalcExecutionMs(s.startTime))
   }

   // Close освобождает ресурс на СВЕЖЕМ контексте: контекст задачи уже отменён.
   func (s *FooState) Close() {
       s.mutex.Lock()
       defer s.mutex.Unlock()

       closeCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
       defer cancel()

       // освободить удерживаемый ресурс на closeCtx
   }
   ```

3. `Handle` фичи создаёт состояние и запускает его через реестр состояний;
   `states.Get().Start` сам зарегистрирует `Close()` на отмену контекста и вернёт
   первый батч:
   ```go
   func (f *FooFeature) Handle(task *tasks.Task) {
       message := task.GetMessage()
       // ... разбор message.Payload ...

       state := state.New(task.GetContext(), message /*, параметры */)

       result, err := states.Get().Start(task.GetContext(), message.TaskKey, state)
       if err != nil {
           task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("foo", err)))
           return
       }

       task.AddResult(result)
   }
   ```

4. Регистрация в `factory.go` — как в A.

> Недотянутый поток (ранний `break` на PHP) закрывается автоматически: PHP освобождает
> поток, контекст задачи отменяется, и хук реестра состояний зовёт `Close()`. Поэтому
> `Close()` обязан работать на свежем контексте.

---

## Тесты (обязательно)

- На каждую фичу — отдельный тест. Если у фичи есть под-операции — тест на каждую.
- Все тесты наследуются от `BaseTestCase` (напрямую или через `BaseAsyncTestCase`).
  `BaseTestCase` управляет жизненным циклом расширения и в `tearDown` проверяет
  отсутствие «висящих» задач — это ловит утечки и забытую отмену контекста.
- Тест фичи пишется с родителем `BaseAsyncTestCase` — он задаёт асинхронный
  паттерн: два конкурентных таска через `WaitGroup`, проверка порядка событий,
  конкурентности и пути с исключением (синхронного и асинхронного). Реализуйте хуки:
  - `on_1_start` / `on_1_middle`, `on_2_start` / `on_2_middle` — шаги двух тасков
    (вызывайте внутри свою операцию);
  - `on_iterate` — действие на каждой итерации результата;
  - `on_exception` — вызов, который обязан бросить исключение;
  - `assertException(Throwable)` — проверка исключения;
  - `assertResult(array $results)` — проверка результатов; здесь же проверяют
    конкурентность (общее время ≈ самой медленной операции, а не сумме).

  Эталон — `tests/feature/Features/Sleeper/SleeperTest.php`.
- Краевые/синхронные проверки добавляйте отдельными тестами от `BaseTestCase`.
- Go-логику покрывайте Go-тестами (`make ext-test`).

---

## Чеклист

PHP:
- [ ] `MethodEnum` — новое значение.
- [ ] Payload-класс (`PayloadInterface`) в `src/Features/<Feature>/Payloads/`; сборка
      параметров — внутри него; payload несёт предельное время выполнения (таймаут);
      docblock с кросс-ссылкой `Go: payloads.<Type>`.
- [ ] Публичный API (для стриминга — возвращает `IteratorResult`).
- [ ] Тест от `BaseAsyncTestCase` + краевые тесты от `BaseTestCase`.

Go:
- [ ] Та же константа в `types/method.go`.
- [ ] Payload-структуры в `ext/internal/features/<feature>/payloads/payloads.go`,
      зеркалят PHP `*Payload` 1:1 (имена, `msgpack`-теги) + кросс-ссылка `// PHP: …`.
- [ ] Пакет фичи с `Handle`: контекст задачи во все вызовы; работа ограничена переданным
      таймаутом; для стриминга — состояние `StateContract` + `Close()` на свежем контексте.
- [ ] Регистрация в `features/factory.go`.
- [ ] (опц.) Go-тесты.

Проверка: `make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test`.
