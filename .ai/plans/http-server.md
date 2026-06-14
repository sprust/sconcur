# HTTP-сервер: каждый запрос в отдельной корутине

Статус: **v1 реализован** (этапы 1–3). Этапы 4–5 — в планах. Краткий пункт —
в [README → Планы](../../README.md#планы). Пользовательская документация (как
пользоваться, параметры, ограничения) — [docs/http-server.ru.md](../../docs/http-server.ru.md).

## Состояние

- **Готово (v1):** spawn-на-запрос работает end-to-end. Go-фича `httpserver`
  (listener как стриминговая задача: каждый запрос = «батч» через `states`/`next`),
  `Scheduler::serve()`/`spawn()`, `HttpServer::serve()`, `Request`/`Response`,
  `ServePayload`/`RespondPayload`, методы `MethodHttpServe`/`MethodHttpRespond`.
  Обработчик ошибок → 500.
- **Готово (этап 4, путь B):** сеть на стандартном `net/http.Server` (serverState —
  его `http.Handler`): **keep-alive**, таймауты (read/write/idle), корректный
  парсинг/запись из коробки. Graceful **на стороне Go** — `Close()` делает
  `http.Server.Shutdown` на свежем контексте (дренаж активных запросов), `BaseContext`
  привязывает запросы к жизни сервера. Проверено e2e (несколько запросов по одному
  соединению).
- **Готово (этап 4, signal-driven graceful):** `HttpServer::serve` ставит
  `pcntl`-обработчики `SIGTERM`/`SIGINT` (`pcntl_async_signals`), которые взводят флаг;
  `Scheduler::serve` по флагу перестаёт принимать новые запросы, дренажит in-flight
  корутины (счётчик `spawnedCount`), затем `stopFlow` и выходит. `pcntl` включён в
  Dockerfile (dev + release). Проверено e2e (SIGTERM → in-flight дослуживается →
  сервер корректно завершается). Обработчики ставятся **до** `push` и восстанавливаются
  в `finally`. Idle-задержка shutdown снята поллингом (ниже).
- **Готово (idle shutdown):** `waitAnyTimeout(ms)` (новый cgo-экспорт); `Scheduler::serve`
  поллит каждые 250 мс и замечает сигнал даже без трафика.
- **Готово (стриминг ответов):** respond переведён на команды записи
  (`op` full/head/chunk/end) с round-trip-подтверждением (write backpressure);
  chunked/SSE через `http.Flusher`; PHP `StreamedResponse` + `ResponseStream::write`.
- **Готово (лимит конкурентности):** `HttpServer(maxConcurrency: N)` — семафор в Go
  `ServeHTTP` до чтения тела; ограничивает горутины, память (тела) и PHP-корутины разом.
- **Готово (503 при дренаже):** запрос, принятый но не отвеченный к моменту остановки,
  получает `503`, а не reset.
- **Готово (тестовый харнесс):** `SConcur\Tests\Impl\HttpServer\TestHttpServer` поднимает
  сервер отдельным процессом (`proc_open`) на loopback-порту с опциями запуска,
  названными точно как параметры `HttpServer` (`--maxRequestBody`, `--maxConcurrency`,
  таймауты — приоритетнее дефолтов). Все HTTP-тесты self-contained (работают под обычным
  `make test`); докер-сервис `http-server` остаётся для ручного запуска демо. Покрытие
  расширено: query-строка, заголовки запроса, бинарное тело, пустой ответ, e2e-лимит
  конкурентности, 413, graceful drain.
- **Готово (SO_REUSEPORT):** `HttpServer(reusePort: true)` — несколько процессов на
  одном порту, ядро балансирует соединения (process-per-core). `ext/.../listen.go`.
- **Готово (drain без потери трафика):** при shutdown листенер закрывается **в начале**
  дренажа (`httpStopAccepting` → Go `http.Server.Shutdown`, не отменяя in-flight), чтобы
  воркер вышел из reuseport-группы и ядро увело новые соединения на соседей. Новый
  cgo-экспорт `httpStopAccepting`. Покрыто
  `TestStopAcceptingClosesListener` (Go) и `HttpServerGracefulShutdownTest::
  testListenerStopsAcceptingAtDrainStart` (PHP).
- **Готово (стриминг тела запроса):** тело не буферизуется целиком — инлайн-первый-чанк
  в событии + остаток стримом через `bodyState` (StateContract, по образцу
  `aggregate`-курсора) и существующий путь `next`/`states`. PHP `$request->body` →
  `RequestBody` с `contents()` (полное) и `read(?int $maxBytes)` (буферизует/нарезает).
  Транспортная гранулярность фиксирована 64 KiB; превышение лимита →
  `RequestBodyTooLargeException` → `413`. Покрыто `TestBodyState*` (Go) и
  `HttpServerRequestStreamTest` (PHP).
