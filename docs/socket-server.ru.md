# Сокет-сервер (TCP)

Долгоживущий TCP-сервер: сеть живёт в Go-расширении, каждое принятое соединение
стримится в PHP и обрабатывается в своей корутине. Модель — push: обработчик
получает объект соединения и сам ведёт диалог — читает входящие фреймы и пушит
фреймы клиенту в любой момент (server push), а не «один ответ на сообщение».

Эталон по устройству — [HTTP-сервер](http-server.ru.md); сокет-сервер переиспользует
его механику (стриминговое состояние, `Scheduler::serve`) и запускается под тем же
[мастером воркеров](worker-master.ru.md).

## Содержание

- [Фрейминг](#фрейминг)
- [Быстрый старт](#быстрый-старт)
- [Connection: read / write / close](#connection-read--write--close)
- [Server push](#server-push)
- [Параметры](#параметры)
- [Конкурентность](#конкурентность)
- [Обработка ошибок](#обработка-ошибок)
- [Graceful shutdown и SO_REUSEPORT](#graceful-shutdown-и-so_reuseport)
- [Лог старта и остановки](#лог-старта-и-остановки)
- [Запуск под мастером воркеров](#запуск-под-мастером-воркеров)
- [Ограничения](#ограничения)

## Фрейминг

Поток байтов соединения нарезается на фреймы по схеме length-prefix: `uint32`
big-endian длина payload (4 байта), затем сам payload. Тот же формат в обе стороны.
Бинарно безопасно, без экранирования, с естественным лимитом `maxMessageBytes`
(на входящие фреймы).

```
[len=5]hello[len=3]bye
```

Клиент кадрирует так же. Пример на PHP:

```php
fwrite($connection, pack('N', strlen($data)) . $data); // отправить фрейм
```

## Быстрый старт

```php
use SConcur\Features\SocketServer\Dto\Connection;
use SConcur\Features\SocketServer\SocketServer;

$server = new SocketServer(address: '0.0.0.0:9100');

$server->serve(static function (Connection $connection): void {
    // echo: читаем фреймы и шлём обратно, пока соединение живо
    while (($frame = $connection->read()) !== null) {
        $connection->write($frame);
    }
});
```

Обработчик — `Closure(Connection): void` — исполняется в корутине соединения и сам
управляет его жизненным циклом. Когда обработчик завершается, соединение закрывается
автоматически.

## Connection: read / write / close

`Connection` (`src/Features/SocketServer/Dto/Connection.php`):

| Член | Описание |
| --- | --- |
| `read(): ?string` | следующий входящий фрейм; `null` — клиент закрыл свою сторону (EOF) или соединение завершено. Кооперативно приостанавливает корутину до прихода фрейма |
| `write(string $data): void` | запушить фрейм клиенту (с backpressure: ждёт, пока байты уйдут в сокет). Бросает `SocketServerConnectionClosedException`, если соединение разорвано |
| `close(): void` | закрыть соединение (идемпотентно, best-effort) |
| `isClosed(): bool` | закрыто ли соединение |
| `id`, `remoteAddr`, `localAddr` | идентификатор и адреса соединения |

Внутри обработчика можно делать асинхронные вызовы (Sleeper, Mongodb, SQL,
HTTP-клиент) между чтениями/записями — корутина кооперативно приостанавливается,
другие соединения продолжают обслуживаться.

## Server push

Главное отличие от «запрос-ответ»: обработчик не обязан отвечать на каждый
входящий фрейм и может пушить сколько угодно фреймов, в том числе без входящих:

```php
$server->serve(static function (Connection $connection): void {
    // один входящий фрейм -> поток ответных фреймов
    $request = $connection->read();

    for ($i = 0; $i < 10; $i++) {
        $connection->write("update-$i");

        Sleeper::sleep(seconds: 1); // между пушами идёт async-работа
    }
});
```

Push в другие соединения (broadcast/чат/pub-sub) в этой версии не встроен —
приложение может хранить ссылки на `Connection` и писать в них самостоятельно
(`Connection::write` маршрутизируется по `id` на Go-стороне через глобальную
карту `pendingConnections`).

## Параметры

Конструктор `SocketServer` (значения по умолчанию зеркалят Go):

| Параметр | По умолчанию | Назначение |
| --- | --- | --- |
| `address` | `0.0.0.0:9100` | адрес слушателя `host:port` |
| `readTimeoutMs` | `0` (выкл) | idle-таймаут ожидания входящего фрейма в `read()`. Push-only обработчик, который не читает, его не касается |
| `writeTimeoutMs` | `30000` | максимум на запись одного фрейма клиенту |
| `maxMessageBytes` | `1048576` (1 MiB) | лимит длины одного входящего фрейма; превышение завершает ввод соединения |
| `maxConcurrency` | `0` (без лимита) | максимум одновременно обслуживаемых соединений; лишние ждут свободный слот |
| `maxConnections` | `0` (без лимита) | остановить сервер после N обслуженных соединений (мера против утечек) |
| `shutdownTimeoutMs` | `5000` | таймаут дренажа in-flight соединений при остановке |
| `reusePort` | `false` | `SO_REUSEPORT` — пул процессов на один порт (Linux) |
| `onError` | `null` | хук ошибки обработчика |
| `masterPid` | `null` | orphan-чек под мастером |

## Конкурентность

Конкурентность — между соединениями: каждое соединение в своей корутине, поэтому
десятки соединений работают параллельно. Каждый `read()`/`write()` кооперативно
приостанавливает корутину, не блокируя остальные.

`maxConcurrency` ограничивает число одновременно обслуживаемых соединений (слот
держится всё время жизни соединения); лишние соединения принимаются на сокете, но
не обрабатываются, пока не освободится слот.

> **CPU-bound / нативный блок.** Тяжёлый синхронный обработчик (нативный `sleep`,
> CPU-цикл) замораживает единственный поток PHP — кооперативная модель его не
> вытесняет. В push-модели per-message-таймаута нет (нет понятия «запрос»); границы
> задают idle-`readTimeoutMs`, `writeTimeoutMs` и graceful-остановка.

## Обработка ошибок

Если обработчик бросает исключение, оно перехватывается, соединение закрывается, а
хук `onError: Closure(Throwable, Connection): void` может это пронаблюдать (логирование)
и при необходимости запушить финальный фрейм перед закрытием:

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

`Connection::write` в обычном коде бросает `ConnectionClosedException`, когда клиент
уже отключился — обработчик может его поймать и остановить пуш-цикл, либо дать ему
размотать корутину.

## Graceful shutdown и SO_REUSEPORT

По сигналу (SIGTERM/SIGINT), по достижении `maxConnections` или при сиротстве
(`masterPid`) сервер перестаёт принимать новые соединения (закрывает слушатель) и
полузакрывает на чтение in-flight соединения (`CloseRead`): обработчик, читающий в
цикле, получает EOF (его текущая запись ещё проходит) и завершается. Push-only
обработчик, который не читает, EOF не замечает и добивается принудительным закрытием
по истечении грейса (`drainGrace`, 2 c). Затем дренаж in-flight ограничен
`shutdownTimeoutMs`. На пуле `SO_REUSEPORT` ядро тут же раздаёт новые соединения
соседям, после чего процесс завершается сам.

`reusePort: true` позволяет нескольким процессам слушать один порт (один процесс на
ядро) — основа масштабирования под мастером воркеров.

Каждый шаг остановки пишется строкой в `STDOUT` — см. [Лог старта и остановки](#лог-старта-и-остановки).

## Лог старта и остановки

Сервер пишет в `STDOUT` строки жизненного цикла (наряду с per-connection access-логом,
который Go-сторона пишет при закрытии каждого соединения). При старте — одна строка, как
только листенер запущен:

```
2026-06-28T12:00:00.000000 sconcur socket server listening on 0.0.0.0:8090 pid=12345 version=0.5.1 maxConcurrency=0 maxConnections=0 reusePort=0
```

В ней адрес, pid процесса, версия расширения и ключевые лимиты. При graceful shutdown —
по строке на шаг:

```
2026-06-28T12:00:01.000000 sconcur socket server shutdown: stop accepting (reason=signal), draining 2 in-flight
2026-06-28T12:00:01.050000 sconcur socket server shutdown: drained all in-flight
2026-06-28T12:00:01.060000 sconcur socket server shutdown: stopped
```

`reason=signal` — остановка по `SIGTERM`/`SIGINT` (или потере мастера); `reason=limit` —
по достижению предела `maxConnections`. Строки пишет PHP-сторона и сразу флашит. Под
[мастером воркеров](worker-master.ru.md) они попадают в общий лог.

## Запуск под мастером воркеров

Сервер — «server-agnostic»-воркер для `bin/sconcur-server`. Пример конфига —
`config/sconcur.socket-server.config.json`; воркер-скрипт строит сервер из argv:

```php
use SConcur\Features\SocketServer\Dto\Connection;
use SConcur\Features\SocketServer\SocketServer;

$server = SocketServer::fromArgs($_SERVER['argv']);

$server->serve(static function (Connection $connection): void {
    while (($frame = $connection->read()) !== null) {
        $connection->write($frame);
    }
});
```

Параметры из блока `server` JSON-конфига мастер разворачивает в `--ключ=значение`
argv (`fromArgs` их разбирает), а свой pid прокидывает флагом `--masterPid`
(orphan-чек). `reusePort: true` включает пул процессов на ядра. Подробности —
в [мастере воркеров](worker-master.ru.md).

## Ограничения

- Только TCP. Unix-сокеты не поддерживаются (`SO_REUSEPORT` неприменим к
  `AF_UNIX`; мультиворкеры для unix требуют наследования fd — отдельная задача).
- Broadcast не встроен. Push в другие соединения — силами приложения (хранить
  хендлы `Connection`).
- Нет per-message-таймаута. Модель push connection-ориентирована; границы — это
  `readTimeoutMs`/`writeTimeoutMs` и graceful-остановка.
- Общие ограничения библиотеки (только CLI, только Linux, только NTS, нельзя
  `pcntl_fork` после загрузки расширения) — см. [README](../README.md).
