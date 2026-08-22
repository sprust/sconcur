[English](socket-server.md) | Русский

# Сокет-сервер (TCP)

Долгоживущий TCP-сервер: сеть живёт в Go-расширении, каждое принятое соединение
стримится в PHP и обрабатывается в своей корутине. Модель push — обработчик
получает объект соединения и сам ведёт диалог: читает входящие кадры и
отправляет кадры клиенту в любой момент, а не отвечает «одно сообщение — один
ответ».

Образец при проектировании — [HTTP-сервер](http-server.ru.md): сокет-сервер
переиспользует его машинерию (самокачающийся поток приёма, `Scheduler::serve`) и
работает под тем же [мастером воркеров](worker-master.ru.md).

## Фрейминг

Байтовый поток режется на кадры схемой length-prefix: `uint32` big-endian длина
полезной нагрузки, затем сама нагрузка — `[len=5]hello[len=3]bye`. Формат одинаков
в обе стороны: бинарно-безопасный, без экранирования, с естественным лимитом
`maxMessageBytes` на входящие кадры. Клиент кадрирует так же:
`fwrite($connection, pack('N', strlen($data)) . $data)`.

## Быстрый старт

```php
use SConcur\Features\SocketServer\Dto\Connection;
use SConcur\Features\SocketServer\SocketServer;

$server = new SocketServer(address: '0.0.0.0:9100');

$server->serve(static function (Connection $connection): void {
    // echo: читаем кадры и отправляем обратно, пока соединение живо
    while (($frame = $connection->read()) !== null) {
        $connection->write($frame);
    }
});
```

Обработчик — `Closure(Connection): void` — исполняется в корутине соединения и сам
ведёт его жизненный цикл; когда он возвращает управление, соединение закрывается
автоматически.

## Connection: read / write / close

`Connection` (`src/Features/SocketServer/Dto/Connection.php`):

| Член | Описание |
| --- | --- |
| `read(): ?string` | следующий входящий кадр; `null` — клиент закрыл свою сторону (EOF) или соединение завершилось. Кооперативно приостанавливает корутину до прихода кадра |
| `write(string $data): void` | отправить кадр клиенту; ждёт, пока байты реально не будут сброшены в сеть, поэтому быстрый обработчик не обгоняет клиента. Бросает `SocketServerConnectionClosedException`, если соединение порвано |
| `close(): void` | закрыть соединение (идемпотентно, best-effort) |
| `isClosed(): bool` | закрыто ли соединение |
| `id`, `remoteAddr`, `localAddr` | идентификатор и адреса |

Внутри обработчика между чтениями и записями можно делать асинхронные вызовы
(Sleeper, Mongodb, SQL, HTTP-клиент) — корутина приостанавливается кооперативно, а
другие соединения продолжают обслуживаться.

## Server push

Обработчик не обязан отвечать на каждый входящий кадр и может отправлять сколько
угодно кадров, в том числе вообще без входящих:

```php
$server->serve(static function (Connection $connection): void {
    $request = $connection->read();

    for ($i = 0; $i < 10; $i++) {
        $connection->write("update-$i");

        Sleeper::sleep(seconds: 1); // между отправками идёт async-работа
    }
});
```

Пуш в другие соединения (broadcast, чат, pub-sub) не встроен — приложение может
держать ссылки на `Connection` и писать в них само (`write` маршрутизируется по
`id` на Go-стороне через глобальную карту `pendingConnections`).

## Параметры

Конструктор `SocketServer`; дефолты PHP зеркалят Go.

| Параметр | Дефолт | Назначение |
| --- | --- | --- |
| `address` | `0.0.0.0:9100` | адрес прослушивания `host:port` |
| `readTimeoutMs` | `0` (выкл.) | idle-таймаут ожидания входящего кадра в `read()`. Push-обработчика, который не читает, не касается |
| `writeTimeoutMs` | `30000` | максимальное время записи одного кадра клиенту |
| `maxMessageBytes` | `1048576` (1 MiB) | лимит длины одного входящего кадра; превышение завершает вход соединения |
| `maxConcurrency` | `0` (без лимита) | сколько соединений обслуживается одновременно; лишние ждут слот |
| `maxConnections` | `0` (без лимита) | остановить сервер после N обслуженных соединений (защита от утечек) |
| `shutdownTimeoutMs` | `10000` | сколько ждать завершения активных соединений при остановке |
| `reusePort` | `false` | `SO_REUSEPORT` — пул процессов на одном порту (Linux) |
| `onError` | `null` | хук ошибки обработчика |
| `masterPid` | `null` | проверка на сироту под мастером |
| `telemetrySocket` | `''` (выкл.) | unix-сокет для снапшотов статистики, инжектируется мастером ([статистика](admin-stats.ru.md)) |
| `serverName` | `'sconcur-server'` | имя воркера в снапшотах статистики |
| `telemetryIntervalMs` | `0` | период снапшотов (`0` — значение по умолчанию у отправителя, 1000 мс) |
| `preemptionQuantumMs` | `5` | квант автоматической преемпции (`0` — выкл.), см. [переключение корутин](coroutine-switching.ru.md) |