- **Готово (access-лог):** колбэк `accessLog: Closure(AccessLogEntry): void` —
  вызывается после каждого запроса с полями `startedAt`/`method`/`path`/`status`/
  `executionMs`. Демо-сервер печатает строку в stdout. Покрыто `HttpServerAccessLogTest`
  (harness захватывает stdout во временный файл).
- **Все пункты плана и ревью закрыты.**

Ключевая реализация (отличие от первоначального наброска): listener — это
**стриминговая задача**, а не источник «события-результата» с произвольным
`taskKey`. Эмитить результаты с незарегистрированным `taskKey` напрямую в общий
канал нельзя — `Flow.OnDelivered` ломает учёт `tasksCount`. Поэтому каждый запрос
приходит как очередной батч (`HasNext=true`), а PHP переармливает поток через
`next()`. `requestId` для маршрутизации ответа лежит в payload события.

## Идея

Долгоживущий PHP-демон принимает HTTP-запросы и обрабатывает каждый в отдельной
корутине (Fiber), конкурентно с остальными. Сетевой I/O живёт в Go; каждый
входящий запрос превращается в обычный `Result` и приходит в PHP через тот же
единый канал `results` / `waitAny`, что и результаты задач. Это переиспользует
существующую машинерию (`Scheduler`, корутины, `waitAny`) и не вводит второй
event-loop — проблема «ждать сеть и Go-результаты одновременно» исчезает, потому
что сеть сведена к каналу результатов.

Базовая модель v1 — **spawn-на-запрос**: на каждое событие-запрос создаётся новая
корутина-обработчик (пул воркеров — возможное развитие).

## Поток

```
PHP-демон
  serverFlowUuid = uuid()
  Extension::httpStart(serverFlowUuid, port, ...)   // push задачи-листенера, рег. серверный flow
  Scheduler::serve():
    while (true) {
      res = Extension::waitAny()                     // единая точка ожидания
      switch dispatch(res):
        request-event   -> spawn(handler, request) в НОВОМ per-request flow; start() до 1-го suspend
        task-result     -> resume корутины по taskKey (как сейчас)
        respond-ack     -> resume корутины, ждавшей записи ответа (если await)
      // graceful: по сигналу — стоп accept, дождаться активных корутин, stopFlow(serverFlowUuid), break
    }

http-корутина (на запрос):
  $response = handler($request)                       // внутри — обычные async-вызовы (свой flow)
  Extension::httpRespond(requestId, $response)        // push ответа в Go
  (опц.) await подтверждения записи
  -> завершилась, per-request flow очищается

Go (фича httpserver):
  httpStart -> net.Listener + accept-loop (долгоживущий «вечный флоу»)
  на соединение: parse HTTP -> Result{type=request, requestId, payload={method,path,headers,body}}
                 -> в общий results-канал; горутина блокируется на responseCh[requestId]
  httpRespond(requestId, response) -> найти responseCh -> запись в conn (keep-alive/close)
  graceful shutdown: стоп accept, дождаться/закрыть активные соединения
```

## Новые сущности

### Go
- **Фича `httpserver`** (метод `MethodHttpServe`): по push-задаче поднимает
  `net.Listener` и accept-loop; это долгоживущий флоу, не завершается до
  `stopFlow(serverFlowUuid)`.
- На каждый запрос — горутина: парсит HTTP (`net/http`), формирует `Result` с
  **дискриминатором `request-event`**, кладёт в общий `results`, блокируется на
  per-request `responseCh` (держит соединение открытым → естественный backpressure).
- Реестр `requestId -> {responseCh, conn}`. `requestId` присваивает **Go** (не
  `Extension::push`).
- **Ответ** — через обычный `push` со спец-методом `MethodHttpRespond`
  (payload `{requestId, status, headers, body}`): обработчик находит `responseCh`,
  пишет в `conn`; результат задачи — подтверждение записи (корутина может его
  `await` → backpressure записи).

### PHP
- **`Scheduler::serve()` / `runForever()`** — серверный цикл поверх `waitAny()` с
  диспетчеризацией трёх видов результата (см. поток).
- **`Scheduler::spawn(Closure, $request)`** — fire-and-forget корутина вне
  `WaitGroup`. Требует доработки: сейчас `Coroutine` обязана иметь `group` (для
  `markReady`/`forget`) — нужна «серверная группа»-владелец либо опциональная
  группа для корутин, чей результат не собирают.
- DTO `Request` / `Response`.
- **`Extension::httpStart(...)`** и **`Extension::httpRespond(...)`** в обёртке
  расширения.

## Ключевые решения и обоснования

