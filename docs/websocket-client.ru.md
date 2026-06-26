# WebSocket-клиент

Асинхронный WebSocket-клиент — зеркальная пара к [WebSocket-серверу](websocket-server.ru.md),
как [сокет-клиент](socket-client.ru.md) — пара к сокет-серверу. Весь сетевой I/O (dial,
рукопожатие апгрейда, чтение, запись) живёт в Go-расширении: `connect()` уходит в
горутину, корутина (Fiber) приостанавливается, поэтому десятки соединений набираются
«веером». Вне `WaitGroup` тот же API работает синхронно (см.
[README → Применение](../README.md)).

Модель — долгоживущее двунаправленное соединение: приложение набирает соединение,
получает объект `Connection` и само ведёт диалог — `read()` тянет входящие сообщения,
`write()` шлёт исходящие (text или binary), `close()` закрывает.

## Содержание

- [Быстрый старт](#быстрый-старт)
- [Connection: read / write / close](#connection-read--write--close)
- [Конкурентность («веер»)](#конкурентность-веер)
- [Параметры и таймауты](#параметры-и-таймауты)
- [Обработка ошибок](#обработка-ошибок)
- [Внутреннее устройство](#внутреннее-устройство)
- [Чего нет в v1](#чего-нет-в-v1)
- [Тестирование](#тестирование)

## Быстрый старт

```php
use SConcur\Features\WsClient\WsClient;

$client = new WsClient();

$connection = $client->connect('ws://127.0.0.1:9200/');

$connection->write('ping');
$reply = $connection->read();          // ?string

$connection->close();
```

`connect()` принимает полный `ws://host:port/path` URL и возвращает открытое
`Connection`. Лучше вести весь диалог внутри той же корутины, что и `connect()`: при
завершении корутины её флоу останавливается и недочитанное соединение на Go-стороне
закрывается (та же оговорка, что у `HttpClient`/`SocketClient`).

## Connection: read / write / close

`Connection` (`src/Features/WsClient/Dto/Connection.php`, общий базовый класс —
`src/Features/Socket/Dto/AbstractConnection.php`):

| Член | Описание |
| --- | --- |
| `read(): ?string` | следующее входящее сообщение; `null` — пир закрыл свою сторону, соединение завершено или превышен `maxMessageBytes`. Кооперативно приостанавливает корутину до прихода сообщения |
| `write(string $data, bool $binary = false): void` | отправить сообщение пиру (с backpressure: ждёт флаша). По умолчанию text, `binary: true` — бинарное. Бросает `WsClientConnectionClosedException`, если соединение разорвано |
| `lastMessageWasBinary(): bool` | был ли последний прочитанный `read()` бинарным (иначе text) |
| `close(): void` | закрыть соединение (идемпотентно, best-effort) |
| `isClosed(): bool` | закрыто ли соединение |
| `id`, `remoteAddr`, `localAddr`, `subprotocol` | идентификатор, адреса и согласованный subprotocol |

Внутри диалога можно делать асинхронные вызовы (Sleeper, Mongodb, SQL, HTTP-клиент)
между чтениями/записями — корутина кооперативно приостанавливается, другие соединения
продолжают работать.

## Конкурентность («веер»)

```php
use SConcur\WaitGroup;

$client    = new WsClient();
$waitGroup = WaitGroup::create();

foreach ($urls as $url) {
    $waitGroup->add(function () use ($client, $url) {
        $connection = $client->connect($url);

        $connection->write('hello');
        $reply = $connection->read();

        $connection->close();

        return $reply;
    });
}

/** @var array<int|string, ?string> $replies */
$replies = $waitGroup->waitResults(); // общее время ≈ самого медленного соединения
```

Каждое соединение живёт в своей корутине: `connect/read/write` кооперативно
приостанавливают её, остальные соединения продолжают обслуживаться.

## Параметры и таймауты

`SConcur\Features\WsClient\WsClientOptions` (`readonly`), все таймауты в мс. Дефолты PHP
зеркалят Go. У долгоживущего соединения нет единого «времени операции» — его роль играют
таймауты dial/read/write (как у `WsServer`, у которого тоже нет per-message-таймаута).

| Параметр | Дефолт | Назначение |
| --- | --- | --- |
| `connectTimeoutMs` | `10000` | предел установки соединения (dial + рукопожатие) |
| `readTimeoutMs` | `0` (выкл) | idle-таймаут ожидания входящего сообщения в `read()` |
| `writeTimeoutMs` | `30000` | максимум на отправку одного сообщения |
| `maxMessageBytes` | `1048576` (1 MiB) | лимит размера одного входящего сообщения; превышение завершает ввод (`read()` → `null`) |
| `subprotocols` | `[]` | WebSocket-subprotocol'ы, предлагаемые в рукопожатии |

```php
use SConcur\Features\WsClient\WsClientOptions;

$client = new WsClient(new WsClientOptions(
    connectTimeoutMs: 5_000,
    readTimeoutMs:    30_000,
    writeTimeoutMs:   10_000,
    maxMessageBytes:  4 * 1024 * 1024,
    subprotocols:     ['chat'],
));
```

## Обработка ошибок

| Случай | Исключение |
| --- | --- |
| Не удалось набрать соединение (refused / DNS-fail / connect-timeout / отказ апгрейда) | `SConcur\Exceptions\WsClient\WsClientConnectException` (бросает `connect()`) |
| `write()` в разорванное соединение | `SConcur\Exceptions\WsClient\WsClientConnectionClosedException` |
| Пир закрыл соединение / idle-таймаут / превышен `maxMessageBytes` | не исключение — `read()` возвращает `null` |

Go-сторона помечает сетевые сбои маркером `net:`, и он сохраняется в сообщении
исключения (удобно для логирования/ретраев).

```php
use SConcur\Exceptions\WsClient\WsClientConnectException;

try {
    $connection = $client->connect('ws://127.0.0.1:9200/');
} catch (WsClientConnectException $exception) {
    // ретрай / логирование; $exception->getMessage() содержит маркер "net:"
}
```

## Внутреннее устройство

PHP (`src/Features/WsClient/`):

- `WsClient` — публичный API: `connect()` собирает `ConnectPayload`, через
  `FeatureExecutor::exec()` набирает соединение, декодирует `ConnectionMeta`
  (`cid`/`ra`/`la`/`su`) и строит `Dto\Connection` с ключом входящего стрима = ключу
  результата connect.
- `WsClientOptions` — `readonly` DTO опций.
- `WsClientCommandEnum` — под-операции конверта: `Connect`/`Send`/`Close`.
- `Dto\Connection` — наследник `Features\Socket\Dto\AbstractConnection`: `read()` снимает
  однобайтовый маркер типа (text/binary), `write()` несёт тип сообщения через `SendPayload`,
  плюс парное исключение.
- `Payloads/` — конверт `Base\BaseWsClientPayload` (`cm`/`p`) + `Connect`/`Send`/`Close`
  payload'ы, зеркала Go-структур.

Go (`ext/internal/features/wsclient/`):

- `payloads/payloads.go` — `Envelope`, `ConnectParams`, `SendParams`, `CloseParams`,
  `ConnectionMeta` (1:1 с PHP).
- `feature.go` — `WsClientFeature` (singleton): диспетчер команд; `Send`/`Close`
  маршрутизируются по `cid` в write-loop соединения.
- `connect.go` — `handleConnect`: `websocket.Dial` с `connectTimeout` (отменяемый
  контекстом флоу), регистрация стримингового `connectionState` (первый `Next` —
  метаданные, далее — входящие сообщения) и write-loop; очистка на остановке флоу.

Общий код (`ext/internal/ws/`, нейтральный, не привязан к серверу/клиенту): цикл записи
с backpressure (`PendingConnection`/`ConsumeCommands`/`Dispatch`) и кодек типа сообщения
(`EncodeInbound`/`MessageTypeFromCode`). Им пользуются и WS-сервер, и WS-клиент — но не
друг другом (как `ext/internal/socket` у пары сокет-сервер/клиент).

## Чего нет в v1

| Что | Комментарий |
| --- | --- |
| TLS (`wss://`) | позже опцией (как у `HttpClient`) |
| `permessage-deflate` | библиотека умеет, пока не включено |
| Пул / keep-alive соединений | каждый `connect()` — новое соединение |
| Авто-reconnect | на стороне приложения |

Общие ограничения библиотеки (только CLI, только Linux, только NTS, нельзя
`pcntl_fork` после загрузки расширения) — см. [README](../README.md).

## Тестирование

- PHP feature-тесты — `tests/feature/Features/WsClient/`: краевые/ошибочные случаи
  (`WsClientTest`) и контракт конкурентности на `BaseAsyncTestCase`
  (`WsClientConcurrencyTest`). Цель — реальный SConcur `WsServer`
  (`tests/servers/ws/ws-server.php`), поднимаемый через
  `SConcur\Tests\Impl\WsServer\TestWsServer`.
- Go-тесты — `ext/internal/features/wsclient/connect_test.go` (`connectionState`:
  метаданные → входящие сообщения → чистый конец).

Запуск: `make test c="--filter=WsClient"`, `make ext-test`.

```
make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test
```
