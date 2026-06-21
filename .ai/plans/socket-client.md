# План: фича SocketClient (TCP-клиент)

Статус: **реализовано** (ветка `feature/socket-client`, версия расширения `0.3.0`).
Документация по фиче — [docs/socket-client.ru.md](../../docs/socket-client.ru.md). Этот
файл сохранён как запись о принятых решениях.

## 1. Что это и зачем

`SocketClient` — асинхронный **TCP-клиент** с фреймингом length-prefix. Это
зеркальная пара к [`SocketServer`](../../docs/socket-server.ru.md), так же как
[`HttpClient`](../../docs/http-client.ru.md) — пара к `HttpServer`.

Модель — **долгоживущее двунаправленное соединение** (а не «запрос-ответ»):
приложение набирает (`connect`) исходящее соединение, получает объект `Connection`
и само ведёт диалог — `read()` тянет входящие фреймы, `write()` пушит исходящие,
`close()` закрывает. Весь сетевой I/O (DNS, dial, чтение/запись) живёт в Go; PHP —
тонкий оркестратор. Внутри `WaitGroup` десятки соединений идут «веером»; вне Fiber
тот же API работает синхронно (как все фичи SConcur).

Сценарии: клиент к собственному `SocketServer`, к произвольному length-prefix
TCP-протоколу, межсервисный bidirectional-канал.

### Структурная аналогия

SocketClient — это, по сути, **per-connection половина `SocketServer`,
инициированная dial вместо accept**. Совпадают:

- кодек фреймов length-prefix (`frame.go`);
- стриминг входящих фреймов (`messageState` → `read()`);
- цикл записи с backpressure (`pendingConnection`/`consumeCommands` → `write()`);
- DTO `Connection` (read/write/close) на стороне PHP.

Отличие — происхождение соединения: `SocketServer` принимает (accept loop отдаёт
`ConnectionEvent`-ы), `SocketClient` набирает один `Connection` на `connect()`.
Это определяет ключевое решение плана — **выделить общий код** (см. §6).

## 2. Публичный PHP API

```php
use SConcur\Features\SocketClient\SocketClient;
use SConcur\Features\SocketClient\SocketClientOptions;

$client = new SocketClient(new SocketClientOptions(
    connectTimeoutMs: 10_000,
    readTimeoutMs:    0,        // idle-таймаут чтения, 0 = выкл
    writeTimeoutMs:   30_000,
    maxMessageBytes:  1_048_576,
));

$connection = $client->connect('127.0.0.1:9100'); // -> Dto\Connection

$connection->write('ping');                        // запушить фрейм
$frame = $connection->read();                      // ?string, null на EOF/закрытии

$connection->close();
```

Конкурентность «веером»:

```php
$waitGroup = WaitGroup::create();

foreach ($addresses as $address) {
    $waitGroup->add(function () use ($client, $address) {
        $connection = $client->connect($address);
        $connection->write('hello');
        $reply = $connection->read();
        $connection->close();

        return $reply;
    });
}

$replies = $waitGroup->waitResults(); // общее время ≈ самого медленного соединения
```

`Connection` живёт в своей корутине: `connect/read/write` кооперативно
приостанавливают её, остальные соединения продолжают работать. Соединение лучше
дочитывать **внутри** той же корутины, что и `connect()` — при завершении корутины
её флоу останавливается и недочитанное соединение на Go-стороне закрывается (та же
оговорка, что у `HttpClient`/`SocketServer`).

## 3. Соответствие `Method` PHP ↔ Go

Текущий максимум — `SocketRespond = 9`. Добавляем **один** новый домен с
конвертом-командой (как `HttpClient`, не как `SocketServer` с двумя методами):

- PHP: `src/Features/MethodEnum.php` → `case SocketClient = 10;`
- Go: `ext/internal/types/method.go` → `MethodSocketClient Method = 10`

Под-операции (по образцу `HttpClientCommand`, файл `ext/internal/types/httpclient.go`):

- PHP: `src/Features/SocketClient/SocketClientCommandEnum.php`
- Go: `ext/internal/types/socketclient.go`

