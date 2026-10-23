[English](adding-a-feature.md) | Русский

# Как добавить новую фичу верхнего уровня

Фича — это PHP-API в `src/Features/<Feature>/` плюс Rust-модуль в
`ext/src/features/<feature>/`, который выполняет её задачи. Фича верхнего уровня —
это новый домен со своим `Method` (как `Sleeper`). Образец для копирования —
`Sleeper`: PHP в `src/Features/Sleeper/`, Rust в `ext/src/features/sleeper/`. Ниже
разбор в двух вариантах — без стриминга (один результат) и со стримингом
(несколько батчей).

> Делаете долгоживущий сетевой сервер (как `HttpServer`)? Это особый вид
> потоковой фичи со своим слушателем и циклом обслуживания — см.
> [Как добавить новый сервер](adding-a-server.ru.md).

## Два обязательных требования

Каждый обработчик на стороне расширения обязан выполнять оба; их нарушение приводит к
утечкам ресурсов и ломает поведение `WaitGroup`.

1. Отмена. Токен отмены задачи, `task.context()`, отменяется при остановке её
   флоу — группы задач, которой `WaitGroup` владеет в расширении
   (`WaitGroup::stop()`, ранний `break`, разрушение `WaitGroup` — оно ждёт работающих
   членов, они его и держат, — `destroy`).
   Для долгой операции ставьте рядом с ней в `select!` ветку
   `_ = task.context().cancelled()`, иначе задачу нельзя остановить. Очистка
   после стрима под этим токеном идти не может — к её началу он уже отменён,
   поэтому у освобождения ресурса должен быть свой предел.

2. Предельное время выполнения. Payload, отправляемый из PHP,
   обязан нести предельное время, а расширение обязано ограничить им операцию —
   задача не должна выполняться бесконечно. Как именно оно применяется, зависит от
   фичи: иногда время и есть суть операции (`Sleeper`); иногда часть его — опция
   драйвера (MongoDB передаёт `serverSelectionTimeoutMs` драйверу как таймаут
   выбора сервера); общий способ, которым MongoDB и SQL ограничивают сам вызов, —
   `tokio::time::timeout(Duration::from_millis(timeout_ms), work)`.

   (`executionMs` в результате — это фактическое время работы, которое меряет
   `calc_execution_ms`, а не таймаут.)

## Method и payload'ы

Домен — это значение, продублированное в двух местах, и оба должны совпадать: PHP
`SConcur\Features\MethodEnum` и Rust `ext/src/types/method.rs` (`Method`).

