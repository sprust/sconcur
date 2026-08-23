[English](adding-a-feature.md) | Русский

# Как добавить новую фичу верхнего уровня

Фича верхнего уровня — это новый домен со своим `Method` (как `Sleeper`). Образец
для копирования — `Sleeper`: PHP в `src/Features/Sleeper/`, Go в
`ext/internal/features/sleeper/`. Ниже разбор в двух вариантах — без стриминга
(один результат) и со стримингом (несколько батчей).

> Делаете долгоживущий сетевой сервер (как `HttpServer`)? Это особый вид
> стриминговой фичи со своим листенером и циклом обслуживания — см.
> [Как добавить новый сервер](adding-a-server.ru.md).

## Два обязательных требования

Каждый обработчик на Go-стороне обязан выполнять оба; их нарушение приводит к
утечкам ресурсов и ломает поведение `WaitGroup`.

1. **Отмена по контексту.** Контекст задачи `task.GetContext()` отменяется при
   остановке флоу (`WaitGroup::stop()`, ранний `break`, разрушение `WaitGroup`,
   `destroy`). Выполняйте работу на этом контексте; для долгих операций слушайте
   `ctx.Done()` через `select`, иначе задачу нельзя остановить. Для стриминга
   освобождайте ресурс на **свежем** контексте (`context.Background()` + таймаут):
   к моменту очистки контекст задачи уже отменён.

2. **Передача предельного времени выполнения.** Payload, отправляемый из PHP,
   обязан нести предельное время, а Go-сторона обязана ограничить им операцию —
   задача не должна выполняться бесконечно. Как именно оно применяется, зависит от
   фичи: иногда время и есть суть операции (`Sleeper`); иногда таймаут применяется
   нативно (MongoDB передаёт `Client::$timeoutMs` и `::$serverSelectionTimeoutMs` в
   `options.Client().SetTimeout(...).SetServerSelectionTimeout(...)`); общий способ
   — ограничить контекст задачи:
   `ctx, cancel := context.WithTimeout(task.GetContext(), timeout)`.

   (`ExecutionMs` в результате — это фактическое время работы, которое ставит
   `dto.NewSuccessResult`, а не таймаут.)

## Method и payload'ы

Домен — это значение, продублированное в двух местах, и оба должны совпадать: PHP
`SConcur\Features\MethodEnum` и Go `ext/internal/types/method.go` (`Method`).

Payload — контракт обмена, разложенный зеркально с обеих сторон: PHP
`src/Features/<Feature>/Payloads/` (по классу на payload), Go
`ext/internal/features/<feature>/payloads/payloads.go` (все типы в одном файле, в
каталоге, названном как PHP-домен). У каждого PHP `*Payload` есть Go-структура с
тем же именем; поля структуры — это ключи, которые возвращает `getData()`, а теги
`msgpack` (и `json`) равны этим коротким ключам — Go декодирует именно по тегам.
Перекрёстные ссылки обязательны в обе стороны.

```go
// SleeperPayload is the payload of a sleep command.
// PHP: SConcur\Features\Sleeper\Payloads\SleeperPayload.
type SleeperPayload struct {
    Microseconds int64 `json:"us" msgpack:"us"`
}
```

Многокомандные фичи (образец — `Mongodb`) используют двухуровневый payload: общий
конверт с полем команды и `dt` (сериализованное тело) — на Go это один тип
`Payload`, на PHP его строит `Base\BaseMongodbPayload` — плюс по структуре на
команду для содержимого `dt`. Там PHP-классы `*PayloadParameters` — удобство только
для PHP при сборке `dt`, на Go они не переносятся: их поля разворачиваются прямо в
соответствующую Go-структуру. Если `dt` команды — произвольный пользовательский
документ (insert, count, runCommand, …) или пуст (drop, list…), Go-структуры у неё
нет: `dt` читается в обработчике как сырой BSON, и такой случай помечается
комментарием, чтобы каждому PHP `*Payload` соответствовала либо Go-структура, либо
явная пометка.

Фича, у которой много команд, а параметры каждой — плоская карта коротких ключей, может
обойтись вовсе без класса на команду: у `Amqp` один
`AmqpPayload(AmqpCommandEnum $command, array $data)`, вызывающие пишут ключи там же, где
значения, а Go-структуру, в которую разбирается `dt`, называет докблок кейса перечисления.
Два десятка почти одинаковых классов не дают ничего сверх именованного аргумента на месте
вызова. Выбирайте эту форму, когда в параметрах нет логики, и форму Mongodb — когда есть.

PHP-payload — `readonly`, поля типизированы, имена не сокращаются.

## Вариант A. Без стриминга (один результат)

PHP:

1. `MethodEnum` — новый case (строковое значение из 2-3 букв должно быть свободным
   и узнаваемым): `case Foo = 'foo';`

2. Класс payload'а, реализующий `PayloadInterface`. `getMethod()` возвращает новый
   `Method`, `getData()` — параметры, сериализуемые в MessagePack:

   ```php
   /**
    * Go: payloads.FooPayload (ext/internal/features/foo/payloads/payloads.go).
    */
   readonly class FooPayload implements PayloadInterface
   {
       public function __construct(
           protected int $someParam,
           protected int $timeoutMs, // обязательное предельное время выполнения
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
   $taskResult = FeatureExecutor::exec(
       payload: new FooPayload(someParam: $someParam, timeoutMs: $timeoutMs),
   );
   ```

Go:

1. `types/method.go` — та же константа: `MethodFoo Method = "foo"`.

