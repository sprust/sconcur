[English](adding-a-feature.md) | Русский

# Как добавить новую фичу верхнего уровня

Фича верхнего уровня — это новый домен со своим `Method` (как `Sleeper`). Образец
для копирования — `Sleeper`: PHP в `src/Features/Sleeper/`, Rust в
`ext/src/features/sleeper/`. Ниже разбор в двух вариантах — без стриминга
(один результат) и со стримингом (несколько батчей).

> Делаете долгоживущий сетевой сервер (как `HttpServer`)? Это особый вид
> стриминговой фичи со своим листенером и циклом обслуживания — см.
> [Как добавить новый сервер](adding-a-server.ru.md).

## Два обязательных требования

Каждый обработчик на стороне расширения обязан выполнять оба; их нарушение приводит к
утечкам ресурсов и ломает поведение `WaitGroup`.

1. **Отмена по контексту.** Контекст задачи `task.GetContext()` отменяется при
   остановке флоу (`WaitGroup::stop()`, ранний `break`, разрушение `WaitGroup`,
   `destroy`). Выполняйте работу на этом контексте; для долгих операций слушайте
   `ctx.Done()` через `select`, иначе задачу нельзя остановить. Для стриминга
   освобождайте ресурс на **свежем** контексте (`context.Background()` + таймаут):
   к моменту очистки контекст задачи уже отменён.

2. **Передача предельного времени выполнения.** Payload, отправляемый из PHP,
   обязан нести предельное время, а Расширение обязана ограничить им операцию —
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
`SConcur\Features\MethodEnum` и Rust `ext/src/types/method.rs` (`Method`).

Payload — контракт обмена, разложенный зеркально с обеих сторон: PHP
`src/Features/<Feature>/Payloads/` (по классу на payload), Rust
`ext-go-legacy/internal/features/<feature>/payloads/payloads.go` (все типы в одном файле, в
модуле, названном как PHP-домен). У каждого PHP `*Payload` есть Rust-структура с
тем же именем; поля структуры — это ключи, которые возвращает `getData()`, а теги
`serde(rename)` равны этим коротким ключам — структура декодируется именно по ним.
Перекрёстные ссылки обязательны в обе стороны.

```rust
/// The payload of a sleep command.
/// PHP: SConcur\Features\Sleeper\Payloads\SleeperPayload.
#[derive(Deserialize)]
pub struct SleeperPayload {
    #[serde(rename = "us")]
    pub microseconds: i64,
}
```

Многокомандные фичи (образец — `Mongodb`) используют двухуровневый payload: общий
конверт с полем команды и `dt` (сериализованное тело) — в Rust это один тип
`Payload`, на PHP его строит `Base\BaseMongodbPayload` — плюс по структуре на
команду для содержимого `dt`. Там PHP-классы `*PayloadParameters` — удобство только
для PHP при сборке `dt`, в Rust они не переносятся: их поля разворачиваются прямо
в соответствующую Rust-структуру. Если `dt` команды — произвольный
пользовательский документ (insert, count, runCommand, …) или пуст (drop, list…), структуры у неё
нет: `dt` читается в обработчике как сырой BSON, и такой случай помечается
комментарием, чтобы каждому PHP `*Payload` соответствовала либо структура, либо
явная пометка.

Фича, у которой много команд, а параметры каждой — плоская карта коротких ключей, может
обойтись вовсе без класса на команду: у `Amqp` один
`AmqpPayload(AmqpCommandEnum $command, array $data)`, вызывающие пишут ключи там же, где
значения, а структуру, в которую разбирается `dt`, называет докблок кейса перечисления.
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
    * Rust: payloads::FooPayload (ext/src/features/foo/payloads.rs).
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

Rust:

1. `ext/src/types/method.rs` — та же константа: вариант `Foo` со значением `"foo"`
   на проводе.

