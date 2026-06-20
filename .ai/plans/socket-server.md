# План: Сокет-сервер (TCP)

Долгоживущий сетевой сервер поверх паттерна из
[«Как добавить новый сервер»](../../docs/adding-a-server.ru.md) — рядом с
`HttpServer`, под тем же [мастером воркеров](../../docs/worker-master.ru.md).
Эталон для копирования по всем механизмам — `HttpServer`
(`src/Features/HttpServer/`, `ext/internal/features/httpserver/`).

## Зафиксированные решения (согласовано с заказчиком)

1. **Модель обработки — «сообщение → ответ» (фрейминг).** Сервер сам нарезает
   байтовый поток соединения на сообщения и стримит каждое сообщение в PHP;
   обработчик возвращает один ответ на сообщение. Публичный обработчик:
   `Closure(Message): (string|MessageResponse|null)`.
2. **Фрейминг — length-prefix:** `uint32` big-endian длина payload + сам payload.
   Бинарно-безопасно, без экранирования, естественный лимит `maxMessageBytes`.
   Тот же формат в обе стороны (вход и ответ).
3. **Транспорт — только TCP** (`host:port`, как у `HttpServer`). Масштабирование
   на ядра — через `SO_REUSEPORT` (пул процессов под мастером, как у `HttpServer`).
   Unix-сокет в этой версии **не реализуем** (`SO_REUSEPORT` к `AF_UNIX`
   неприменим — мультиворкеры через fd-наследование можно добавить отдельной
   задачей позже).

## Ключевая идея маппинга на эталон

Сокет-сервер — это `HttpServer`, обобщённый на **много раундов запрос/ответ в
одном соединении**:

| HttpServer | SocketServer |
| --- | --- |
| HTTP-запрос = батч `Serve` | **Соединение** = батч `Serve` (`ConnectionEvent`) |
| стриминг тела запроса (`next()`) | стриминг **входящих фреймов** (`next()`), один фрейм за `Next()` |
| `Response`/чанки → `Respond` | **ответный фрейм** → `Respond` (write/close) |
| 1 корутина на запрос | 1 корутина на **соединение**, внутри — цикл по сообщениям |
| `maxRequests` | `maxConnections` (тот же параметр `Scheduler::serve`) |

**Последовательность внутри соединения гарантируется автоматически:** на одно
соединение — одна корутина, которая крутит цикл `next() → handler → respond`.
Конкурентность — **между** соединениями (их спавнит `Scheduler::serve`), внутри
одного соединения сообщения обрабатываются строго по порядку (что и требует
протокол с length-prefix без корреляционных id). Внутри обработчика по-прежнему
можно делать async-вызовы (Sleeper, Mongodb, …) — корутина кооперативно
приостанавливается, другие соединения продолжают работать.

`Scheduler::serve` переиспользуется **как есть** — переписывать его не нужно.

---

## Поток данных одного сообщения

```
клиент --(len+payload)--> Go listener.Accept --(ConnectionEvent)--> connections-канал
   serverState.Next() отдаёт ConnectionEvent в PHP
   --> Scheduler::serve спавнит корутину на соединение
   --> SocketServer::handleConnection цикл:
         frame = next(connId:in)            (messageState читает 1 фрейм из conn)
         if frame === null: break           (EOF/закрытие)
         response = handler(new Message(...))
         Respond(connId, op=FRAME, data=response)   --> Go пишет len+payload в conn
   --> finally: Respond(connId, op=SKIP, close=true) --> Go закрывает conn
```

---

## PHP-сторона (`src/Features/SocketServer/`)

### `MethodEnum` (`src/Features/MethodEnum.php`)

Добавить два значения:

```php
case SocketServe   = 8;
case SocketRespond = 9;
```

### Payloads

**`Payloads/ServePayload.php`** (`implements PayloadInterface`, `getMethod(): SocketServe`):
адрес + тюнинг. Поля (msgpack-ключи короткие, зеркалят Go 1:1):

