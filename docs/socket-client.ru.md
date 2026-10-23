[English](socket-client.md) | Русский

# Сокет-клиент (TCP)

Асинхронный TCP-клиент с фреймингом length-prefix — зеркало
[сокет-сервера](socket-server.ru.md) со стороны dial, как
[HTTP-клиент](http-client.ru.md) — пара к HTTP-серверу. Весь сетевой I/O (DNS,
dial, чтение, запись) живёт в расширении: `connect()` уходит в задачу рантайма,
корутина (PHP-файбер, который планировщик приостанавливает, пока работает
расширение) приостанавливается, поэтому десятки соединений можно поднимать
одновременно. Вне `WaitGroup` тот же API работает синхронно.

Модель — долгоживущее двунаправленное соединение, а не «запрос-ответ»: приложение
дозванивается, получает `Connection` и само ведёт диалог. Кодек фрейминга общий с
сокет-сервером (`ext/src/socket/`), поэтому клиент и сервер SConcur совместимы
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

Соединение принадлежит флоу, в котором открыто, — группе задач, которой
`WaitGroup` владеет на стороне расширения (у обработчика сервера она своя). Когда
флоу заканчивается (`waitResults()` вернулся, группу остановили, обработчик
завершился), ещё открытое соединение закрывается, поэтому доводите диалог до конца
внутри группы, вызвавшей `connect()`.

## Connection: read / write / close

`Connection` (`src/Features/SocketClient/Dto/Connection.php`, общий базовый класс —
`src/Features/Socket/Dto/AbstractConnection.php`):

| Член | Описание |
| --- | --- |
| `read(): ?string` | следующий входящий кадр; `null` — другая сторона закрыла свою половину (EOF), соединение завершилось или превышен лимит входа. Кооперативно приостанавливает корутину |
| `write(string $data): void` | отправить кадр другой стороне; ждёт, пока кадр реально не будет сброшен в сеть, поэтому быстрый писатель не обгоняет получателя. Бросает `SocketClientConnectionClosedException`, если соединение порвано |
| `close(): void` | закрыть соединение (идемпотентно; если соединение уже разорвано, ничего не делает) |
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
мс; значения по умолчанию в PHP совпадают со значениями расширения.

| Параметр | По умолчанию | Назначение |
| --- | --- | --- |
| `connectTimeoutMs` | `10000` | предел установления TCP-соединения (dial) |
| `readTimeoutMs` | `0` (выкл.) | таймаут простоя при ожидании входящего кадра в `read()` |
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
| Не удалось дозвониться (соединение отклонено, ошибка DNS, таймаут подключения) | `SConcur\Exceptions\SocketClient\SocketClientConnectException`, бросает `connect()` |
| `write()` в порванное соединение | `SConcur\Exceptions\SocketClient\SocketClientConnectionClosedException` |
| Другая сторона закрыла соединение / EOF / таймаут простоя / превышен `maxMessageBytes` | не исключение — `read()` возвращает `null` |

Сообщение об ошибке дозвона сохраняет маркер расширения `net:`.

## Внутреннее устройство

PHP (`src/Features/SocketClient/`): `SocketClient::connect()` собирает
`ConnectPayload`, дозванивается через `FeatureExecutor::exec()`, декодирует
`ConnectionMeta` (`cid`/`ra`/`la`) и строит `Dto\Connection`, у которого ключ
входящего потока — ключ результата connect. `Dto\Connection` — тонкий наследник
`Features\Socket\Dto\AbstractConnection` (общего с сокет-сервером), подставляющий
`SendPayload`/`ClosePayload` и парное исключение; `SocketClientCommandEnum` и
`Payloads/` — конверт `Connect`/`Send`/`Close`, зеркало структур расширения.

Rust (`ext/src/features/socketclient/`): путь подключения дозванивается в пределах
`connect_timeout_ms` и регистрирует стриминговый `ConnectionState` — первый
`next()` даёт метаданные, дальше идут входящие кадры — плюс цикл записи, очищаемый
при остановке флоу; `mod.rs` диспетчеризует команды, маршрутизируя `Send`/`Close`
по `cid` в этот цикл. Кодек кадров, `MessageState` и `dispatch` — передача, которая
ждёт, пока цикл записи сообщит, что кадр записан, — живут в нейтральном
`ext/src/socket/`, общем с сокет-сервером.

## Не поддерживается

TLS (позже, опцией), unix-сокеты (только TCP), пул соединений и keep-alive (каждый
`connect()` — новое соединение) и авто-переподключение (на стороне приложения).
Общие ограничения библиотеки — см. [README](../README.ru.md).

## Тестирование

PHP feature-тесты лежат в `tests/feature/Features/SocketClient/` — граничные случаи и
ошибки плюс контракт конкурентности на `BaseAsyncTestCase`, против
реального `SocketServer` SConcur, поднятого через `TestSocketServer`.
Собственные unit-тесты ядра покрывают общий модуль `ext/src/socket/`. Бенчмарк
(`make bench-socket-client c=20`) гоняет N обменов «запрос — ответ» с эндпоинтом
`msleep` демо-сервера: одновременный async против последовательных native (сырые
сокеты PHP) и sync.

Запуск: `make test c="--filter=SocketClient"`, `make ext-test`.