2. Модуль фичи `ext/src/features/foo/mod.rs`, реализующий `Feature`
   (`handle(&self, task: Task) -> BoxFuture`): раскодировать `message.payload`,
   сделать работу под `task.context()` и ответить результатом.

   ```rust
   impl Feature for FooFeature {
       fn handle(&self, task: Task) -> BoxFuture {
           Box::pin(async move {
               let start_time = Instant::now();
               let message = task.message();

               let payload: payloads::FooPayload = match rmp_serde::from_slice(&message.payload) {
                   Ok(payload) => payload,
                   Err(error) => {
                       task.add_result(Result::error(
                           message,
                           ERR_FACTORY.by_err("parse error", error),
                       )).await;

                       return;
                   }
               };

               // Токен флоу отменяет это на остановке, таймаут — ограничивает.
               tokio::select! {
                   _ = task.context().cancelled() => {
                       task.add_result(Result::error(
                           message,
                           ERR_FACTORY.by_text("closed by task stop"),
                       )).await;
                   }
                   outcome = do_foo(&payload) => match outcome {
                       Ok(body) => {
                           task.add_result(Result::success(
                               message,
                               body,
                               calc_execution_ms(start_time),
                           )).await;
                       }
                       Err(error) => {
                           task.add_result(Result::error(
                               message,
                               ERR_FACTORY.by_err("foo error", error),
                           )).await;
                       }
                   },
               }
           })
       }
   }
   ```

   Как и у `Sleeper`, фича — синглтон через `OnceLock` + `get()`.

3. Регистрация в `ext/src/features/mod.rs` — ветка в `detect_message_handler`:

   ```rust
   Method::Foo => Ok(foo::get()),
   ```

## Вариант B. Со стримингом (батчами)

Стриминг отдаёт результат по частям: расширение держит состояние, PHP тянет следующие
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

Rust: константа как в A, плюс модуль состояния в фиче (`rows_state.rs` в `sql`,
`message_state.rs` в `wsserver`; у mongodb они лежат в `states.rs`), реализующий
`StateContract` (`next()`, `close()`, оба асинхронные):

```rust
pub struct FooState {
    // Мьютекс, потому что close() может прийти по отмене, пока next() ещё
    // использует ресурс.
    resource: tokio::sync::Mutex<Option<Resource>>,
    message: Arc<Message>,
    start_time: Instant,
}

impl StateContract for FooState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            let mut resource = self.resource.lock().await;

            // На первом вызове открываем ресурс, дальше читаем один батч.

            // Есть ещё данные → батч, который об этом говорит:
            Result::success_with_next(&self.message, body, calc_execution_ms(self.start_time))
            // Последний батч → без флага, и реестр закрывает состояние.
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            // Освобождаем ресурс. Этого дожидаются, поэтому брошенный курсор
            // MongoDB доезжает до сервера раньше, чем PHP посмотрит на счётчик
            // открытых курсоров.
        })
    }
}
```

`handle` фичи создаёт состояние и запускает его через реестр; `states::get().start()`
сам вешает `close()` на завершение флоу и возвращает первый батч:

```rust
let state = Arc::new(FooState::new(task.message_arc() /*, параметры */));

match states::get().start(task.context().clone(), &message.task_key, state).await {
    Ok(result) => task.add_result(result).await,
    Err(error) => {
        task.add_result(Result::error(message, ERR_FACTORY.by_text(&error))).await;
    }
}
```

Недочитанный поток (ранний `break` на PHP) закрывается автоматически: PHP
освобождает флоу, срабатывает его токен отмены, и хук реестра состояний вызывает
`close()` — поэтому `close()` не должен зависеть от того, жив ли ещё флоу.

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
  логику расширения покрывайте юнит-тестами (`make ext-test`).

## Чек-лист

PHP:

- [ ] `MethodEnum` — новое значение.
- [ ] Класс payload'а (`PayloadInterface`) в `src/Features/<Feature>/Payloads/`;
      сборка параметров внутри него; payload несёт предельное время выполнения;
      докблок с перекрёстной ссылкой `Rust: payloads::<Type>`.
- [ ] Публичный API (для стриминга — возвращает `IteratorResult`).
- [ ] Тест от `BaseAsyncTestCase` плюс edge-тесты от `BaseTestCase`.

Rust:

- [ ] Та же константа в `ext/src/types/method.rs`.
- [ ] Структуры payload'ов в `payloads.rs`, зеркальные PHP `*Payload` 1:1 (имена,
      ключи `serde(rename)`) плюс перекрёстная ссылка `// PHP: …`.
- [ ] Модуль фичи с `handle`: токен отмены задачи учитывается каждым вызовом;
      работа ограничена переданным таймаутом; для стриминга — состояние
      `StateContract`, чей `close()` дожидаются.
- [ ] Регистрация в `ext/src/features/mod.rs`.
- [ ] (опц.) юнит-тесты в расширении.

Проверка:
`make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test`.
