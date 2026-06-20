# Как добавить новый сервер

**Сервер** — это особый вид фичи: долгоживущий сетевой слушатель, который живёт в
Go-расширении, **принимает** входящие соединения и **стримит каждое событие в PHP**, а
PHP обрабатывает его в отдельной корутине и отправляет ответ обратно. Это «инверсия»
обычной фичи: не PHP вызывает Go и ждёт результат, а Go отдаёт PHP поток входящих
запросов.

Эталон для копирования — **`HttpServer`**: PHP в `src/Features/HttpServer/`, Go в
`ext/internal/features/httpserver/`. Эта дока описывает паттерн в общем виде; за полной
реализацией всегда смотрите на `HttpServer`.

Перед чтением освойте [как добавить обычную фичу](adding-a-feature.ru.md) — сервер
переиспользует её механику (`Method`, payloads, реестр состояний/стриминг, `next()`) и
добавляет поверх неё сетевой слой и цикл обслуживания.

См. также: [HTTP-сервер](http-server.ru.md) (пользовательская дока эталона) и
[Мастер воркеров](worker-master.ru.md) (как сервер масштабируется на ядра и
супервизируется).

---

## Модель: два `Method` на один сервер

Сервер — это **пара методов**, оба обслуживает одна Go-фича (через `switch` по
`Method`):

- **`<Server>Serve`** — открыть слушатель и **стримить** принятые запросы в PHP
  (стриминговое состояние: каждый запрос — очередной «батч», который PHP тянет через
  `next()`).
- **`<Server>Respond`** — доставить **одну запись ответа** (целиком, либо
  head/chunk/end стрима) от PHP-обработчика обратно в висящее соединение.

Поток данных одного запроса:

```
клиент → Go listener → ServeHTTP-горутина ─(RequestEvent)→ requests-канал
   → Next() отдаёт батч в PHP → Scheduler::serve спавнит корутину
   → handler(Request): Response → RespondPayload (Respond-метод)
   → handleRespond находит висящее соединение по requestId → пишет в сокет
```

Эталон: `MethodHttpServe` (3) + `MethodHttpRespond` (4), оба → `httpserver_feature`.

---

## ⚠️ Обязательные требования

Помимо двух общих требований к фиче (отмена контекста и предельное время выполнения —
см. [adding-a-feature.ru.md](adding-a-feature.ru.md)), у сервера есть свои:

1. **Контекст серверного состояния = жизнь сервера.** Контекст задачи `Serve`
   (`task.GetContext()`) пробрасывается в `http.Server.BaseContext`, поэтому отмена
   потока/`stopFlow` обрывает и слушатель, и все висящие соединения. Никакой запрос не
   должен переживать остановку сервера.

2. **Лимит на запрос, а не только на сервер.** Каждый обработчик ограничивается
   `handlerTimeoutMs` на Go-стороне (таймер в отдельной горутине, срабатывает
   **независимо от PHP** — см. [«Таймаут хендлера» в HTTP-сервере](http-server.ru.md)).
   До первой записи → клиент получает `504`; после начала стрима → ответ обрывается.

3. **Graceful-дренаж и осиротевшие воркеры.** Сервер обязан уметь: остановить **приём**
   новых соединений, не трогая in-flight (для бесшовного хендовера соседям по
   `SO_REUSEPORT`), и самозавершаться, если его мастер умер (`--masterPid`). См. ниже.

---

## Соответствие `Method` (PHP ↔ Go)

Два новых значения, **оба** дублируются с обеих сторон:

- PHP: `SConcur\Features\MethodEnum` — `case <Server>Serve = N;` и `case <Server>Respond = N+1;`
- Go: `ext/internal/types/method.go` — `Method<Server>Serve` и `Method<Server>Respond`.

Регистрация в `ext/internal/features/factory.go` — **один** кейс на оба метода:

```go
case types.MethodHttpServe, types.MethodHttpRespond:
    return httpserver_feature.Get(), nil
```

---

## Payloads (PHP ↔ Go)

Оформляются как у обычной фичи (зеркально, `msgpack`-теги = короткие ключи,
кросс-ссылки — см. раздел «Оформление payloads» в
[adding-a-feature.ru.md](adding-a-feature.ru.md)). Серверу нужны минимум три:

1. **`ServePayload`** — адрес слушателя + тюнинг (таймауты в мс, лимиты в байтах,
   `reusePort`). Эталон: `src/Features/HttpServer/Payloads/ServePayload.php` ↔
   `payloads.ServePayload`.

