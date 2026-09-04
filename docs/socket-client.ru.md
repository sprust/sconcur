[English](socket-client.md) | Русский

# Сокет-клиент (TCP)

Асинхронный TCP-клиент с фреймингом length-prefix — зеркало
[сокет-сервера](socket-server.ru.md) со стороны dial, как
[HTTP-клиент](http-client.ru.md) — пара к HTTP-серверу. Весь сетевой I/O (DNS,
dial, чтение, запись) живёт в расширении: `connect()` уходит в задачу рантайма,
корутина приостанавливается, поэтому десятки соединений можно поднимать
одновременно. Вне `WaitGroup` тот же API работает синхронно.

Модель — долгоживущее двунаправленное соединение, а не «запрос-ответ»: приложение
дозванивается, получает `Connection` и само ведёт диалог. Кодек фрейминга общий с
сокет-сервером (`ext-go-legacy/internal/socket`), поэтому клиент и сервер SConcur совместимы
из коробки.

## Быстрый старт

```php
use SConcur\Features\SocketClient\SocketClient;

$client = new SocketClient();

$connection = $client->connect('127.0.0.1:9100');

$connection->write('ping');
$reply = $connection->read();          // ?string

$connection->close();
```

Весь диалог лучше вести внутри той же корутины, что и `connect()`: когда корутина
завершается, её флоу останавливается и незавершённое соединение на стороне расширения
закрывается (та же оговорка, что у `HttpClient`/`SocketServer`).

## Connection: read / write / close

`Connection` (`src/Features/SocketClient/Dto/Connection.php`, общий базовый класс —
`src/Features/Socket/Dto/AbstractConnection.php`):

| Член | Описание |
| --- | --- |
| `read(): ?string` | следующий входящий кадр; `null` — пир закрыл свою сторону (EOF), соединение завершилось или превышен лимит входа. Кооперативно приостанавливает корутину |
| `write(string $data): void` | отправить кадр пиру; ждёт, пока кадр реально не будет сброшен в сеть, поэтому быстрый писатель не обгоняет пира. Бросает `SocketClientConnectionClosedException`, если соединение порвано |
| `close(): void` | закрыть соединение (идемпотентно, best-effort) |
| `isClosed(): bool` | закрыто ли соединение |
| `id`, `remoteAddr`, `localAddr` | идентификатор и адреса |

Между чтениями и записями можно делать асинхронные вызовы (Sleeper, Mongodb, SQL,
HTTP-клиент) — корутина приостанавливается кооперативно, другие соединения
продолжают работать.

## Параллельная работа с соединениями

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
$replies = $waitGroup->waitResults(); // суммарное время ≈ самому медленному соединению
```

## Параметры и таймауты

`SConcur\Features\SocketClient\SocketClientOptions` (`readonly`), все таймауты в
мс; дефолты PHP зеркалят дефолты расширения. У долгоживущего соединения нет единого «времени
операции» — эту роль играют таймауты dial/read/write.

| Параметр | Дефолт | Назначение |
| --- | --- | --- |
| `connectTimeoutMs` | `10000` | предел установления TCP-соединения (dial) |
| `readTimeoutMs` | `0` (выкл.) | idle-таймаут ожидания входящего кадра в `read()` |
| `writeTimeoutMs` | `30000` | максимальное время записи одного кадра |
| `maxMessageBytes` | `1048576` (1 MiB) | лимит длины входящего кадра; превышение завершает вход (`read()` → `null`) |

```php
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
| Не удалось дозвониться (refused / DNS-fail / connect-timeout) | `SConcur\Exceptions\SocketClient\SocketClientConnectException`, бросает `connect()` |
| `write()` в порванное соединение | `SConcur\Exceptions\SocketClient\SocketClientConnectionClosedException` |
| Пир закрыл соединение / EOF / idle-таймаут / превышен `maxMessageBytes` | не исключение — `read()` возвращает `null` |

Расширение помечает сетевые сбои маркером `net:`, и он сохраняется в сообщении
исключения (удобно для логов и ретраев).

## Внутреннее устройство

PHP (`src/Features/SocketClient/`): `SocketClient::connect()` собирает
`ConnectPayload`, дозванивается через `FeatureExecutor::exec()`, декодирует
`ConnectionMeta` (`cid`/`ra`/`la`) и строит `Dto\Connection`, у которого ключ
входящего потока — ключ результата connect. `Dto\Connection` — тонкий наследник
`Features\Socket\Dto\AbstractConnection` (общего с сокет-сервером), подставляющий
`SendPayload`/`ClosePayload` и парное исключение; `SocketClientCommandEnum` и
`Payloads/` — конверт `Connect`/`Send`/`Close`, зеркало структур расширения.

Rust (`ext/src/features/socketclient/`): путь подключения дозванивается с
`connectTimeout` (отменяем контекстом флоу) и регистрирует стриминговый
`connectionState` — первый `Next` даёт метаданные, дальше идут входящие кадры —
плюс цикл записи, очищаемый при остановке флоу; `feature.go` диспетчеризует
команды, маршрутизируя `Send`/`Close` по `cid` в этот цикл. Кодек кадров,
`MessageState` и цикл записи, ждущий сброса каждого кадра, живут в нейтральном
`ext-go-legacy/internal/socket/`, общем с сокет-сервером.

То есть чтение входящих кадров — это `next()` по стриминговому состоянию connect
(как тело ответа у `HttpClient`), а запись и закрытие — `exec(Send/Close)` с
маршрутизацией по `cid` (как `Respond` у сервера).

## Чего нет в v1

TLS (позже, опцией), unix-сокеты (только TCP), пул соединений и keep-alive (каждый
`connect()` — новое соединение) и авто-переподключение (на стороне приложения).
Общие ограничения библиотеки — см. [README](../README.ru.md).

## Тестирование

PHP feature-тесты лежат в `tests/feature/Features/SocketClient/` — edge- и
error-случаи плюс контракт конкурентности на `BaseAsyncTestCase`, против
реального `SocketServer` SConcur, поднятого через `TestSocketServer`. Тесты ядра
покрывают общий пакет `ext-go-legacy/internal/socket/` и `connect_test.go`. Бенчмарк
(`make bench-socket-client c=20`) гоняет N round-trip'ов к эндпоинту `msleep`
демо-сервера: одновременный async против последовательных native (сырые сокеты
PHP) и sync.

Запуск: `make test c="--filter=SocketClient"`, `make ext-test`.