| Команда | Значение | Назначение |
| --- | --- | --- |
| `Connect` | 1 | набрать соединение, вернуть `ConnectionMeta`, поднять inbound-стрим + write-loop |
| `Send`    | 2 | запушить один фрейм в соединение (`cid` + данные) |
| `Close`   | 3 | закрыть соединение (`cid`) |

Чтение входящих фреймов — это не команда, а `next()` по стриминговому состоянию
inbound (ключ `cid:in`), как у `SocketServer::Connection::read()`
(`src/Features/SocketServer/Dto/Connection.php:47`).

## 4. PHP-слой (`src/Features/SocketClient/`)

| Файл | Роль |
| --- | --- |
| `SocketClient.php` | публичный API: `connect(string $address): Connection`. Собирает `ConnectPayload`, через `FeatureExecutor::exec()` набирает соединение, декодирует `ConnectionMeta` (`cid`/`ra`/`la`) и строит `Dto\Connection`. Конструктор — `(SocketClientOptions $options = new SocketClientOptions())`. |
| `SocketClientOptions.php` | `readonly` DTO опций (см. §5). |
| `SocketClientCommandEnum.php` | `int`-enum: `Connect=1, Send=2, Close=3`. |
| `Dto/Connection.php` | `read(): ?string` / `write(string): void` / `close(): void` / `isClosed(): bool` + `id`/`remoteAddr`/`localAddr`. Зеркало `SocketServer\Dto\Connection`, но `write/close` шлют `SendPayload`/`ClosePayload` с `MethodEnum::SocketClient`. См. §6 про общий базовый класс/трейт. |
| `Payloads/Base/BaseSocketClientPayload.php` | абстрактный конверт `['cm' => command, 'p' => parameters]`, `getMethod() = MethodEnum::SocketClient` (образец — `HttpClient\Payloads\Base\BaseHttpClientPayload`). |
| `Payloads/ConnectPayload.php` + `ConnectPayloadParameters.php` | команда `Connect`: адрес + все опции (timeouts, `maxMessageBytes`). |
| `Payloads/SendPayload.php` | команда `Send`: `cid` + `data` (бинарно-безопасно). |
| `Payloads/ClosePayload.php` | команда `Close`: `cid`. |

Исключения (`src/Exceptions/SocketClient/`):

Исключения **парные** (своё на каждую фичу), имя несёт префикс фичи (решение по
вопросу 3):

- `SConcur\Exceptions\SocketClient\SocketClientConnectException extends RuntimeException`
  — сбой dial (refused / DNS-fail / connect-timeout). Бросается из
  `SocketClient::connect()`; Go помечает класс ошибки маркером (`net:`), PHP мапит
  маркер → класс (образец — `HttpClient::toClientException`, маркеры `net:`/`req:`).
- `SConcur\Exceptions\SocketClient\SocketClientConnectionClosedException extends RuntimeException`
  — `write()` в мёртвое соединение.

Заодно (решение 3) **переименовать существующее** серверное исключение
`SConcur\Exceptions\SocketServer\ConnectionClosedException` →
`SocketServerConnectionClosedException` (класс несёт префикс фичи; namespace
остаётся `SConcur\Exceptions\SocketServer`). Поправить ссылки в
`src/Features/SocketServer/Dto/Connection.php` (использование) — это единственная
точка использования.

### Payload-ключи (msgpack), зеркалятся 1:1 с Go

- `ConnectPayloadParameters.getData()`:
  `['ad'=>address, 'ct'=>connectTimeoutMs, 'rt'=>readTimeoutMs, 'wt'=>writeTimeoutMs, 'mmb'=>maxMessageBytes]`
- `SendPayload` → params `['cid'=>id, 'dt'=>data]`
- `ClosePayload` → params `['cid'=>id]`
- `ConnectionMeta` (Go→PHP, первый результат connect): `['cid'=>id, 'ra'=>remoteAddr, 'la'=>localAddr]`

Все payload-классы — `readonly`, типизированы, с docblock-кросс-ссылкой
`Go: payloads.<Type> (ext/internal/features/socketclient/payloads/payloads.go)`
(требование [docs/adding-a-feature.ru.md](../../docs/adding-a-feature.ru.md)).