Payload — контракт обмена, разложенный зеркально с обеих сторон: PHP
`src/Features/<Feature>/Payloads/` (по классу на payload), Rust
`ext/src/features/<feature>/payloads.rs` (все типы в одном файле, в
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

`Mongodb` — исключение из правила «структура на payload». Каждая команда — один
конверт (`ul`/`db`/`cl`/`to`/`sst`/`cm`/`dt`), который на PHP строит
`Base\BaseMongodbPayload`; Rust читает его вручную через `decode_envelope()` в
`Envelope` (`ext/src/features/mongodb/payloads.rs`), без serde, потому что в `dt`
лежат вложенные значения, которые переводятся в BSON. `dt` — либо документ
пользователя (insert, count, runCommand, …), либо пусто (drop, list…), либо карта
именованных параметров, которую собирают PHP-классы `*PayloadParameters`;
обработчик в `commands.rs` читает эту карту по коротким ключам через
`decode_params()` и `Params` (`params.filter("f")`). Rust-структуры на команду нет.

Фича, у которой много команд, а параметры каждой — плоская карта коротких
ключей, может обойтись вовсе без класса на команду: у `Amqp` один
`AmqpPayload(AmqpCommandEnum $command, array $data)` (в Rust —
`payloads::Envelope`), вызывающие пишут ключи там же, где значения, а структуру, в
которую разбирается `p`, называет докблок варианта `AmqpCommandEnum`. Выбирайте
эту форму, когда в параметрах нет логики, и класс на команду — когда есть.

PHP-payload — `readonly`, поля типизированы, имена не сокращаются.

## Вариант A. Без стриминга (один результат)

PHP:

1. `MethodEnum` — новый вариант (строковое значение из 2-3 букв должно быть свободным
   и узнаваемым): `case Foo = 'foo';`

2. Класс payload'а, реализующий `PayloadInterface`. `getMethod()` возвращает новый
   `Method`, `getData()` — параметры массивом, который читает ядро:

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

1. `ext/src/types/method.rs` — та же константа: вариант `Method::Foo` и по ветке в
   `from_wire()` (`b"foo"`), `as_wire()` (`"foo"`) и `as_php_case()` (`c"Foo"` —
   имя варианта PHP, по которому построитель результатов в `ext/sconcur.c` находит
   вариант перечисления).

2. `ext/src/features/foo/payloads.rs` — `FooPayload` с полями
   `#[serde(rename = "p")]` и `#[serde(rename = "to")]` и перекрёстной ссылкой
   `/// PHP: …`.

3. Модуль фичи `ext/src/features/foo/mod.rs`, реализующий `Feature`
   (`handle(&self, task: Task) -> BoxFuture`): раскодировать `message.payload`,
   сделать работу под `task.context()` и ответить результатом.

   ```rust
   impl Feature for FooFeature {
       fn handle(&self, task: Task) -> BoxFuture {
           Box::pin(async move {
               let start_time = Instant::now();
               let message = task.message();

               let payload: payloads::FooPayload = match message.payload.decode() {
                   Ok(payload) => payload,
                   Err(error) => {
                       task.add_result(Result::error(
                           message,
                           ERR_FACTORY.by_err("parse error", error),
                       )).await;

                       return;
                   }
               };

               // Токен флоу отменяет это на остановке; do_foo сам ограничивает себя
               // payload.timeout_ms (требование 2).
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

   Сам синглтон ничего не хранит. Всё, что фича держит на всё время жизни
   процесса, — соединения, пулы, открытые дескрипторы, флаг запущенной фоновой
   задачи — лежит в её структуре `Registries`, поле `Core` в `ext/src/core.rs` —
   объявленное в структуре, создаваемое в её конструкторе, с доступом через метод
   (`crate::core::get().sql()`), — и никогда не в `static`.
   После `fork` ребёнок пересобирает ядро и всё, что на нём. `static` передал бы
   ребёнку соединения родителя, флаг задачи, которая крутится в рантайме, которого
   у ребёнка нет, или мьютекс, который в момент форка держал другой поток. Задачу,
   которая стартует при первом использовании, по той же причине включает флаг в
   реестре (`sweeping` в `ext/src/features/sql/pools.rs`). Счётчики, которые только
   нумеруют идентификаторы, могут остаться `static`: ребёнок, продолжающий нумерацию
   родителя, ничего не ломает.

4. Регистрация в `ext/src/features/mod.rs`: `pub mod foo;` среди объявлений
   модулей, ветка в `detect_message_handler`, а если фича держит соединения или
   дескрипторы, — вызов её `shutdown()` внутри `shutdown()`, который выполняет
   `destroy`:

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
проверяет, что батч — это список, который построило ядро, поэтому новая фича пишет
свой эквивалент под формат своих батчей.)

```php
/**
 * @return Iterator<int, mixed>
 */
public function doFoo(int $someParam, int $timeoutMs): Iterator
{
    return new IteratorResult(
        payload: new FooPayload(someParam: $someParam, timeoutMs: $timeoutMs),
    );
}
```

Rust: константа как в A, плюс модуль состояния в фиче (`rows_state.rs` в `sql`,
`ext/src/ws/message_state.rs` у WebSocket-сервера; у mongodb они лежат в
`states.rs`), реализующий `StateContract` (`next()`, `close()`, оба
асинхронные):

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

Хук завершается и вместе с состоянием: поток, дочитанный до конца или удалённый самой
фичей, уносит свой хук с собой. Это важно, потому что флоу может жить столько же,
сколько процесс: у корутины (PHP-файбера, который приостанавливает планировщик) с
бесконечным циклом, например у задачи из пула задач,
один флоу на все потоки, которые она когда-либо откроет. Состояние, которое нельзя
запустить, потому что его первый батч ещё не готов (транзакция, подписка, потоковая
загрузка), регистрируется через `states::get().register_with_flow()` — он вешает хук
так же. Не запускайте свою задачу, которая ждёт `task.context().cancelled()`, чтобы
удалить состояние: до конца флоу её ничто не завершит.

## Тесты (обязательно)

- Один тест на фичу; если у фичи есть под-операции — тест на каждую.
- Все тесты наследуются от `BaseTestCase` (`tests/feature/BaseTestCase.php`,
  напрямую или через `tests/feature/BaseAsyncTestCase.php`). `BaseTestCase`
  управляет жизненным циклом расширения и в `tearDown` проверяет, что не осталось
  висящих задач — это ловит утечки и забытую отмену по контексту.
- Тест фичи пишется от родителя `BaseAsyncTestCase`, который задаёт асинхронный
  шаблон: две конкурентные задачи через `WaitGroup`, проверка порядка событий,
  конкурентности и пути с исключением. Реализуйте его хуки
  (`on_1_start`/`on_1_middle`, `on_2_start`/`on_2_middle`, `on_iterate`,
  `on_exception`, `assertException`, `assertResult`) — в `assertResult` заодно
  проверяется конкурентность, то есть что общее время ≈ самой медленной операции, а
  не их сумме. Образец — `tests/feature/Features/Sleeper/SleeperTest.php`.
- Проверки граничных случаев и синхронные проверки добавляйте отдельными тестами
  от `BaseTestCase`, а логику расширения покрывайте юнит-тестами (`make ext-test`).

## Чек-лист

PHP:

- [ ] `MethodEnum` — новое значение.
- [ ] Класс payload'а (`PayloadInterface`) в `src/Features/<Feature>/Payloads/`;
      сборка параметров внутри него; payload несёт предельное время выполнения;
      докблок с перекрёстной ссылкой `Rust: payloads::<Type>`.
- [ ] Публичный API (для стриминга — возвращает `IteratorResult`).
- [ ] Тест от `BaseAsyncTestCase` плюс тесты граничных случаев от `BaseTestCase`.

Rust:

- [ ] Та же константа в `ext/src/types/method.rs`: вариант и его ветки в
      `from_wire()`, `as_wire()`, `as_php_case()`.
- [ ] Структуры payload'ов в `payloads.rs`, зеркальные PHP `*Payload` 1:1 (имена,
      ключи `serde(rename)`) плюс перекрёстная ссылка `/// PHP: …`.
- [ ] Модуль фичи с `handle`: токен отмены задачи учитывается каждым вызовом;
      работа ограничена переданным таймаутом; для стриминга — состояние
      `StateContract`, чей `close()` дожидаются.
- [ ] Всё, что фича держит на всё время жизни процесса, лежит в её `Registries` на
      `Core`, а не в `static`.
- [ ] Регистрация в `ext/src/features/mod.rs` (`pub mod`, ветка
      `detect_message_handler`, `shutdown()`, если держит ресурсы).
- [ ] (опц.) юнит-тесты в расширении.

Проверка: `make check` (запускает `cs-fixer-check`, `php-stan`, `ext-build`,
`ext-test`, `ext-check` и `test`).