| Параметр | Ключ | Тип | Назначение |
| --- | --- | --- | --- |
| `address` | `ad` | string | `host:port`, напр. `0.0.0.0:9100` |
| `readTimeoutMs` | `rt` | int | idle-таймаут между сообщениями (нет фрейма → закрыть). 0 = выкл |
| `writeTimeoutMs` | `wt` | int | дедлайн записи одного ответного фрейма. 0 = выкл |
| `handlerTimeoutMs` | `hto` | int | макс. время обработки **одного сообщения** (таймер на Go, независим от PHP). 0 = выкл |
| `maxMessageBytes` | `mmb` | int | лимит длины одного фрейма (защита от огромного length-prefix) |
| `maxConcurrency` | `mc` | int | макс. одновременно обслуживаемых соединений (0 = без лимита) |
| `shutdownTimeoutMs` | `sht` | int | таймаут graceful-дренажа |
| `reusePort` | `rp` | bool | `SO_REUSEPORT` (пул процессов на один порт) |

**`Payloads/RespondPayload.php`** — одна запись ответа на сообщение. Фабрики:
- `RespondPayload::frame(string $connectionId, string $data, bool $close = false)` — записать ответный фрейм (op=FRAME); `$data` может быть `''` (валидный фрейм нулевой длины);
- `RespondPayload::skip(string $connectionId, bool $close = false)` — нет ответа на сообщение, только снять handler-таймер (op=SKIP);
- `RespondPayload::close(string $connectionId)` — закрыть соединение (op=SKIP, close=true).

Поля/ключи: `connectionId` (`cid`), `op` (`op`: `OP_FRAME=0`/`OP_SKIP=1`), `close` (`cl` bool), `data` (`dt`). `getMethod(): SocketRespond`.

> `connectionId` — сквозной id (Go генерит `flowKey:c:<n>` на приёме), кладёт в
> `ConnectionEvent`, PHP возвращает его в каждом `RespondPayload`, Go по нему
> находит висящее соединение в `pendingConnections`.

### DTO

**`Dto/Message.php`** (`readonly`) — одно входящее сообщение, которое получает
обработчик:
```php
public function __construct(
    public string $connectionId,
    public string $data,         // payload фрейма (бинарно-безопасно)
    public string $remoteAddr,   // "ip:port" клиента
    public string $localAddr,
    public int    $messageIndex, // порядковый номер сообщения в соединении (с 0)
) {}
```

**`Dto/MessageResponse.php`** (`readonly`) — расширенный возврат обработчика,
когда нужно закрыть соединение после ответа:
```php
public function __construct(
    public string $data,
    public bool   $close = false,
) {}
```
(Обычный возврат `string` = ответ без закрытия; `null` = без ответа.)

`ConnectionEvent` отдельным PHP-DTO **не нужен** — `SocketServer::handleConnection`
декодит его инлайн из payload батча (msgpack: `connectionId`, `remoteAddr`,
`localAddr`) и кладёт поля в каждый `Message`.

### Общий трейт `ServerRuntimeSupportTrait` (`src/Features/Server/`)

Лёгкий трейт, чтобы не дублировать рантайм-обвязку сервера между `HttpServer` и
`SocketServer`. Выносим в него ровно три куска, сейчас скопированных в
`HttpServer.php`:

- `protected static function parseArgs(array $argv): array` — рефлексивный разбор
  argv в `overrides` (тело текущего `HttpServer::fromArgs()` без финального
  `new`); каждый сервер делает `new self(...static::parseArgs($argv) + $extra)`;
- `protected function installSignalHandlers(bool &$stopRequested): Closure` —
  как `HttpServer::installSignalHandlers()`;
- `protected static function isOrphaned(int $masterPid): bool` —
  как `HttpServer::isOrphaned()`.

**`HttpServer` рефакторим на этот же трейт** (убрать копии методов), чтобы это
была настоящая дедупликация, а не вторая копия. Рефлексия в `parseArgs`
использует `new ReflectionClass(static::class)`, так что собирает параметры
конкретного сервера. Трейт без состояния — «lite».