## 5. Опции (`SocketClientOptions`)

`readonly`, дефолты зеркалят Go. Берём только клиентское подмножество опций
`SocketServer` (без `maxConcurrency`/`maxConnections`/`reusePort`/`shutdown*` —
это серверное).

| Параметр | Дефолт | Назначение |
| --- | --- | --- |
| `connectTimeoutMs` | `10000` | предел dial (`net.Dialer.Timeout` / `DialContext`). |
| `readTimeoutMs` | `0` (выкл) | idle-таймаут ожидания входящего фрейма в `read()`. |
| `writeTimeoutMs` | `30000` | максимум на запись одного фрейма. |
| `maxMessageBytes` | `1048576` (1 MiB) | лимит длины одного входящего фрейма. |

> **Два обязательных требования** Go-стороны
> ([adding-a-feature.ru.md](../../docs/adding-a-feature.ru.md)): (1) отмена
> контекста — на остановке флоу dial/read/write должны прерваться, ресурсы
> освободиться; (2) предельное время — у долгоживущего соединения нет единого
> «времени операции», его роль играют `connectTimeoutMs` (dial),
> `readTimeoutMs` (idle-read), `writeTimeoutMs` (write) — ровно как у
> `SocketServer`, у которого тоже нет per-message-таймаута.

v1 — **только plain TCP**: без TLS, без unix-сокетов, без keep-alive-пула
(каждый `connect` — новое соединение). См. §9.

## 6. Вынос общего кода (решение 1: вынести и переиспользовать)

Решение: **выделить общую логику в нейтральные пакеты; `SocketServer` и
`SocketClient` зависят только от общего, но НЕ друг от друга** (фичи не связаны).
Проект уже так делает (`ServerRuntimeSupportTrait`, `helpers.ReadChunk`).

**Go — новый нейтральный пакет `ext/internal/socket/`** (не под `features/`, т.к.
у него нет своего `Method` — это общая инфраструктура, как `internal/helpers`):

- `frame.go` — кодек `ReadFrame`/`WriteFrame` (перенести из
  `ext/internal/features/socketserver/frame.go`, экспортировать). Чистый, уже
  покрыт тестами — переносятся вместе.
- `message_state.go` — `MessageState` (стриминг входящих фреймов), параметризуется
  `conn`/`reader`/таймаутами; одинаков для accept- и dial-соединений (из
  `socketserver/message_state.go`).
- `write_loop.go` — `WriteCommand`/`PendingConnection`/`ConsumeCommands`,
  `OpFrame`/`OpClose` (из `socketserver/server.go`).

`socketserver` переключается на `internal/socket` (правка существующего кода,
покрыта `make ext-test`); `socketclient` импортирует тот же пакет. Связи
`socketserver ↔ socketclient` нет.

**PHP — нейтральный общий базовый класс**
`src/Features/Socket/Dto/AbstractConnection.php` (namespace
`SConcur\Features\Socket\Dto`). Несёт `id`/`remoteAddr`/`localAddr`, состояние
`closed`/`inboundEnded`, готовый `read()` (ключ `id:in`), `isClosed()` и
абстрактные хуки записи/закрытия:

```php
abstract protected function pushFrame(string $data): void;     // exec(SendPayload) / RespondPayload::frame
abstract protected function pushClose(): void;                  // exec(ClosePayload) / RespondPayload::close
abstract protected function connectionClosedException(...): RuntimeException; // парное исключение фичи
```

`SocketServer\Dto\Connection` и `SocketClient\Dto\Connection` становятся тонкими
наследниками: каждый подставляет свои payload'ы и своё парное исключение. Фичи
остаются развязанными (общий родитель нейтрален).

## 7. Go-слой (`ext/internal/features/socketclient/`)