- **Инверсия инициатора.** Обычная задача инициируется из PHP (`push` заранее
  регистрирует `task->fiber` в `State`). Событие-запрос рождается в Go без
  предварительного push, поэтому в `State` записи нет. Scheduler по дискриминатору
  `request-event` (и отсутствию ключа в `State`) понимает «новый запрос → spawn»,
  а не «resume существующей корутины».
- **Per-request flow, а не один серверный.** `serverFlowUuid` — это flow листенера
  (accept). Каждый запрос обрабатывается в **своём** flow, чтобы под-задачи
  обработчика (Mongo/Sleeper) изолировались и корректно очищались, а `stopFlow`
  одного запроса не ронял весь сервер.
- **Сеть в Go.** Неблокирующий I/O, keep-alive, таймауты, парсинг — из `net/http`;
  PHP остаётся тонким оркестратором.

## Нюансы

- **Graceful shutdown.** Сигнал (`pcntl_signal`) в PHP → стоп accept (Go), дождаться
  активных корутин, `stopFlow(serverFlowUuid)`, выход из цикла. Нельзя обрывать
  запросы в полёте (правило «не `exit()` при активных задачах»).
- **Изоляция ошибок.** Исключение/паника в обработчике → ответ 500; петля `serve()`
  не падает.
- **Backpressure.** `results` unbuffered: горутина запроса блокируется до вычитки
  `waitAny`; при медленном PHP соединения копятся в accept-очереди ОС. Ограничивать
  числом одновременных корутин (см. [`wait-group-max-concurrency`](wait-group-max-concurrency.md)).
- **Большие тела.** v1 — копирование запроса/ответа через cgo; later — чанки/zero-copy.
- **Масштаб на ядра.** Один процесс = один PHP-поток; несколько процессов с
  `SO_REUSEPORT` в Go-listener — отдельная задача. FPM/`fork` по-прежнему нельзя;
  целевой сценарий — долгоживущий CLI.

## Этапы внедрения

1. [x] `Scheduler::spawn` + `serve()`.
2. [x] Go-фича `httpserver`: listener как стриминговая задача; `httpStart` (push `ServePayload`).
3. [x] `MethodHttpRespond` + DTO запроса/ответа; базовый запрос-ответ end-to-end.
4. [x] Keep-alive + таймауты (путь B: `net/http.Server`); Go-side graceful `Shutdown`;
   signal-driven graceful из PHP (`pcntl` SIGTERM/SIGINT → дренаж in-flight → stopFlow).
   Осталось: `waitAny` с таймаутом для мгновенного shutdown на idle-сервере.
5. [ ] Лимит конкурентности; затем стриминг ответов (SSE/chunked) через `http.Flusher`;
   затем мульти-процесс (`SO_REUSEPORT`).

## Доработки по ревью (2026-06-14)

Задачи по итогам дотошного ревью v1, по убыванию важности. Группа «критичное»
блокирует прод-использование.

### Критичное

1. [x] **Ошибка `bind` молча проглатывается.** `handleServe` отдаёт error-result с
   `HasNext=false`; `Scheduler::serve` ломал цикл по `!hasNext`, игнорируя
   `isError`/`payload`. Теперь error-end серверного таска бросает `TaskErrorException`,
   чистый `!hasNext` — штатный shutdown.
2. [x] **Хендлер, вернувший не-`Response`, вешает клиента.** `HttpServer::resolveResponse`
   теперь валидирует тип (`InvalidHandlerResponseException`) и оборачивает и вызов
   хендлера, и любой сбой в 500 — соединение всегда получает ответ.
3. [x] **Гонка на graceful shutdown теряет последний ответ.** В `ServeHTTP` ветка
   `ctx.Done()` теперь неблокирующе перечитывает `responseCh` и отдаёт уже готовый
   ответ, а не роняет его на гонке.
4. [x] **Исключения хендлера проглатываются без следа.** Добавлен опциональный
   `onError(Throwable, Request): ?Response` в конструктор `HttpServer`: может
   залогировать/трейсить и вернуть свой ответ; иначе fallback 500.
5. [x] **Тайм-аут хендлера.** `HttpServer(handlerTimeoutMs: N)` (0 = выкл). Ограничивает
   **полное** время обработки запроса (включая стрим): не уложился → обрыв + освобождение
   слота/соединения. До первого write → `504`; в середине стрима → обрыв (статус уже на
   проводе). Повисший respond разблокируется сигналом `abandoned` (корутина разворачивается,
   без залипания goroutine). Покрыто Go-тестом `TestServeHTTPHandlerTimeout` и
   `HttpServerHandlerTimeoutTest` (включая обрыв стрима по дедлайну).
   **Ограничение:** CPU-bound хендлер всё равно блокирует однопоточный цикл (кооперативная
   модель); полная отмена самой корутины — отдельная задача «остановка корутины».