### `SocketServer.php` (`readonly class`, эталон — `HttpServer.php`)

Конструктор (значения по умолчанию зеркалят Go):
```php
public function __construct(
    private string   $address          = '0.0.0.0:9100',
    private int      $readTimeoutMs     = 0,        // idle между сообщениями
    private int      $writeTimeoutMs    = 30_000,
    private int      $handlerTimeoutMs  = 60_000,
    private int      $maxMessageBytes   = 1_048_576, // 1 MiB
    private int      $maxConcurrency    = 0,
    private int      $maxConnections    = 0,         // → Scheduler::serve maxRequests
    private int      $shutdownTimeoutMs = 5_000,
    private bool     $reusePort         = false,
    private ?Closure $onError           = null,      // Closure(Throwable, Message): ?string
    private ?Closure $onConnect         = null,      // Closure(string $connectionId, string $remoteAddr): void
    private ?Closure $onClose           = null,      // Closure(string $connectionId): void
    private ?int     $masterPid         = null,
) {}
```

> `onConnect`/`onClose` — опциональные lifecycle-хуки соединения (для
> per-connection состояния в userland: завести сессию в `onConnect`, очистить в
> `onClose`). Чисто PHP-сторона, протокол не меняют. `onClose` вызывается в
> `finally` корутины соединения — гарантированно при любом завершении (EOF,
> ошибка, shutdown).

- **`fromArgs(array $argv, ?Closure $onError = null): self`** — использует общий
  трейт `ServerRuntimeSupportTrait` (см. ниже): трейт парсит argv в массив
  `overrides` (скалярные параметры конструктора → `--имя=значение`, приведение
  типов, бросок на неизвестный аргумент), а `fromArgs` собирает
  `new self(...$overrides)`. Мастер прокидывает `--masterPid` сюда.

- **`serve(Closure $handler): void`** — структурно копия `HttpServer::serve`:
  1. `flowKey = uniqid('sock_', true)`; установить обработчики SIGTERM/SIGINT
     (флаг `stopRequested`), восстановить в `finally` — через трейт
     `ServerRuntimeSupportTrait` (`installSignalHandlers()`/`isOrphaned()`);
  2. `Extension::get()->push($flowKey, new ServePayload(...))` — запуск слушателя
     (стриминговая задача-аккорд);
  3. `Scheduler::get()->serve(serverFlowKey: $flowKey, serverTaskKey: …,
     maxRequests: $this->maxConnections, onRequest: …, shouldStop: …,
     onDrainStart: …)`:
     - **`onRequest`** → `self::handleConnection($handler, $onError, $payload)`;
     - **`shouldStop`** → `$stopRequested || orphan-чек` (как у эталона);
     - **`onDrainStart`** → `Extension::get()->socketStopAccepting($flowKey)`.

- **`handleConnection(Closure $handler, ?Closure $onError, ?Closure $onConnect,
  ?Closure $onClose, string $payload): void`** (статическая, запускается в
  спавн-корутине на каждое соединение):
  1. декодить `ConnectionEvent` (`connectionId`, `remoteAddr`, `localAddr`)
     из msgpack;
  2. `$onConnect?->($connectionId, $remoteAddr)` (best-effort, в try);
  3. цикл по сообщениям:
     ```php
     $index = 0;
     try {
         while (true) {
             $frame = FeatureExecutor::next(taskKey: $connectionId . ':in'); // RunningResult
             if (!$frame->hasNext && $frame->payload === '') {
                 break; // EOF / соединение закрыто клиентом
             }
             $message = new Message(connectionId: …, data: $frame->payload, …, messageIndex: $index++);
             $response = self::resolveResponse($handler, $onError, $message); // string|MessageResponse|null
             self::respond($connectionId, $response); // FeatureExecutor::exec(RespondPayload::…)
             if ($response instanceof MessageResponse && $response->close) {
                 return;
             }
         }
     } catch (TaskErrorException) {
         // соединение оборвано/abandoned со стороны Go — выходим из цикла.
     } finally {
         self::sendClose($connectionId);   // RespondPayload::close, best-effort
         $onClose?->($connectionId);        // best-effort, в try
     }
     ```
     (точная форма чтения фрейма через `next()` — см. `RequestBody::pullChunk()`,
     `FeatureExecutor::next($taskKey): TaskResultDto`).
  4. `resolveResponse()` — копия защитной логики `HttpServer::resolveResponse`:
     любой `Throwable` или результат не того типа → `onError` (может вернуть
     строку-ответ) → иначе **без ответа** (op=SKIP). На сокете нет «500», поэтому
     по умолчанию ошибочный обработчик просто не отвечает (поведение
     конфигурируется через `onError`).

  > **Важно:** на каждое прочитанное сообщение PHP обязан отправить **ровно один**
  > `Respond` (FRAME или SKIP) — это снимает handler-таймер на Go-стороне и даёт
  > write-backpressure. На завершении цикла — один `close`.

