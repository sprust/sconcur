# HTTP-сервер: каждый запрос в отдельной корутине

Статус: **v1 реализован** (этапы 1–3). Этапы 4–5 — в планах. Краткий пункт —
в [README → Планы](../../README.md#планы).

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
  сервер корректно завершается). **Ограничение:** serve блокирует в cgo `waitAny`, и
  PHP-сигнал обрабатывается лишь на следующем событии — на idle-сервере shutdown
  отложен до ближайшего запроса. Снимется `waitAny` с таймаутом (ниже).
- **Готово (стриминг ответов):** respond переведён на команды записи
  (`op` full/head/chunk/end) с round-trip-подтверждением (write backpressure);
  chunked/SSE через `http.Flusher`; PHP `StreamedResponse` + `ResponseStream::write`.
- **Осталось:** `waitAny` с таймаутом (мгновенный shutdown на idle); лимит
  конкурентности; стриминг **тела запроса** (читается целиком); мульти-процесс
  (`SO_REUSEPORT`); автотест в CI (нужен отдельно-процессный харнесс — в одном процессе
  `serve()` блокирует, а `fork` запрещён).

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
5. [ ] **Нет тайм-аута хендлера → утечка корутины/соединения.** Зависший хендлер
   держит слот и соединение до полного shutdown сервера. Ввести per-request дедлайн.
6. [ ] **Лимит конкурентности + памяти.** Тело читается целиком в память, канал
   `requests` буферизован на 1024, лимита одновременных обработчиков нет → флуд
   крупными телами = OOM. Ограничить число корутин (см. wait-group-max-concurrency).

### Безопасность

7. [ ] **TLS.** Только `net.Listen("tcp")`; добавить `ServeTLS`/`tls.Config`.
8. [ ] **Сигналы перехватываются глобально без восстановления** и ставятся после
   `push(serve)` (окно гонки). Восстанавливать прежние обработчики, ставить до push.

### Корректность / функционал

9.  [x] **Метаданные соединения** в `RequestEvent`: `RemoteAddr`/`Host`/`Proto`
    (ключи `ra`/`ho`/`pr`) проброшены в `Request` DTO. Покрыто `HttpServerMetadataTest`.
10. [x] **Мульти-значные заголовки ответа.** `RespondPayload.Headers` →
    `map[string][]string`; `Response::headers` принимает `string|list<string>` и
    нормализуется; `writeResponse` использует `Header().Add`. Покрыто тестом
    (несколько `Set-Cookie`).
11. [ ] **Запросы во время дренажа → `503`**, а не reset соединения.
12. [x] **`serve` хрупок к исключениям** из `next()`/`waitAny()` — цикл обёрнут в
    `try/finally`, `stopFlow(serverFlowKey)` гарантирован на любом выходе.
13. [ ] **`waitAny` с таймаутом** для мгновенного shutdown на idle-сервере.
14. [~] **Стриминг.** Ответы (chunked/SSE через `http.Flusher`) — **готово**: respond
    переведён на команды записи (`op` full/head/chunk/end) с round-trip-подтверждением
    (write backpressure); PHP `StreamedResponse` + `ResponseStream::write`. Покрыто
    `HttpServerStreamingTest`. **Осталось:** стриминг тела запроса (читается целиком);
    мульти-процесс `SO_REUSEPORT`.

### Тесты

15. [x] `testPostBodyIsReceived` не проверял тело (GET без body). Теперь POST на
    новый `/echo`-роут и сверка эха; роут добавлен в демо-сервер.
16. [ ] Добавить тесты: 413, graceful drain (SIGTERM с in-flight), большое/бинарное
    тело, заголовки; вынести логику `serve()` в юнит-тестируемую единицу.

## Открытые вопросы

- **Парсинг HTTP** — целиком в Go (`net/http`, базовый план) или сырой запрос в PHP.
- **Стриминг/WebSocket/SSE** — в v1 или позже (потребует механики `next`/состояний
  для записи ответа частями).
- **Сигнатура обработчика** — низкоуровневый `(Request): Response` или мини-роутер
  поверх.
- **Пул воркеров** как альтернатива spawn-на-запрос — если понадобится жёсткий
  контроль числа обработчиков.