2. Пакет фичи `ext/internal/features/foo/feature.go`, реализующий
   `contracts.FeatureContract` (`Handle(task *tasks.Task)`): распарсить
   `message.Payload`, выполнить работу на `task.GetContext()`, вернуть результат с
   `ExecutionMs`.

   ```go
   func (f *FooFeature) Handle(task *tasks.Task) {
       start := time.Now()
       message := task.GetMessage()

       var payload payloads.FooPayload

       if err := msgpack.Unmarshal(message.Payload, &payload); err != nil {
           task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse error", err)))
           return
       }

       // Ограничиваем работу переданным таймаутом; этот же ctx отменяется при остановке.
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

   Как и у `Sleeper`, фичу обычно делают синглтоном через `sync.Once` + `Get()`.

3. Регистрация в `ext/internal/features/factory.go` — case в
   `DetectMessageHandler`:

   ```go
   case types.MethodFoo:
       return foo_feature.Get(), nil
   ```

## Вариант B. Со стримингом (батчами)

Стриминг отдаёт результат по частям: Go держит состояние, PHP тянет следующие
батчи. Маршрутизация `next` к состоянию общая для всех фич, отдельной настройки не
требует.

PHP: `MethodEnum` и payload — как в варианте A; публичный API возвращает
итератор-результат, обёрнутый вокруг payload'а, — он сам запросит первый и
последующие батчи. (`IteratorResult` ниже — это
`Features\Mongodb\Results\IteratorResult` из Mongodb, показанный как образец: он
декодирует батчи сериализатором BSON, поэтому новая фича пишет свой эквивалент под
свой формат payload'а.)

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

Go: константа как в A, плюс файл состояния в пакете фичи (`rows_state.go` в `sql`,
`message_state.go` в `wsserver`; у mongodb они лежат в `states/`), реализующий
`contracts.StateContract` (`Next() *dto.Result`, `Close()`):

```go
type FooState struct {
    // мьютекс сериализует Next и Close: Close может прийти по отмене контекста,
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

    // на первом вызове лениво инициализируем ресурс на s.ctx, читаем батч

    // есть ещё данные → батч с флагом «будет продолжение»:
    return dto.NewSuccessResultWithNext(s.message, response, helpers.CalcExecutionMs(s.startTime))
    // последний батч → без флага (состояние удаляется, вызывается Close())
}

// Close освобождает ресурс на СВЕЖЕМ контексте: контекст задачи уже отменён.
func (s *FooState) Close() {
    s.mutex.Lock()
    defer s.mutex.Unlock()

    closeCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
    defer cancel()

    // освобождаем удерживаемый ресурс на closeCtx
}
```

`Handle` фичи создаёт состояние и запускает его через реестр; `states.Get().Start`
сам вешает `Close()` на отмену контекста и возвращает первый батч:

```go
state := newFooState(task.GetContext(), message /*, параметры */)

result, err := states.Get().Start(task.GetContext(), message.TaskKey, state)
if err != nil {
    task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("foo", err)))
    return
}

task.AddResult(result)
```

Недочитанный поток (ранний `break` на PHP) закрывается автоматически: PHP
освобождает флоу, контекст задачи отменяется, и хук реестра состояний вызывает
`Close()` — поэтому `Close()` обязан работать на свежем контексте.

## Тесты (обязательно)

- Один тест на фичу; если у фичи есть под-операции — тест на каждую.
- Все тесты наследуются от `BaseTestCase` (напрямую или через
  `BaseAsyncTestCase`). `BaseTestCase` управляет жизненным циклом расширения и в
  `tearDown` проверяет, что не осталось висящих задач — это ловит утечки и забытую
  отмену по контексту.
- Тест фичи пишется от родителя `BaseAsyncTestCase`, который задаёт async-паттерн:
  две конкурентные задачи через `WaitGroup`, проверка порядка событий,
  конкурентности и пути с исключением. Реализуйте его хуки
  (`on_1_start`/`on_1_middle`, `on_2_start`/`on_2_middle`, `on_iterate`,
  `on_exception`, `assertException`, `assertResult`) — в `assertResult` заодно
  проверяется конкурентность, то есть что общее время ≈ самой медленной операции, а
  не их сумме. Образец — `tests/feature/Features/Sleeper/SleeperTest.php`.
- Edge- и синхронные проверки добавляйте отдельными тестами от `BaseTestCase`, а
  Go-логику покрывайте Go-тестами (`make ext-test`).

## Чек-лист

PHP:

- [ ] `MethodEnum` — новое значение.
- [ ] Класс payload'а (`PayloadInterface`) в `src/Features/<Feature>/Payloads/`;
      сборка параметров внутри него; payload несёт предельное время выполнения;
      докблок с перекрёстной ссылкой `Go: payloads.<Type>`.
- [ ] Публичный API (для стриминга — возвращает `IteratorResult`).
- [ ] Тест от `BaseAsyncTestCase` плюс edge-тесты от `BaseTestCase`.

Go:

- [ ] Та же константа в `types/method.go`.
- [ ] Структуры payload'ов в `payloads.go`, зеркальные PHP `*Payload` 1:1 (имена,
      теги `msgpack`) плюс перекрёстная ссылка `// PHP: …`.
- [ ] Пакет фичи с `Handle`: контекст задачи в каждый вызов; работа ограничена
      переданным таймаутом; для стриминга — состояние `StateContract` плюс
      `Close()` на свежем контексте.
- [ ] Регистрация в `features/factory.go`.
- [ ] (опц.) Go-тесты.

Проверка:
`make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test`.