### Точка входа воркера

Демо/тест-скрипт `tests/servers/socket/socket-server.php` (аналог
`tests/servers/http/http-server.php`): `SocketServer::fromArgs($_SERVER['argv'])`
+ `serve(...)` с несколькими демо-«командами» (echo, uppercase, ping/pong,
async-msleep, native-block для теста handler-таймаута, close-после-ответа).

---

## Go-сторона (`ext/internal/features/socketserver/`)

### `types/method.go`

```go
MethodSocketServe   Method = 8
MethodSocketRespond Method = 9
```

### `features/factory.go`

```go
case types.MethodSocketServe, types.MethodSocketRespond:
    return socketserver_feature.Get(), nil
```

### `payloads/payloads.go`

`ServePayload`, `RespondPayload`, `ConnectionEvent` — зеркалят PHP 1:1
(см. таблицы выше). `ConnectionEvent`:
```go
type ConnectionEvent struct {
    ConnectionId string `msgpack:"cid"`
    RemoteAddr   string `msgpack:"ra"`
    LocalAddr    string `msgpack:"la"`
}
```

### `feature.go` (эталон — `httpserver/feature.go`)

- Синглтон `Get()`, `Handle(task)` → `switch Method` → `handleServe`/`handleRespond`.
- Глобальные карты: `pendingConnections sync.Map` (`connId → *pendingConnection`),
  `serverStates sync.Map` (`flowKey → *serverState`), `connectionCounter atomic.Int64`.
- `handleServe(task)`: распарсить `ServePayload`; `listener := listen(address,
  reusePort)`; `state := newServerState(task.GetContext(),
  message, listener, config)`; `serverStates.Store(flowKey, state)`;
  `states.Get().Start(ctx, taskKey, state)` → первый `ConnectionEvent` как первый
  батч.
- `handleRespond(task)`: декодить `connectionId` отдельной мини-структурой (как
  `idOnly` в эталоне), найти `pendingConnection`, `dispatch()` команду
  (write-backpressure через `done`-канал, `abandoned` + `ctx.Done()` —
  скопировать `dispatch` из эталона дословно).
- `StopAccepting(flowKey)` — найти `serverState`, `state.stopAccepting()`.
- `nextConnectionId(flowKey) = flowKey + ":c:" + counter`.

### `server.go` (эталон — `httpserver/server.go`)

`serverState` (`implements contracts.StateContract`):
- поля: `ctx`, `message`, `listener`, `config`, `startTime`,
  `connections chan *payloads.ConnectionEvent` (буфер ~1024), `sem` (семафор
  `maxConcurrency`);
- в `newServerState` запускается **accept-loop горутина**:
  ```go
  go func() {
      for {
          conn, err := listener.Accept()
          if err != nil { return } // listener закрыт (stopAccepting/Close)
          go s.handleConn(conn)
      }
  }()
  ```
  (в отличие от `HttpServer`, который поднимает `net/http.Server` — здесь сырой
  `Accept`, т.к. протокол свой);
