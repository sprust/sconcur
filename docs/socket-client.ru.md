# Сокет-клиент (TCP)

Асинхронный TCP-клиент с фреймингом length-prefix — зеркальная пара к
[сокет-серверу](socket-server.ru.md), как [HTTP-клиент](http-client.ru.md) — пара к
HTTP-серверу. Весь сетевой I/O (DNS, dial, чтение, запись) живёт в Go-расширении:
`connect()` уходит в горутину, корутина (Fiber) приостанавливается, поэтому
десятки соединений набираются «веером». Вне `WaitGroup` тот же API работает
синхронно (см. [README → Применение](../README.md)).

Модель — долгоживущее двунаправленное соединение (а не «запрос-ответ»):
приложение набирает соединение, получает объект `Connection` и само ведёт диалог —
`read()` тянет входящие фреймы, `write()` пушит исходящие, `close()` закрывает.

## Содержание

- [Фрейминг](#фрейминг)
- [Быстрый старт](#быстрый-старт)
- [Connection: read / write / close](#connection-read--write--close)
- [Конкурентность («веер»)](#конкурентность-веер)
- [Параметры и таймауты](#параметры-и-таймауты)
- [Обработка ошибок](#обработка-ошибок)
- [Внутреннее устройство](#внутреннее-устройство)
- [Чего нет в v1](#чего-нет-в-v1)
- [Тестирование](#тестирование)

## Фрейминг

Поток байтов соединения нарезается на фреймы по схеме **length-prefix**: `uint32`
big-endian длина payload, затем сам payload. Тот же формат в обе стороны, бинарно
безопасно, с естественным лимитом `maxMessageBytes`. Это ровно тот же кодек, что у
сокет-сервера (общий код на Go — пакет `ext/internal/socket`), поэтому SConcur-клиент
и SConcur-сервер совместимы «из коробки».

## Быстрый старт

```php
use SConcur\Features\SocketClient\SocketClient;

$client = new SocketClient();

$connection = $client->connect('127.0.0.1:9100');

$connection->write('ping');
$reply = $connection->read();          // ?string

$connection->close();
```

`connect()` возвращает открытое `Connection`. Лучше вести весь диалог внутри той
же корутины, что и `connect()`: при завершении корутины её флоу останавливается и
недочитанное соединение на Go-стороне закрывается (та же оговорка, что у
`HttpClient`/`SocketServer`).

## Connection: read / write / close

`Connection` (`src/Features/SocketClient/Dto/Connection.php`, общий базовый класс —
`src/Features/Socket/Dto/AbstractConnection.php`):

| Член | Описание |
| --- | --- |
| `read(): ?string` | следующий входящий фрейм; `null` — пир закрыл свою сторону (EOF), соединение завершено или входной лимит превышен. Кооперативно приостанавливает корутину до прихода фрейма |
| `write(string $data): void` | запушить фрейм пиру (с backpressure: ждёт флаша). Бросает `SocketClientConnectionClosedException`, если соединение разорвано |
| `close(): void` | закрыть соединение (идемпотентно, best-effort) |
| `isClosed(): bool` | закрыто ли соединение |
| `id`, `remoteAddr`, `localAddr` | идентификатор и адреса соединения |

Внутри диалога можно делать асинхронные вызовы (Sleeper, Mongodb, SQL, HTTP-клиент)
между чтениями/записями — корутина кооперативно приостанавливается, другие соединения
продолжают работать.

## Конкурентность («веер»)

```php
use SConcur\WaitGroup;

$client    = new SocketClient();
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

/** @var array<int|string, ?string> $replies */
$replies = $waitGroup->waitResults(); // общее время ≈ самого медленного соединения
```

Каждое соединение живёт в своей корутине: `connect/read/write` кооперативно
приостанавливают её, остальные соединения продолжают обслуживаться.

## Параметры и таймауты

`SConcur\Features\SocketClient\SocketClientOptions` (`readonly`), все таймауты в мс.
Дефолты PHP зеркалят Go. У долгоживущего соединения нет единого «времени операции» —
его роль играют таймауты dial/read/write (как у `SocketServer`, у которого тоже нет
per-message-таймаута).

| Параметр | Дефолт | Назначение |
| --- | --- | --- |
| `connectTimeoutMs` | `10000` | предел установки TCP-соединения (dial). |
| `readTimeoutMs` | `0` (выкл) | idle-таймаут ожидания входящего фрейма в `read()`. |
| `writeTimeoutMs` | `30000` | максимум на запись одного фрейма. |
| `maxMessageBytes` | `1048576` (1 MiB) | лимит длины одного входящего фрейма; превышение завершает ввод (`read()` → `null`). |

```php
use SConcur\Features\SocketClient\SocketClientOptions;

$client = new SocketClient(new SocketClientOptions(
    connectTimeoutMs: 5_000,
    readTimeoutMs:    30_000,
    writeTimeoutMs:   10_000,
    maxMessageBytes:  4 * 1024 * 1024,
));
```

## Обработка ошибок

| Случай | Исключение |
| --- | --- |
| Не удалось набрать соединение (refused / DNS-fail / connect-timeout) | `SConcur\Exceptions\SocketClient\SocketClientConnectException` (бросает `connect()`) |
| `write()` в разорванное соединение | `SConcur\Exceptions\SocketClient\SocketClientConnectionClosedException` |
| Пир закрыл соединение / EOF / idle-таймаут / превышен `maxMessageBytes` | не исключение — `read()` возвращает `null` |

Go-сторона помечает сетевые сбои маркером `net:`, и он сохраняется в сообщении
исключения (удобно для логирования/ретраев).

```php
use SConcur\Exceptions\SocketClient\SocketClientConnectException;

try {
    $connection = $client->connect('127.0.0.1:9100');
} catch (SocketClientConnectException $exception) {
    // ретрай / логирование; $exception->getMessage() содержит маркер "net:"
}
```

## Внутреннее устройство

PHP (`src/Features/SocketClient/`):

- `SocketClient` — публичный API: `connect()` собирает `ConnectPayload`, через
  `FeatureExecutor::exec()` набирает соединение, декодирует `ConnectionMeta`
  (`cid`/`ra`/`la`) и строит `Dto\Connection` с ключом входящего стрима = ключу
  результата connect.
- `SocketClientOptions` — `readonly` DTO опций.
- `SocketClientCommandEnum` — под-операции конверта: `Connect`/`Send`/`Close`.
- `Dto\Connection` — тонкий наследник `Features\Socket\Dto\AbstractConnection`
  (общего с сокет-сервером): подставляет `SendPayload`/`ClosePayload` и парное
  исключение.
- `Payloads/` — конверт `Base\BaseSocketClientPayload` (`cm`/`p`) + `Connect`/`Send`/
  `Close` payload'ы, зеркала Go-структур.

Go (`ext/internal/features/socketclient/`):

- `payloads/payloads.go` — `Envelope`, `ConnectParams`, `SendParams`, `CloseParams`,
  `ConnectionMeta` (1:1 с PHP).
- `feature.go` — `SocketClientFeature` (singleton): диспетчер команд; `handleRespond`
  маршрутизирует `Send`/`Close` по `cid` в write-loop соединения.
- `connect.go` — `handleConnect`: dial с `connectTimeout` (отменяемый контекстом
  флоу), регистрация стримингового `connectionState` (первый `Next` — метаданные, далее
  — входящие фреймы) и write-loop; очистка на остановке флоу.

Общий код (`ext/internal/socket/`, нейтральный, не привязан к серверу/клиенту):
кодек фреймов (`frame.go`), стрим входящих фреймов (`MessageState`) и цикл записи с
backpressure (`PendingConnection`/`ConsumeCommands`/`Dispatch`). Им пользуются и
сокет-сервер, и сокет-клиент — но не друг другом.

Чтение входящих фреймов — это `next()` по стриминговому состоянию connect (как тело
ответа у `HttpClient`); запись/закрытие — `exec(Send/Close)` с маршрутизацией по `cid`
в write-loop (как `Respond` у сокет-сервера).

## Чего нет в v1

| Что | Комментарий |
| --- | --- |
| TLS | позже опцией (как у `HttpClient`). |
| Unix-сокеты | только TCP (как у `SocketServer`). |
| Пул / keep-alive соединений | каждый `connect()` — новое соединение. |
| Авто-reconnect | на стороне приложения. |

Общие ограничения библиотеки (только CLI, только Linux, только NTS, нельзя
`pcntl_fork` после загрузки расширения) — см. [README](../README.md).

## Тестирование

- PHP feature-тесты — `tests/feature/Features/SocketClient/`: краевые/ошибочные случаи
  (`SocketClientTest`) и контракт конкурентности на `BaseAsyncTestCase`
  (`SocketClientConcurrencyTest`). Цель — реальный SConcur `SocketServer`
  (`tests/servers/socket/socket-server.php`), поднимаемый через
  `SConcur\Tests\Impl\SocketServer\TestSocketServer`.
- Go-тесты — `ext/internal/socket/` (кодек, `MessageState`, write-loop) и
  `ext/internal/features/socketclient/connect_test.go` (`connectionState`:
  метаданные → входящие фреймы → чистый конец).

- Бенчмарк — `tests/benchmarks/socket-client.php` (`make bench-socket-client`):
  N round-trip'ов к I/O-эндпоинту (`msleep:<ms>`) демо-сервера; async-прогон через
  `WaitGroup` показывает «веер» (общее время ≈ одного round-trip), против
  последовательных native (сырые PHP-сокеты) и sync.

Запуск: `make test c="--filter=SocketClient"`, `make ext-test`,
`make bench-socket-client c=20`.

```
make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test
```