2. **`RespondPayload`** — одна запись ответа. Поле `op` выбирает вид записи; у
   `HttpServer` это `OP_FULL`(0) / `OP_HEAD`(1) / `OP_CHUNK`(2) / `OP_END`(3) — фабрики
   `RespondPayload::full()/head()/chunk()/end()`. Заголовки нормализуются в
   `array<string, list<string>>` (мульти-значные). Эталон:
   `src/Features/HttpServer/Payloads/RespondPayload.php` ↔ `payloads.RespondPayload`.

3. **`RequestEvent`** — то, что Go стримит в PHP на каждый запрос (Go-only структура;
   PHP декодирует её в свой DTO `Request`). Несёт `requestId`, метод/путь/заголовки и
   **инлайн первый чанк тела** + ключ стриминга остального тела (`BodyKey`, см.
   «Стриминг тела» ниже). Эталон: `payloads.RequestEvent` (Go) ↔
   `SConcur\Features\HttpServer\Dto\Request`.

> `requestId` — сквозной идентификатор: Go генерит его на приёме (`flowKey:r:<n>`),
> кладёт в `RequestEvent`, PHP возвращает его в каждом `RespondPayload`, и Go по нему
> находит висящее соединение. Делайте его уникальным в пределах flow.

---

## PHP-сторона

### DTO

- **`Request`** (`Dto/Request.php`) — `fromPayload(string $payload)` декодит
  `RequestEvent`. Тело — отдельный объект (`RequestBody`) с ленивым дочитыванием
  остатка через `next()`.
- **`Response`** / **`StreamedResponse`** + **`ResponseStream`** — что возвращает
  обработчик. `Response` — один атомарный ответ; `StreamedResponse` — замыкание-писатель,
  которому передаётся `ResponseStream`; каждый `->write($chunk)` шлёт `OP_CHUNK` и ждёт
  флаша (backpressure). DTO — `readonly`.

### `fromArgs()` (для мастера воркеров)

Чтобы сервер запускался под `bin/sconcur-server`, сделайте статический конструктор из
`argv` — по образцу `HttpServer::fromArgs()` (`HttpServer.php`): рефлексией собрать
скалярные параметры конструктора, для каждого `--имя=значение` привести строку к типу
(int/bool/float/string) и бросить на неизвестный аргумент. Мастер прокидывает
`--masterPid` именно сюда (см. «Интеграция с мастером»).

### Цикл обслуживания: `serve()`

Публичный `serve(Closure $handler)` (`HttpServer::serve`, `HttpServer.php:193`):

1. Сгенерировать `flowKey`, установить обработчики сигналов (SIGTERM/SIGINT → флаг
   `stopRequested`), восстановить их в `finally`.
2. **Запустить слушатель**: `Extension::get()->push($flowKey, new ServePayload(...))` —
   это стриминговая задача (как курсор), её первый и последующие батчи — входящие
   запросы.
3. Отдать управление **общему** примитиву `Scheduler::get()->serve(...)`
   (`Scheduler.php:211`), передав:
   - `serverFlowKey` / `serverTaskKey` — ключи стрима-слушателя;
   - `maxRequests` — штатно завершиться после N запросов (мера против утечек памяти);
   - **`onRequest(string $payload)`** — спавн-на-запрос: декодить `Request`, вызвать
     `handler`, отправить ответ (`RespondPayload::full(...)` или
     head→chunk*→end для стрима). У эталона это `HttpServer::handle()` (`HttpServer.php:324`);
   - **`shouldStop(): bool`** — `true`, когда пришёл сигнал **или** воркер осиротел
     (orphan-чек ниже);
   - **`onDrainStart()`** — вызывается один раз при начале дренажа: рано закрыть приём,
     `Extension::get()->httpStopAccepting($flowKey)`, чтобы новые соединения ушли
     соседям по `SO_REUSEPORT`.

`Scheduler::serve` сам мультиплексирует входящие запросы и async-работу их обработчиков
в одном `waitAny`-цикле, переармливает стрим через `next()` и на дренаже корректно
гасит поток (`stopFlow`). **Эту механику переписывать не нужно — она общая.**

### Сигналы и самозавершение осиротевших воркеров

- **Сигналы**: `installSignalHandlers()` ставит SIGTERM/SIGINT → `stopRequested = true`
  (через `pcntl_async_signals`), и `shouldStop()` это видит. Прежние обработчики
  восстанавливаются в `finally`.
- **Orphan-чек**: если в конструктор передан `masterPid`, `shouldStop()` дополнительно
  проверяет `posix_getppid() !== $masterPid` — после смерти мастера ядро меняет
  родителя, и воркер сам уходит в graceful-дренаж (без подверженности PID-reuse). См.
  `HttpServer::isOrphaned()` (`HttpServer.php:301`).