- **`handleConn(conn net.Conn)`**:
  1. семафор (до чтения), на `ctx.Done()` — закрыть conn, выйти;
  2. `connId := nextConnectionId(...)`;
  3. `pending := &pendingConnection{commands, abandoned, messageStarted}`;
     `pendingConnections.Store(connId, pending)`;
  4. `msgState := newMessageState(s.message, conn, config, pending)`;
     `states.Get().Register(connId+":in", msgState)`;
  5. построить `ConnectionEvent`, отправить в `s.connections` (или `ctx.Done()`
     → cleanup);
  6. `s.consumeCommands(conn, pending)` — **цикл записи** (см. ниже);
  7. defer-cleanup: `close(pending.abandoned)`,
     `pendingConnections.Delete(connId)`, `states.Get().DeleteState(connId+":in")`,
     `conn.Close()`, освободить семафор, записать access-лог (на Go-стороне).
- **`consumeCommands(conn, pending)`** — обобщение эталонного на много
  сообщений. Один за другим применяет `writeCommand`'ы, отдавая результат в
  `done` (write-backpressure). **Per-message handler-таймаут** реализуется так:
  - `pending.messageStarted chan struct{}` (буфер 1) — `messageState.Next()`
    шлёт в него неблокирующе после выдачи фрейма в PHP («сообщение пошло в
    обработку»);
  - цикл `select`:
    - `<-pending.messageStarted` → взвести таймер `handlerTimeout`;
    - `command := <-pending.commands` → применить (FRAME → `writeFrame(conn, data)`
      с `writeTimeout`-дедлайном; SKIP → ничего), снять таймер, ответить в `done`;
      если `command.close` → выйти из цикла (→ conn закроется в cleanup);
    - `<-timer.C` → handler завис (в т.ч. нативный блок PHP) → выйти (cleanup
      закроет conn и `abandoned`, поздний `Respond` получит `errAbandoned`);
    - `<-s.ctx.Done()` → сервер останавливается → выйти.
  - т.к. обработка строго последовательна (одно in-flight сообщение на
    соединение), таймер всегда взводится/снимается по одному.

`stopAccepting()` — `listener.Close()` (Accept выйдет; in-flight не трогаем).
`Close()` — снять `serverStates`, закрыть listener, дождаться дренажа in-flight
с `shutdownTimeout`. `Next()` —
`select { case ev := <-connections: marshal → NewSuccessResultWithNext;
case <-ctx.Done(): NewSuccessResult("") }` (финальный батч завершает PHP-цикл
`serve`).

### `message_state.go` (эталон — `httpserver/body_state.go`)

`messageState` (`implements StateContract`):
- `Next()`: установить read-дедлайн (`readTimeoutMs`, если задан) → `readFrame(conn,
  maxMessageBytes)`:
  - успех → неблокирующе сигналить `pending.messageStarted`, вернуть
    `NewSuccessResultWithNext(frame)` (будет ещё);
  - `io.EOF` / закрытое соединение → `NewSuccessResult("")` (финал, без next —
    PHP-цикл выходит);
  - длина > `maxMessageBytes` или таймаут чтения → `NewErrorResult(...)`
    (PHP получит `TaskErrorException`, выйдет из цикла → соединение закроется).
- `Close()` — no-op (conn закрывает `handleConn`).

### `frame.go`

- `readFrame(r io.Reader, maxBytes int) ([]byte, error)`: прочитать 4 байта BE
  длины (`io.ReadFull`), проверить `<= maxBytes`, прочитать payload (`io.ReadFull`).
- `writeFrame(w io.Writer, payload []byte) error`: записать 4 байта BE длины +
  payload (одним `Write` по возможности).
- Гранулярность фиксирована форматом; отдельный размер чанка не нужен (читаем
  ровно фрейм).

### `listen.go` (эталон — `httpserver/listen.go`)

`listen(address string, reusePort bool) (net.Listener, error)` — практически
копия эталона: `net.Listen("tcp", address)`, а при `reusePort` —
`net.ListenConfig` с `SO_REUSEPORT` на сокете (тот же `Control`-хук, что в
`httpserver/listen.go`). Только TCP.