| Файл | Роль |
| --- | --- |
| `payloads/payloads.go` | `Envelope` (`cm`/`p`), `ConnectParams` (`ad`/`ct`/`rt`/`wt`/`mmb`), `SendParams` (`cid`/`dt`), `CloseParams` (`cid`), `ConnectionMeta` (`cid`/`ra`/`la`). Теги `msgpack` = ключам PHP, кросс-ссылка `// PHP: SConcur\Features\SocketClient\Payloads\...`. |
| `feature.go` | `SocketClientFeature` (singleton, `sync.Once`+`Get()`), `Handle(task)` → switch по `Envelope.Command`: `Connect`/`Send`/`Close`. |
| `connect.go` | `handleConnect`: dial + регистрация состояния и write-loop (см. ниже). |
| `respond.go` | `handleSend`/`handleClose`: найти соединение в реестре по `cid`, диспетчеризовать `writeCommand` в write-loop, дождаться `done` (backpressure). Образец — `socketserver` `handleRespond`/`dispatch`. |

Реестр живых соединений — `sync.Map` (`cid → *socket.PendingConnection`), как
`serverState.conns` в `socketserver/server.go`. Примитивы фрейминга, inbound-стрим
и write-loop берутся из общего `ext/internal/socket/` (§6).

### `handleConnect` — пошагово

1. Декодировать `ConnectParams`.
2. **Dial с отменой и таймаутом:**
   `ctx, cancel := context.WithTimeout(task.GetContext(), connectTimeout)` →
   `(&net.Dialer{}).DialContext(ctx, "tcp", address)`. Ошибка dial →
   `NewErrorResult` с маркером `net:` (PHP → `ConnectException`).
3. Сгенерировать **новый** `cid` (решение 4) — по образцу сервера
   `nextConnectionId(flowKey)` (`flowKey:c:counter`), не переиспользуя TaskKey.
4. Зарегистрировать **inbound** `messageState` под ключом `cid:in` через
   `states.Get().Register(...)` (deferred, без авточтения первого фрейма — чтение
   ленивое, по `next()`; образец — `httpclient` `startStreamedRequest` с
   `states.Get().Register`).
5. Создать `pendingConnection` (каналы `commands`/`abandoned`), положить в реестр
   по `cid`, запустить goroutine write-loop (`consumeCommands`).
6. Зарегистрировать `context.AfterFunc(task/flow ctx)` для очистки: закрыть
   `conn`, остановить write-loop, удалить из реестра, снять inbound-state
   (образец — `httpclient/upload.go` cleanup на flow-stop + `socketserver`
   per-conn defer).
7. Вернуть первый результат — `ConnectionMeta` (`cid`/`ra`/`la`) обычным
   `NewSuccessResult` (не стриминговым: meta — единичный результат, фреймы идут
   отдельным inbound-стримом).

### `read()` / inbound

`Connection::read()` → `FeatureExecutor::next(taskKey: cid:in)` → `messageState.Next()`
читает следующий length-prefixed фрейм (read deadline из `readTimeoutMs`); чистый
конец (EOF / closed / timeout) → результат без `hasNext` (PHP вернёт `null`).

### `write()` / `close()` / outbound

`Connection::write()` → `exec(SendPayload)` → `handleSend` → `writeCommand{opFrame}`
в write-loop, `writeFrame(conn, data)` с write deadline, ответ в `done`
(backpressure). `close()` → `exec(ClosePayload)` → `writeCommand{opClose}` →
закрыть соединение, снять с реестра. Мёртвое соединение → ошибка →
`ConnectionClosedException` на PHP.

### Регистрация и shutdown

- `ext/internal/features/factory.go`: `case types.MethodSocketClient: return socketclient_feature.Get(), nil`.
- Глобального idle-пула нет (каждый connect — своё соединение), поэтому в
  `features.Shutdown()` правок не нужно; очистка — через AfterFunc по флоу.

## 8. Тесты (обязательно)

**PHP** (`tests/feature/Features/SocketClient/`) — цель запросов — реальный
`SocketServer` SConcur через готовый помощник
`tests/impl/SocketServer/TestSocketServer.php` (echo-сервер
`tests/servers/socket/socket-server.php`):

- `SocketClientTest` (от `BaseTestCase`) — краевые/синхронные случаи: успешный
  `connect` + echo round-trip; `read()` возвращает `null` на закрытии сервером;
  `write()` после `close()` → `ConnectionClosedException`; `connect` на закрытый
  порт → `ConnectException`; бинарно-безопасные фреймы; `maxMessageBytes`.