---

## Go-сторона

### Фича: `Handle` → `handleServe` / `handleRespond`

`ext/internal/features/<server>/feature.go`, реализует `contracts.FeatureContract`.
`Handle` диспатчит по `Method` (эталон — `feature.go:54`):

```go
func (f *Feature) Handle(task *tasks.Task) {
    switch task.GetMessage().Method {
    case types.MethodHttpServe:   f.handleServe(task)
    case types.MethodHttpRespond: f.handleRespond(task)
    default:                      /* unknown method error */
    }
}
```

Глобальные карты (синглтон-фича):
- `pendingRequests sync.Map` — `requestId → *pendingRequest` (канал команд записи).
  Глобальная, чтобы `Respond` (приходит на **другом** flow) нашёл соединение.
- `serverStates sync.Map` — `flowKey → *serverState`, чтобы `StopAccepting` нашёл
  слушатель.

### `handleServe`: слушатель как стриминговое состояние

(`feature.go:69`)

1. Разобрать `ServePayload`.
2. `listener, err := listen(payload.Address, payload.ReusePort)` — TCP-слушатель;
   `reusePort` ставит `SO_REUSEPORT` на сокет (`listen.go`).
3. `state := newServerState(task.GetContext(), message, listener, ...)` — состояние,
   реализующее `contracts.StateContract`. Внутри поднимается стандартный
   `net/http.Server` (keep-alive, таймауты, парсинг), у которого `serverState` —
   `http.Handler`; `BaseContext` привязан к `task.GetContext()`.
4. `serverStates.Store(message.FlowKey, state)` — для раннего закрытия приёма.
5. `states.Get().Start(task.GetContext(), message.TaskKey, state)` — регистрирует
   состояние (как у курсора), сам повесит `Close()` на отмену контекста и вернёт первый
   батч.

`serverState` (`server.go`):
- `ServeHTTP(w, r)` (горутина соединения): сложить `RequestEvent` в буферизованный
  `requests`-канал, **захватить семафор `maxConcurrency`** (до чтения тела), завести
  `pendingRequest` в `pendingRequests`, и **ждать** команд записи от PHP, применяя их к
  сокету. На `handlerTimeout`/обрыве — закрыть `abandoned`, чтобы поздний ответ не висел
  вечно. Деферром пишется access-лог (на Go-стороне, без PHP↔Go на запрос).
- `Next() *dto.Result` — отдать следующий `RequestEvent` из канала как батч
  `dto.NewSuccessResultWithNext(...)` (флаг «будет ещё»); по `ctx.Done()` — финальный
  батч без флага (PHP-цикл выйдет).
- `Close()` — остановить `http.Server`, снять `serverStates`, освободить ресурсы (на
  свежем контексте — контекст задачи уже отменён).

### `handleRespond`: маршрут ответа в соединение

(`feature.go:112`) Декодить `requestId` (отдельной мини-структурой, чтобы маршрутизация
работала даже при битом остальном payload), найти `pendingRequest` в `pendingRequests`,
и `dispatch()` команду записи (`writeFull`/`writeHead`/`writeChunk`/`writeEnd`).
`dispatch` ждёт применения команды (write-backpressure): корутина-обработчик
продолжается, только когда байты ушли в сокет, либо приходит `abandoned`/отмена
контекста.

### Раннее закрытие приёма + `SO_REUSEPORT`

`StopAccepting(flowKey)` (`feature.go:215`) находит `serverState` и закрывает **только
слушатель** (`http.Server.Shutdown` в фоне), не отменяя in-flight. На пуле
`SO_REUSEPORT` ядро тут же раздаёт новые соединения соседям, пока этот процесс
дренажит. Это вызывается из PHP-`onDrainStart`.

### Стриминг тела запроса

Если тело больше инлайнового первого чанка — Go кладёт остаток в **отдельное
стриминговое состояние** и отдаёт его ключ в `RequestEvent.BodyKey`; PHP дочитывает
куски через тот же общий `next()`-механизм (как курсор Mongo). Эталон —
`body_state.go` и `RequestBody`. Гранулярность транспортного чанка фиксирована
(64 KiB).

---

## cgo-экспорт `StopAccepting` (единственный «серверный» экспорт)

Общие экспорты (`push`, `next`, `stopFlow`, `waitAnyTimeout`, `waitAny`) сервер
переиспользует. Дополнительно ему нужен **свой** экспорт раннего закрытия приёма —
у `serverStates` каждого сервера своя карта, поэтому `httpStopAccepting` чужой сервер
переиспользовать не может. Заведите `<server>StopAccepting` по той же цепочке, что и
`httpStopAccepting`:

- `ext/main.go` — `//export <server>StopAccepting` → `<server>_feature.StopAccepting(...)`;
- `ext/sconcur.c` — `PHP_FUNCTION`, `arginfo`, регистрация `ZEND_NS_FE` и строка в шапке;
- `ext/sconcur.stub.php` — объявление функции;
- `src/Connection/Extension.php` — `use function` + PHP-обёртка.

Это **протокольное изменение** — действует правило версии расширения (бамп не чаще раза
на ветку, см. раздел «Extension versioning» в [.ai/README.md](../.ai/README.md)).

> Минимальный сервер может обойтись без `StopAccepting` и гасить всё через `stopFlow`,
> но тогда теряется бесшовный дренаж/хендовер `SO_REUSEPORT` — для продакшн-сервера под
> мастером он нужен.

---

## Интеграция с мастером воркеров

Сервер становится «server-agnostic»-воркером для `bin/sconcur-server` бесплатно, если
соблюдает контракт:

- воркер-скрипт строит сервер из argv и обслуживает:
  ```php
  $server = MyServer::fromArgs($_SERVER['argv']);
  $server->serve(static fn (Request $request) => new Response(body: 'ok'));
  ```
- параметры из блока `server` JSON-конфига мастер разворачивает в `--ключ=значение`
  argv (`fromArgs` их разбирает), а свой pid прокидывает флагом `--masterPid` (orphan-чек);
- `reusePort: true` в конфиге включает `SO_REUSEPORT` — мастер поднимает пул процессов,
  ядро балансирует. Команда `reload` делает rolling-перезапуск без простоя.

Подробности контракта и параметры — в [Мастер воркеров](worker-master.ru.md)
(разделы «Поддерживаемые серверы» и «Параметры»).

---

## Тесты (обязательно)

- **Поднимайте реальный процесс сервера** на loopback и бейте по нему `curl`'ом —
  эталон инфраструктуры: `tests/impl/HttpServer/TestHttpServer.php` (spawn через
  `proc_open`, свободный порт, чтение access-лога) и
  `tests/feature/Features/HttpServer/BaseHttpServerTestCase.php` (свой сервер на класс,
  `serverOptions()`, `request()`, `concurrentGet()`).
- Покрывайте: базовый запрос/ответ, стриминг, `maxConcurrency`, `handlerTimeoutMs`
  (включая нативно-блокирующий обработчик), graceful shutdown, `SO_REUSEPORT` (два
  сервера на одном порту), `maxRequests`, orphan-самозавершение (`--masterPid`).
  Примеры — соседние `HttpServer*Test.php`.
- Go-логику слушателя/состояния покрывайте Go-тестами (`make ext-test`); эталон —
  `ext/internal/features/httpserver/server_test.go`.
- e2e под мастером — `tests/feature/Worker/WorkerMasterTest.php`.

---

## Чеклист

PHP:
- [ ] `MethodEnum` — **два** значения (`<Server>Serve`, `<Server>Respond`).
- [ ] Payloads: `ServePayload`, `RespondPayload` (+ кросс-ссылки `Go: payloads.<Type>`).
- [ ] DTO: `Request` (`fromPayload`), `Response`/`StreamedResponse`/`ResponseStream`.
- [ ] `fromArgs()` (рефлексия argv) — для мастера; принимает `--masterPid`.
- [ ] `serve()`: запуск слушателя через `push(ServePayload)` + `Scheduler::serve(...)`
      с `onRequest`/`shouldStop`/`onDrainStart`; обработка сигналов + orphan-чек.
- [ ] Тесты от `BaseHttpServerTestCase`-аналога (реальный процесс + `curl`).

Go:
- [ ] Те же **две** константы в `types/method.go`.
- [ ] Payload-структуры в `payloads.go` + `RequestEvent`; зеркалят PHP 1:1.
- [ ] Фича: `Handle`-switch → `handleServe` (listen → `serverState`/`StateContract` →
      `states.Get().Start`) и `handleRespond` (rendezvous по `requestId` + write-backpressure).
- [ ] `serverStates`/`pendingRequests`-карты; `StopAccepting(flowKey)`; `SO_REUSEPORT` в `listen`.
- [ ] `BaseContext` = контекст задачи; `handlerTimeout`; access-лог на Go-стороне.
- [ ] Регистрация в `features/factory.go` (один кейс на оба метода).

cgo / протокол:
- [ ] `<server>StopAccepting` по цепочке `main.go` → `sconcur.c` → `sconcur.stub.php` →
      `Extension.php`; учесть версию расширения (бамп раз на ветку).

Проверка: `make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test`.