6. [x] **Лимит конкурентности + памяти.** `HttpServer(maxConcurrency: N)` (0 =
   безлимит). Семафор в Go `ServeHTTP` захватывается **до** чтения тела, поэтому
   ограничивает разом: горутины, одновременно прочитанные тела (память) и — так как
   корутина живёт ⊆ времени удержания семафора — число PHP-корутин. Покрыто Go-тестом
   `TestServeHTTPRespectsMaxConcurrency`.

### Безопасность

7. [ ] **TLS.** Только `net.Listen("tcp")`; добавить `ServeTLS`/`tls.Config`.
8. [x] **Сигналы.** `installSignalHandlers` теперь ставит обработчики **до** `push`
   (нет окна пропуска сигнала на старте) и возвращает restorer, который в `finally`
   восстанавливает прежние обработчики `SIGTERM`/`SIGINT` и режим `async_signals`.

### Корректность / функционал

9.  [x] **Метаданные соединения** в `RequestEvent`: `RemoteAddr`/`Host`/`Proto`
    (ключи `ra`/`ho`/`pr`) проброшены в `Request` DTO. Покрыто `HttpServerMetadataTest`.
10. [x] **Мульти-значные заголовки ответа.** `RespondPayload.Headers` →
    `map[string][]string`; `Response::headers` принимает `string|list<string>` и
    нормализуется; `writeResponse` использует `Header().Add`. Покрыто тестом
    (несколько `Set-Cookie`).
11. [x] **Запросы во время дренажа → `503`.** Go `ServeHTTP` при отмене серверного
    контекста (если ответ ещё не начат) отдаёт `503 Service Unavailable` вместо reset —
    во всех точках выхода по shutdown (семафор, доставка, `consumeCommands`). Покрыто
    Go-тестом `TestServeHTTPAnswers503OnShutdown`.
12. [x] **`serve` хрупок к исключениям** из `next()`/`waitAny()` — цикл обёрнут в
    `try/finally`, `stopFlow(serverFlowKey)` гарантирован на любом выходе.
13. [x] **`waitAny` с таймаутом.** Новый экспорт `waitAnyTimeout(ms)` (Go
    `Handler.WaitAnyTimeout` + C-мост `sconcur.c`); PHP `Extension::waitAnyTimeout`
    возвращает `null` по таймауту (sentinel `"timeout"`). `Scheduler::serve` поллит с
    интервалом 250 мс, поэтому замечает сигнал shutdown даже на idle. Версия расширения
    поднята до `0.2.0`. Покрыто `TestWaitAnyTimeout` (Go) и `WaitAnyTimeoutTest` (PHP).
14. [~] **Стриминг + мульти-процесс.** Ответы (chunked/SSE через `http.Flusher`) —
    **готово**: respond переведён на команды записи (`op` full/head/chunk/end) с
    round-trip-подтверждением (write backpressure); PHP `StreamedResponse` +
    `ResponseStream::write`. **`SO_REUSEPORT`** — **готово**: `HttpServer(reusePort: true)`,
    несколько процессов на одном порту (`ext/.../listen.go`), покрыто
    `TestListenReusePort*` (Go) и `HttpServerReusePortTest` (PHP). **Стриминг тела
    запроса** — **готово**: `RequestBody::contents()/read()`, инлайн-первый-чанк +
    `bodyState`, `requestBodyChunkSize` (`HttpServerRequestStreamTest`).

### Тесты

15. [x] `testPostBodyIsReceived` не проверял тело (GET без body). Теперь POST на
    новый `/echo`-роут и сверка эха; роут добавлен в демо-сервер.
16. [x] Тесты добавлены (через `TestHttpServer`-харнесс): **413** + приём под лимитом
    (`HttpServerErrorsTest`); **graceful drain** (`HttpServerGracefulShutdownTest`,
    `SIGTERM` в in-flight `/msleep/500` → 200 + чистый выход 0); **query-строка,
    заголовки запроса, бинарное тело, пустой ответ** (`HttpServerRequestTest`);
    **e2e-лимит конкурентности** (`HttpServerMaxConcurrencyTest`, cap=2 → сериализация).
    Опциональный вынос `serve()` в чистый юнит — на будущее.

## Открытые вопросы

- **Парсинг HTTP** — целиком в Go (`net/http`, базовый план) или сырой запрос в PHP.
- **Стриминг/WebSocket/SSE** — в v1 или позже (потребует механики `next`/состояний
  для записи ответа частями).
- **Сигнатура обработчика** — низкоуровневый `(Request): Response` или мини-роутер
  поверх.
- **Пул воркеров** как альтернатива spawn-на-запрос — если понадобится жёсткий
  контроль числа обработчиков.
