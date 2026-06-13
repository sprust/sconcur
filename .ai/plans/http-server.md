# HTTP-сервер: каждый запрос в отдельной корутине

Статус: план. Краткий пункт — в [README → Планы](../../README.md#планы).

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

1. `Scheduler::spawn` + `serve()` (без сети — на «фейковых» событиях из теста).
2. Go-фича `httpserver`: listener + `request-event` в общий канал; `httpStart`.
3. `MethodHttpRespond` + DTO запроса/ответа; базовый запрос-ответ end-to-end.
4. Graceful shutdown, изоляция ошибок, keep-alive/таймауты.
5. Лимит конкурентности; затем стриминг ответов (SSE/chunked) через механику `next`;
   затем мульти-процесс (`SO_REUSEPORT`).

## Открытые вопросы

- **Парсинг HTTP** — целиком в Go (`net/http`, базовый план) или сырой запрос в PHP.
- **Стриминг/WebSocket/SSE** — в v1 или позже (потребует механики `next`/состояний
  для записи ответа частями).
- **Сигнатура обработчика** — низкоуровневый `(Request): Response` или мини-роутер
  поверх.
- **Пул воркеров** как альтернатива spawn-на-запрос — если понадобится жёсткий
  контроль числа обработчиков.