## Конкурентность

Конкурентность — между соединениями: каждое живёт в своей корутине, а каждый
`read()`/`write()` приостанавливает её кооперативно, не блокируя остальные.
`maxConcurrency` ограничивает число одновременно обслуживаемых соединений (слот
держится всю жизнь соединения); лишние принимаются на сокете, но не обрабатываются
до освобождения слота.

> Обработчик, застрявший в нативном вызове (`sleep`, синхронный PDO/`curl`),
> замораживает единственный PHP-поток — нативный вызов вытеснить нечем. Userland
> CPU-цикл вытесняется по умолчанию
> ([автоматическая преемпция](coroutine-switching.ru.md)), поэтому задерживает
> соседей только на квант. Per-message таймаута в push-модели нет (нет понятия
> «запрос»); границы задают idle `readTimeoutMs`, `writeTimeoutMs` и graceful
> shutdown.

## Обработка ошибок

Если обработчик бросил исключение, оно перехватывается, соединение закрывается, а
хук `onError: Closure(Throwable, Connection): void` может его увидеть и отправить
прощальный кадр перед закрытием. В обычном коде `write` бросает
`SocketServerConnectionClosedException`, когда клиент уже отключился — поймайте
его, чтобы остановить push-цикл, или дайте размотать корутину.

```php
$server = new SocketServer(
    onError: function (Throwable $exception, Connection $connection): void {
        error_log($exception->getMessage());

        try {
            $connection->write("error\n");
        } catch (Throwable) {
        }
    },
);
```

## Graceful shutdown и SO_REUSEPORT

По сигналу (SIGTERM/SIGINT), при достижении `maxConnections` или при осиротении
(`masterPid`) сервер закрывает листенер и полузакрывает активные соединения на
чтение (`CloseRead`): обработчик, читающий в цикле, получает EOF (текущая запись
всё же проходит) и возвращает управление. Push-обработчик, который не читает,
EOF не замечает, и его добивает принудительное закрытие после grace-периода
(`drainGrace`, 2 c), а дальше ожидание ограничено `shutdownTimeoutMs`. В пуле
`SO_REUSEPORT` ядро сразу отдаёт новые соединения соседям, и процесс выходит
сам. `reusePort: true` — несколько процессов на одном порту, по процессу на ядро
— основа масштабирования под мастером воркеров.

Строки жизненного цикла идут в `STDOUT` — рядом с access-логом на соединение,
который Go-сторона пишет при закрытии каждого соединения:

```
2026-06-28T12:00:00.000000 sconcur socket server listening on 0.0.0.0:9100 pid=12345 version=0.9.0 maxConcurrency=0 maxConnections=0 reusePort=0
2026-06-28T12:00:01.000000 sconcur socket server shutdown: stop accepting (reason=signal), draining 2 in-flight
2026-06-28T12:00:01.050000 sconcur socket server shutdown: drained all in-flight
2026-06-28T12:00:01.060000 sconcur socket server shutdown: stopped
```

`reason=signal` — `SIGTERM`/`SIGINT` или потеря мастера; `reason=limit` — лимит
`maxConnections`. Под [мастером воркеров](worker-master.ru.md) они попадают в общий
лог.

## Запуск под мастером воркеров

Сервер — «server-agnostic» воркер для `bin/sconcur-server`; пример конфига —
группа `socket` в `config/sconcur.servers.config.json`. Мастер разворачивает блок `server`
этого конфига в argv `--key=value` (их парсит `fromArgs`) и передаёт свой pid как
`--masterPid` для проверки на сироту — детали в
[мастере воркеров](worker-master.ru.md).

```php
$server = SocketServer::fromArgs($_SERVER['argv']);

$server->serve(static function (Connection $connection): void {
    while (($frame = $connection->read()) !== null) {
        $connection->write($frame);
    }
});
```

## Ограничения

- Только TCP. Unix-сокеты не поддерживаются (`SO_REUSEPORT` к `AF_UNIX` не
  применяется; multi-worker для unix требует наследования fd).
- Broadcast не встроен — рассылку в другие соединения делает приложение.
- Нет per-message таймаута: push-модель ориентирована на соединение.
- Общие ограничения библиотеки (только CLI, только Linux, только NTS, нельзя
  `pcntl_fork` после загрузки расширения) — см. [README](../README.ru.md).