### Access-лог (на Go-стороне)

Одна строка на **соединение** при закрытии: `<ISO-время> <remoteAddr>
msgs=<n> in=<байт> out=<байт> <ms>ms` — через `logger.Write(...)` из
`handleConn` (без PHP↔Go-кроссинга на сообщение), как у эталона.

---

## cgo / протокол

Новый экспорт **`socketStopAccepting`** по цепочке эталонного
`httpStopAccepting`:
- `ext/main.go` — `//export socketStopAccepting` → `socketserver_feature.StopAccepting(C.GoString(fk))`;
- `ext/sconcur.c` — `PHP_FUNCTION`, `arginfo`, `ZEND_NS_FE`, строка в шапке-комментарии;
- `ext/sconcur.stub.php` — объявление `function socketStopAccepting(string $fk): void`;
- `src/Connection/Extension.php` — `use function …\socketStopAccepting;` + метод-обёртка `socketStopAccepting(string $flowKey): void`.

Остальные экспорты (`push`, `next`, `stopFlow`, `waitAny`, `waitAnyTimeout`)
переиспользуются.

### Версия расширения

Это протокольное изменение (2 новых метода + новый экспорт). Ветка
`feature/socket-server` пока **без бампа** (текущая `0.2.3`, последний бамп — на
master). Бампим **один раз на ветке**: `ext/main.go` `version()` и
`src/Connection/Extension.php` `REQUIRED_EXTENSION_VERSION` → **`0.2.4`** (патч —
по прецеденту репозитория: фича http-client `download()` тоже была патчем).
Минор/мажор — только с одобрения мейнтейнера.

---

## Интеграция с мастером воркеров

«Бесплатно», если соблюсти контракт `bin/sconcur-server` (мастер
server-agnostic): параметры из блока `server` JSON-конфига разворачиваются в
`--ключ=значение` argv, `fromArgs` их разбирает, `--masterPid` → orphan-чек,
`reusePort: true` → пул процессов на ядра (только TCP).

Добавить пример конфига `config/sconcur.socket-server.config.json` (аналог
`config/sconcur.http-server.config.json`) c `workerScript` →
`tests/servers/socket/socket-server.php` и блоком `server`
(`address: "0.0.0.0:9100"`, `reusePort: true`, лимиты/таймауты).

---

## Тесты (обязательно)

**Инфраструктура** (эталон — `tests/impl/HttpServer/TestHttpServer.php` +
`tests/feature/Features/HttpServer/BaseHttpServerTestCase.php`):
- `tests/impl/SocketServer/TestSocketServer.php` — спавн реального процесса
  сервера через `proc_open` на свободном TCP-порту, ожидание готовности
  (коннект), чтение access-лога, `signal()/stop()/waitForExit()`;
- `tests/feature/Features/SocketServer/BaseSocketServerTestCase.php` — свой
  сервер на класс, хелперы `sendFrame()/recvFrame()` (length-prefix codec на
  PHP-стороне через `pack('N', …)`), `roundtrip()`, `concurrentRoundtrips()`.

**Покрыть** (по аналогии с `HttpServer*Test.php`):
- базовый round-trip (echo) по TCP;
- несколько сообщений в одном соединении (порядок ответов);
- бинарно-безопасный payload (нулевые байты, фрейм нулевой длины);
- `maxMessageBytes` (фрейм сверх лимита → соединение закрывается с ошибкой);
- `maxConcurrency` (одновременные соединения);
- `handlerTimeoutMs` — async-обработчик **и** нативно-блокирующий (таймер Go
  закрывает соединение независимо от PHP);
- `readTimeoutMs` (idle-соединение закрывается);
- `null`-ответ (нет фрейма) и `MessageResponse(close: true)` (закрытие после ответа);
- graceful shutdown (дренаж in-flight, новые коннекты не принимаются);
- `SO_REUSEPORT` (два процесса на одном TCP-порту);
- `maxConnections` (сервер завершается после N соединений);
- orphan-самозавершение (`--masterPid`);
- `onError` (наблюдение ошибки обработчика + кастомный ответ).