- `SocketClientConcurrencyTest` (от `BaseAsyncTestCase`) — контракт
  конкурентности: два соединения через `WaitGroup`, проверка порядка событий и
  что общее время ≈ самого медленного (образец —
  `HttpClient\HttpClientConcurrencyTest`). Реализовать хуки `on_1_*`/`on_2_*`,
  `on_iterate`, `on_exception`/`assertException`, `assertResult`.

**Go** (`ext/internal/features/socketclient/..._test.go`) — на `net.Listener` в
тесте: успешный dial + meta, стриминг входящих фреймов, write/backpressure,
классификация ошибки dial (`net:`), `Close`/отмена контекста. Если кодек вынесен в
`socket/` — перенести и его тест.

## 9. Чего нет в v1

| Что | Комментарий |
| --- | --- |
| TLS | позже опцией (`verifyTls`/handshake-timeout, как у `HttpClient`). |
| Unix-сокеты | только TCP (как у `SocketServer`). |
| Пул/keep-alive соединений | каждый `connect` — новое соединение. |
| Авто-reconnect | на стороне приложения. |

## 10. Версия расширения

Протокол меняется (новый `Method` + команды) → бумп **один раз на ветке**
`feature/socket-client`. Текущая — `0.2.4` (`ext/main.go:224`,
`src/Connection/Extension.php:37`). Решение 2: **минорный** бамп → `0.3.0`
(`version()` в `ext/main.go` и `REQUIRED_EXTENSION_VERSION` в
`src/Connection/Extension.php` — вместе). Major не трогаем без согласования.

## 11. Чеклист реализации

PHP:
- [ ] `MethodEnum::SocketClient = 10`.
- [ ] `SocketClientCommandEnum` (Connect/Send/Close).
- [ ] `SocketClientOptions` (`readonly`, дефолты зеркалят Go).
- [ ] Payloads (`Base` конверт + `Connect`/`Send`/`Close` + parameters) с
      кросс-ссылками `Go: payloads.<Type>`.
- [ ] Общий `Features/Socket/Dto/AbstractConnection` (§6); `SocketServer\Dto\Connection`
      переключить на него.
- [ ] `SocketClient::connect()` + `SocketClient\Dto\Connection` (тонкий наследник).
- [ ] Исключения `SocketClient\SocketClientConnectException` (+ маппинг `net:`) и
      `SocketClientConnectionClosedException`; **переименовать** серверный
      `SocketServer\ConnectionClosedException` → `SocketServerConnectionClosedException`.
- [ ] Тесты: `SocketClientTest` (BaseTestCase) + `SocketClientConcurrencyTest`
      (BaseAsyncTestCase).
- [ ] Бамп версии `0.3.0` (§10).

Go:
- [ ] `types/method.go` + `types/socketclient.go` (команды).
- [ ] Общий пакет `internal/socket/` (frame + MessageState + write-loop),
      `socketserver` переключить на него + перенести его тесты.
- [ ] `features/socketclient/`: `payloads.go`, `feature.go`, `connect.go`,
      `respond.go`.
- [ ] Регистрация в `features/factory.go`.
- [ ] Go-тесты + `version()` → `0.3.0`.

Документация:
- [ ] `docs/socket-client.ru.md` (по образцу `docs/socket-server.ru.md` /
      `docs/http-client.ru.md`) + ссылки в `.ai/README.md` и `README.md`.
- [ ] (опц.) бенчмарк `tests/benchmarks/socket-client.php`.

Финальная проверка:
`make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test`.

## 12. Принятые решения

1. **Вынос общего кода** — выделить нейтральные пакеты (`ext/internal/socket/` на
   Go, `Features/Socket/Dto/AbstractConnection` на PHP); `SocketServer` и
   `SocketClient` зависят только от общего, друг от друга — нет (§6).
2. **Версия** — минор `0.3.0` (§10).
3. **Исключения** — парные, с префиксом фичи в имени класса:
   `SocketClientConnectException` / `SocketClientConnectionClosedException`;
   серверное переименовать в `SocketServerConnectionClosedException` (§4).
4. **`cid`** — генерировать новый (`flowKey:c:counter`), не переиспользовать TaskKey
   (§7).
