[English](websocket-client.md) | Русский

# WebSocket-клиент

Асинхронный WebSocket-клиент — зеркало
[WebSocket-сервера](websocket-server.ru.md) со стороны dial, как
[сокет-клиент](socket-client.ru.md) — пара к сокет-серверу. Весь сетевой I/O
(dial, рукопожатие апгрейда, чтение, запись) живёт в расширении: `connect()`
уходит в задачу рантайма, корутина приостанавливается, поэтому десятки соединений можно
поднимать одновременно. Вне `WaitGroup` тот же API работает синхронно.

Модель — долгоживущее двунаправленное соединение: приложение дозванивается,
получает `Connection` и само ведёт разговор.

## Быстрый старт

```php
use SConcur\Features\WsClient\WsClient;

$client = new WsClient();

$connection = $client->connect('ws://127.0.0.1:9200/');

$connection->write('ping');
$reply = $connection->read();          // ?string

$connection->close();
```

`connect()` принимает URL `ws://host:port/path`; поддерживается только `ws://`,
порт по умолчанию — `80`, путь — `/`. Весь разговор лучше вести внутри той же
корутины, что и `connect()`: когда корутина завершается, её флоу (группа запущенных
ею задач) останавливается и недочитанное соединение на стороне расширения
закрывается (та же оговорка, что у `HttpClient`/`SocketClient`).

## Connection: read / write / close

`Connection` (`src/Features/WsClient/Dto/Connection.php`, общий базовый класс —
`src/Features/Socket/Dto/AbstractConnection.php`):

| Член | Описание |
| --- | --- |
| `read(): ?string` | следующее входящее сообщение; `null` — другая сторона закрыла свою половину, соединение завершилось или превышен `maxMessageBytes`. Кооперативно приостанавливает корутину |
| `write(string $data, bool $binary = false): void` | отправить сообщение; ждёт, пока оно реально не будет сброшено в сеть, поэтому быстрый писатель не обгоняет получателя. По умолчанию текст. Бросает `WsClientConnectionClosedException`, если соединения уже нет |
| `lastMessageWasBinary(): bool` | было ли последнее `read()` бинарным |
| `close(): void` | закрыть соединение (идемпотентно; если соединение уже разорвано, ничего не делает) |
| `isClosed(): bool` | закрыто ли соединение |
| `id`, `remoteAddr`, `localAddr`, `subprotocol` | идентификатор, удалённый и локальный `ip:port` TCP-соединения и согласованный субпротокол (пусто, если его нет) |

Между чтениями и записями можно делать асинхронные вызовы (Sleeper, Mongodb, SQL,
HTTP-клиент) — корутина приостанавливается кооперативно, другие соединения
продолжают работать.

## Параллельная работа с соединениями

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
$replies = $waitGroup->waitResults(); // суммарное время ≈ самому медленному соединению
```

## Параметры и таймауты

`SConcur\Features\WsClient\WsClientOptions` (`readonly`), все таймауты в мс;
значения по умолчанию в PHP совпадают со значениями расширения. У долгоживущего
соединения нет единого «времени операции» — эту роль играют таймауты dial/read/write.

| Параметр | По умолчанию | Назначение |
| --- | --- | --- |
| `connectTimeoutMs` | `10000` | предел установления соединения (dial + рукопожатие) |
| `readTimeoutMs` | `0` (выкл.) | таймаут простоя при ожидании входящего сообщения в `read()` |
| `writeTimeoutMs` | `30000` | максимальное время отправки одного сообщения |
| `maxMessageBytes` | `1048576` (1 MiB) | лимит размера входящего сообщения; превышение завершает вход (`read()` → `null`) |
| `subprotocols` | `[]` | субпротоколы WebSocket, предлагаемые в рукопожатии |

```php
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
| Не удалось дозвониться (соединение отклонено, ошибка DNS, таймаут подключения, отказ в апгрейде, адрес не `ws://`) | `SConcur\Exceptions\WsClient\WsClientConnectException`, бросает `connect()` |
| `write()` в порванное соединение | `SConcur\Exceptions\WsClient\WsClientConnectionClosedException` |
| Другая сторона закрыла соединение / таймаут простоя / превышен `maxMessageBytes` | не исключение — `read()` возвращает `null` |

Расширение помечает сетевые сбои маркером `net:`, и он сохраняется в сообщении
исключения.

## Внутреннее устройство

PHP (`src/Features/WsClient/`): `WsClient::connect()` собирает `ConnectPayload`,
дозванивается через `FeatureExecutor::exec()`, декодирует `ConnectionMeta`
(`cid`/`ra`/`la`/`su`) и строит `Dto\Connection`, у которого ключ входящего потока
— ключ результата connect. Этот `Dto\Connection` наследует
`Features\Socket\Dto\AbstractConnection`: `read()` снимает однобайтовый маркер типа
(text/binary), а `write()` несёт тип сообщения через `SendPayload`.
`WsClientCommandEnum` и `Payloads/` — конверт `Connect`/`Send`/`Close`, зеркало
структур расширения.

Rust (`ext/src/features/wsclient/`): путь подключения выполняет апгрейд с
`connect_timeout_ms` (отменяем контекстом флоу) и регистрирует стриминговый
`ConnectionState` — первый `next()` даёт метаданные, дальше идут входящие
сообщения из задачи рантайма чтения — плюс цикл записи, очищаемый при остановке
флоу; `mod.rs` диспетчеризует команды по `cid`. Цикл записи, ждущий сброса
каждого сообщения, и кодек типа сообщения живут в нейтральном `ext/src/ws/`,
общем с WS-сервером (как `ext/src/socket/` для пары сырых TCP).

## Чего нет в v1

TLS (`wss://`), `permessage-deflate` (библиотека умеет, пока не включено), пул
соединений и keep-alive (каждый `connect()` — новое соединение) и
авто-переподключение (на стороне приложения). Общие ограничения библиотеки — см.
[README](../README.ru.md).

## Тестирование

PHP feature-тесты лежат в `tests/feature/Features/WsClient/` — граничные случаи и
ошибки плюс контракт конкурентности на `BaseAsyncTestCase`, против реального
`WsServer` SConcur, поднятого через `TestWsServer`; сторону расширения покрывают
собственные unit-тесты ядра. Бенчмарк (`make bench-ws-client c=20`) гоняет N обменов
«запрос — ответ» с эндпоинтом `msleep` демо-сервера: одновременный async против
последовательных native (сырой WS-фрейминг на PHP) и sync; серверные бенчмарки
пула — `make bench-ws-server-io` / `bench-ws-server-cpu` / `bench-ws-throughput`.

Запуск: `make test c="--filter=WsClient"`, `make ext-test`.