**Go-тесты** (`make ext-test`, эталон — `httpserver/*_test.go`):
- `frame_test.go` — codec readFrame/writeFrame (границы, частичные чтения, лимит);
- `listen_test.go` — tcp + reusePort (два слушателя на одном порту);
- `server_test.go` / `message_state_test.go` — accept-loop, consumeCommands,
  handler-таймер, EOF.

**e2e под мастером** — расширить/повторить паттерн
`tests/feature/Worker/WorkerMasterTest.php` для сокет-конфига (если оправдано).

---

## Документация

- `docs/socket-server.ru.md` — пользовательская дока (быстрый старт, параметры,
  фрейминг length-prefix, graceful shutdown/SO_REUSEPORT, ограничения) по образцу
  `docs/http-server.ru.md`;
- `.ai/README.md` — строка в «PHP layer» / «Go extension» / «Key enums»
  (методы 8/9), ссылка в «Further Reading»;
- `README.md` — в `## Планы` строку «Сокет-сервер» пометить как реализованную и
  добавить ссылку в `## Документация`;
- `docs/worker-master.ru.md` — в «Поддерживаемые серверы» добавить SocketServer.

---

## Чеклист реализации

PHP:
- [ ] `MethodEnum`: `SocketServe = 8`, `SocketRespond = 9`.
- [ ] `Payloads/ServePayload.php`, `Payloads/RespondPayload.php` (+ кросс-ссылки `Go:`).
- [ ] `Dto/Message.php`, `Dto/MessageResponse.php`.
- [ ] `Features/Server/ServerRuntimeSupportTrait` (трейт) + **рефакторинг `HttpServer`**
      на него (дедуп `parseArgs`/`installSignalHandlers`/`isOrphaned`).
- [ ] `SocketServer.php`: `fromArgs()`, `serve()`, `handleConnection()`
      (с `onConnect`/`onClose`), `resolveResponse()`; сигналы + orphan-чек из трейта.
- [ ] `Extension::socketStopAccepting()`.
- [ ] `tests/servers/socket/socket-server.php` + `config/sconcur.socket-server.config.json`.

Go:
- [ ] `types/method.go`: 8/9; `features/factory.go`: один кейс на оба метода.
- [ ] `payloads/payloads.go`: `ServePayload`/`RespondPayload`/`ConnectionEvent`.
- [ ] `feature.go`: `Handle`-switch, `handleServe`/`handleRespond`/`dispatch`,
      `pendingConnections`/`serverStates`, `StopAccepting`.
- [ ] `server.go`: `serverState` (accept-loop, `handleConn`, `consumeCommands`,
      handler-таймер через `messageStarted`, `Next`/`Close`/`stopAccepting`).
- [ ] `message_state.go`: чтение фреймов, EOF/таймаут/лимит.
- [ ] `frame.go`: length-prefix codec; `listen.go`: tcp + `SO_REUSEPORT`.
- [ ] access-лог на Go-стороне.

cgo / протокол:
- [ ] `socketStopAccepting`: `main.go` → `sconcur.c` → `sconcur.stub.php` → `Extension.php`.
- [ ] Версия расширения → `0.2.4` (оба места).

Проверка: `make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test`.

---

## Решения (согласовано)

1. **Дедупликация** `installSignalHandlers()`/`isOrphaned()`/разбор argv — через
   **lite-трейт `ServerRuntimeSupportTrait`** (`src/Features/Server/`); `HttpServer`
   рефакторим на него же, чтобы это была настоящая дедупликация. Трейт без
   состояния.
2. **`onConnect`/`onClose`-хуки** — **включаем** как опциональные nullable-замыкания
   конструктора. Чисто PHP-сторона, протокол не меняют (`onConnect` в начале
   корутины соединения, `onClose` — в `finally`).
3. **Ошибочный обработчик по умолчанию** — **не отвечать и не закрывать** (op=SKIP);
   `onError` может вернуть строку-ответ. На сокете «500» нет.
