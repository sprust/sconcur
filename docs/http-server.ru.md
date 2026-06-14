# HTTP-сервер

Долгоживущий PHP-демон, который принимает HTTP-запросы и обрабатывает **каждый в
отдельной корутине** (Fiber), конкурентно с остальными. Сетевой I/O живёт в
Go-расширении; PHP остаётся тонким слоем-оркестратором. Реализация — в
`src/Features/HttpServer/` (PHP) и `ext/internal/features/httpserver/` (Go).

> ⚠️ Перед использованием прочитайте раздел [«Чего нет в отличие от типовых
> серверов»](#чего-нет-в-отличие-от-типовых-серверов) — модель кооперативная и
> однопоточная, это накладывает реальные ограничения на код обработчиков.

## Оглавление

- [Идея и модель](#идея-и-модель)
- [Быстрый старт](#быстрый-старт)
- [Примеры](#примеры)
- [Параметры сервера](#параметры-сервера)
- [API: Request / Response / StreamedResponse](#api-request--response--streamedresponse)
- [Стриминг ответа (chunked / SSE)](#стриминг-ответа-chunked--sse)
- [Обработка ошибок](#обработка-ошибок)
- [Конкурентность и лимиты](#конкурентность-и-лимиты)
- [Graceful shutdown](#graceful-shutdown)
- [Внутреннее устройство](#внутреннее-устройство)
- [Чего нет в отличие от типовых серверов](#чего-нет-в-отличие-от-типовых-серверов)
- [Нюансы и подводные камни](#нюансы-и-подводные-камни)
- [Запуск в Docker](#запуск-в-docker)
- [Тестирование](#тестирование)

---

## Идея и модель

Сетевой стек (приём соединений, парсинг HTTP, keep-alive, таймауты, запись ответа)
работает в Go на стандартном `net/http.Server`. Каждый принятый запрос
превращается в обычный «результат» и приходит в PHP через тот же единый канал
`waitAny`, что и результаты всех остальных задач (Mongo, Sleeper). Благодаря этому
сервер **переиспользует существующий планировщик** (`Scheduler`) и не вводит второй
event-loop.

Базовая модель — **spawn-на-запрос**: на каждое событие-запрос создаётся новая
корутина-обработчик. Внутри обработчика можно делать обычные асинхронные вызовы
SConcur (MongoDB, Sleeper, …) — они выполнятся конкурентно с обработкой других
запросов.

```
клиент ──► Go (net/http.Server) ──► событие-запрос ──► PHP Scheduler::serve()
                                                          └─► spawn(корутина) ──► ваш обработчик
ваш обработчик возвращает Response ──► Go пишет ответ в соединение ──► клиент
```

## Быстрый старт

```php
use SConcur\Features\HttpServer\Dto\Request;
use SConcur\Features\HttpServer\Dto\Response;
use SConcur\Features\HttpServer\HttpServer;

require __DIR__ . '/vendor/autoload.php';

$server = new HttpServer(address: '0.0.0.0:8080');

$server->serve(static function (Request $request): Response {
    return match ($request->path) {
        '/'      => new Response(body: 'ok'),
        '/ping'  => new Response(body: 'pong'),
        default  => new Response(body: 'not found', status: 404),
    };
});
```

Запуск (требуется собранное расширение `ext/build/sconcur.so`):

```shell
php -d extension=./ext/build/sconcur.so server.php
```

`serve()` блокируется навсегда — до сигнала `SIGTERM`/`SIGINT` или остановки потока.

## Примеры

### Конкурентная асинхронная работа в обработчике

Обработчик исполняется в своей корутине, поэтому асинхронные фичи SConcur внутри
него не блокируют другие запросы:

```php
use SConcur\Features\Sleeper\Sleeper;

$sleeper = new Sleeper();

$server->serve(static function (Request $request) use ($sleeper): Response {
    if ($request->path === '/slow') {
        $sleeper->msleep(milliseconds: 500); // корутина приостанавливается,
                                              // другие запросы продолжают обслуживаться
        return new Response(body: 'done');
    }

    return new Response(body: 'ok');
});
```

### Чтение метода, пути, query, заголовков и тела

```php
$server->serve(static function (Request $request): Response {
    // $request->method     — "GET" / "POST" / ...
    // $request->path       — "/users"
    // $request->query      — сырая строка "a=1&b=2" (парсите сами: parse_str())
    // $request->headers    — array<string, array<int, string>> (значений может быть несколько)
    // $request->body       — тело запроса (байт-безопасно; в т.ч. бинарь)
    // $request->remoteAddr — "ip:port" клиента
    // $request->host       — Host запроса
    // $request->proto      — "HTTP/1.1"

    parse_str($request->query, $queryParams);

    return new Response(
        body: json_encode([
            'method' => $request->method,
            'query'  => $queryParams,
        ]),
        headers: ['Content-Type' => 'application/json'],
    );
});
```

### Несколько значений одного заголовка (например, Set-Cookie)

```php
return new Response(
    body: 'ok',
    headers: [
        'Set-Cookie'   => ['a=1; Path=/', 'b=2; Path=/'], // список значений
        'Content-Type' => 'text/plain',                   // одиночная строка тоже можно
    ],
);
```

### Сервер с тюнингом

```php
$server = new HttpServer(
    address:          '0.0.0.0:8080',
    maxConcurrency:   256,    // не больше 256 запросов в обработке одновременно
    maxRequestBody:   1 << 20, // 1 MiB лимит тела запроса
    handlerTimeoutMs: 5_000,  // 504, если обработчик не начал отвечать за 5 с
    onError: static function (\Throwable $e, Request $r): void {
        error_log(sprintf('[http] %s %s: %s', $r->method, $r->path, $e->getMessage()));
    },
);
```

## Параметры сервера

Конструктор `HttpServer` (`src/Features/HttpServer/HttpServer.php`). Все таймауты —
в миллисекундах. Дефолты PHP зеркалят дефолты Go.

| Параметр | Дефолт | Назначение |
|---|---|---|
| `address` | — (обязателен) | Адрес прослушивания, например `0.0.0.0:8080` или `127.0.0.1:9000`. |
| `readHeaderTimeoutMs` | `10000` | Предел чтения заголовков запроса (`net/http` `ReadHeaderTimeout`). |
| `readTimeoutMs` | `30000` | Предел чтения всего запроса (`ReadTimeout`). |
| `writeTimeoutMs` | `30000` | Предел записи ответа (`WriteTimeout`). |
| `idleTimeoutMs` | `60000` | Предел простоя keep-alive соединения (`IdleTimeout`). |
| `shutdownTimeoutMs` | `5000` | Сколько ждать дренаж активных соединений на стороне Go при остановке. |
| `maxRequestBody` | `10485760` (10 MiB) | Лимит тела запроса в байтах. Превышение → `413`. |
| `maxConcurrency` | `0` (без лимита) | Максимум одновременно обрабатываемых запросов. См. [лимиты](#конкурентность-и-лимиты). |
| `handlerTimeoutMs` | `0` (выкл) | Сколько ждать **начала** ответа от обработчика, иначе `504`. См. [таймаут хендлера](#таймаут-хендлера). |
| `onError` | `null` | `Closure(Throwable, Request): ?Response` — наблюдатель ошибок обработчика. |

Значение `0` для `maxConcurrency`/`handlerTimeoutMs` означает «выключено». Для
таймаутов `0` означает «взять Go-дефолт».

## API: Request / Response / StreamedResponse

### `Request` (`Dto/Request.php`)

`readonly`-DTO входящего запроса:

```php
public string $requestId;   // внутренний id (маршрутизация ответа), обычно не нужен
public string $method;      // "GET", "POST", ...
public string $path;        // "/users/42" (без query)
public string $query;       // сырая query-строка "a=1&b=2"
/** @var array<string, array<int, string>> */
public array  $headers;     // канонизированные имена ("Content-Type"), значений может быть несколько
public string $body;        // тело запроса (байт-безопасно)
public string $remoteAddr;  // "ip:port" клиента
public string $host;        // Host
public string $proto;       // "HTTP/1.1"
```

### `Response` (`Dto/Response.php`)

Обычный одноразовый ответ:

```php
new Response(
    body: 'hello',         // string, по умолчанию ''
    status: 200,           // int, по умолчанию 200
    headers: [             // array<string, string|array<int, string>>
        'Content-Type' => 'text/plain',
    ],
);
```

Если `Content-Type` не задан — Go определит его автоматически по телу
(`http.DetectContentType`). Заголовок может быть строкой (одно значение) или
списком строк (несколько значений).

### `StreamedResponse` (`Dto/StreamedResponse.php`)

Потоковый ответ — см. [Стриминг](#стриминг-ответа-chunked--sse).

Обработчик возвращает **либо `Response`, либо `StreamedResponse`**. Возврат чего-то
иного — это ошибка контракта: фреймворк ответит `500` и сообщит в `onError`.

## Стриминг ответа (chunked / SSE)

Чтобы отдавать тело частями (chunked transfer, Server-Sent Events), верните
`StreamedResponse`. Сначала отправляются статус и заголовки, затем замыкание-писатель
отдаёт чанки через `ResponseStream`:

```php
use SConcur\Features\HttpServer\Dto\ResponseStream;
use SConcur\Features\HttpServer\Dto\StreamedResponse;

return new StreamedResponse(
    status: 200,
    headers: ['Content-Type' => 'text/event-stream'],
    writer: static function (ResponseStream $out) use ($sleeper): void {
        foreach (range(1, 5) as $i) {
            $out->write("data: event $i\n\n"); // отдаётся и сбрасывается клиенту немедленно
            $sleeper->msleep(milliseconds: 1000); // между чанками можно делать async-работу
        }
    },
);
```

Ключевые свойства:

- **Backpressure записи.** `ResponseStream::write()` возвращается только после того,
  как Go **фактически записал и сбросил** чанк клиенту. Быстрый продюсер не обгоняет
  медленного клиента.
- **Без `Content-Length`.** Сброс после заголовков и каждого чанка включает chunked
  transfer encoding — длина заранее не известна.
- **Между чанками** корутина может приостанавливаться на асинхронных вызовах, не
  блокируя другие запросы.
- **Статус нельзя поменять** после первого `write()` — заголовки уже на проводе.
  Исключение внутри писателя поэтому не превращается в `500` (он уже отправлен), а
  лишь сообщается в `onError`, после чего поток корректно завершается.

## Обработка ошибок

- **Исключение в обработчике** → клиент получает `500 Internal Server Error`, петля
  `serve()` не падает (изоляция ошибок).
- **Неверный тип возврата** (не `Response`/`StreamedResponse`) → тоже `500`.
- **Наблюдаемость.** По умолчанию ошибка **проглатывается** (только `500`). Передайте
  `onError`, чтобы её увидеть — залогировать, отправить в трейсинг, или вернуть свой
  ответ:

```php
onError: static function (\Throwable $e, Request $request): ?Response {
    error_log((string) $e);

    // вернуть свой ответ вместо дефолтного 500 (или null → дефолтный 500)
    return new Response(body: 'oops', status: 500);
}
```

`onError`, бросивший исключение сам, безопасно проглатывается — клиент всё равно
получит `500`.

## Конкурентность и лимиты

### Один процесс = один поток

PHP-часть **однопоточная и кооперативная**: единый `Scheduler` гоняет цикл `waitAny`
и возобновляет корутины. Управление переходит другой корутине **только** когда
текущая приостанавливается на асинхронной фиче SConcur (`Fiber::suspend()`).

Из этого следует главное правило:

> **Обработчики обязаны быть I/O-bound через фичи SConcur.** Любая блокирующая или
> CPU-затратная работа в обработчике (нативный `sleep()`, синхронный PDO/`curl`,
> тяжёлый расчёт, чтение файла) замораживает **весь** сервер — все остальные запросы
> ждут. Уступают управление только асинхронные вызовы SConcur.

### `maxConcurrency`

Ограничивает число одновременно обрабатываемых запросов. Реализован семафором в Go,
захватываемым **до чтения тела**, поэтому ограничивает разом:

- число горутин,
- объём памяти (тела читаются только у запросов, получивших слот),
- число PHP-корутин (корутина живёт не дольше удержания слота).

Лишние соединения ждут освобождения слота (естественный backpressure). `0` —
без лимита; под нагрузкой с большими телами это риск OOM, поэтому для публичных
серверов задавайте лимит.

### Таймаут хендлера

`handlerTimeoutMs` ограничивает время **до начала ответа**. Если обработчик за это
время ничего не записал → клиент получает `504 Gateway Timeout`, а слот
освобождается. Дедлайн покрывает только время до первого `write`/возврата
`Response`, поэтому **начатый стрим не обрывается**.

Важно: таймаут спасает от обработчиков, **ждущих async-работу** (зависший Mongo,
долгий sleep). От **CPU-bound** зацикливания он не спасает — вытеснения в
кооперативной модели нет, такой обработчик блокирует весь цикл и таймер не сработает.

## Graceful shutdown

При получении `SIGTERM`/`SIGINT` сервер:

1. перестаёт принимать новые запросы;
2. **дожидается** завершения уже запущенных обработчиков (in-flight);
3. останавливает листенер и выходит.

Запрос, **принятый но ещё не отвеченный** к моменту остановки, получает `503 Service
Unavailable` (а не оборванное соединение).

Детали:

- Обработчики сигналов ставятся **до** старта листенера и **восстанавливаются** при
  выходе (прежние обработчики `SIGTERM`/`SIGINT` и режим `pcntl_async_signals` не
  угоняются навсегда).
- Требуется `ext-pcntl`. Без него graceful shutdown не работает — процесс
  завершится жёстко (что нарушает правило «не обрывать активные задачи»). В
  Docker-образах проекта `pcntl` включён.
- На **idle-сервере** shutdown срабатывает быстро: цикл `serve()` поллит `waitAny` с
  интервалом 250 мс и замечает сигнал даже без трафика.

## Внутреннее устройство

### Поток одного запроса

```
PHP                                              │ Go (httpserver)
HttpServer::serve($handler)                      │
  push(ServePayload, MethodHttpServe) ──────────►│ handleServe: net.Listen + net/http.Server.Serve()
                                                 │   serverState — это http.Handler, стриминговое «состояние»
Scheduler::serve(): waitAnyTimeout(250ms) loop   │
  ◄── событие-запрос (батч, HasNext=true) ───────│ ServeHTTP: захват слота → чтение тела →
  next() (переармить листенер)                   │   RequestEvent в канал requests → ждёт команды записи
  spawn(корутина): handle($handler, payload)     │
    $response = $handler($request)               │
    exec(RespondPayload::full) ─────────────────►│ handleRespond: dispatch writeCommand →
  ◄── ack (ответ записан) ────────────────────────│   ServeHTTP пишет статус+заголовки+тело → клиент
  корутина завершилась → flow очищается          │
```

### Ключевые сущности

**PHP** (`src/`):

- `Features/HttpServer/HttpServer` — публичный API: `serve($handler)`. Генерирует
  `flowKey`, ставит обработчики сигналов, пушит задачу-листенер, запускает серверный
  цикл планировщика.
- `Scheduler/Scheduler::serve()` — серверный цикл поверх `waitAnyTimeout()`:
  диспетчеризует три вида результата — **событие-запрос** (→ `spawn` обработчика в
  новом per-request flow), **результат задачи** (→ возобновление корутины по
  `taskKey`), и завершение/ошибку серверного потока. Дренаж и `stopFlow` при
  shutdown.
- `Scheduler::spawn()` — fire-and-forget корутина вне `WaitGroup`, со своим flow;
  её результат не собирается, ошибки она обязана обработать сама (что и делает
  `HttpServer::handle`, превращая их в `500`).
- DTO `Request`/`Response`/`StreamedResponse`/`ResponseStream`; payloads
  `ServePayload`/`RespondPayload`.

**Go** (`ext/internal/features/httpserver/`):

- `feature.go` — методы `MethodHttpServe` (поднять листенер) и `MethodHttpRespond`
  (записать команду в соединение). Глобальный реестр `pendingRequests`
  (`requestId → канал команд + сигнал отмены`).
- `server.go` — `serverState` как `http.Handler` поверх `net/http.Server`. Каждый
  запрос: `ServeHTTP` отдаёт `RequestEvent` в PHP и ждёт команды записи. Команды:
  `full` (одноразовый ответ), `head`/`chunk`/`end` (стриминг). Семафор конкурентности,
  таймаут хендлера, 503/504, graceful `Shutdown`.

### Почему листенер — это «стриминговая задача»

Эмитить событие-запрос с произвольным `taskKey` напрямую в общий канал нельзя —
сломается учёт задач (`Flow.OnDelivered`). Поэтому листенер оформлен как
**стриминговое состояние**: каждый принятый запрос приходит как очередной «батч»
(`HasNext=true`), а PHP переармливает поток вызовом `next()`. `requestId` для
маршрутизации ответа лежит в payload события.

### Per-request flow

`serverFlowUuid` — это flow самого листенера. Каждый запрос обрабатывается в **своём**
flow, чтобы под-задачи обработчика (Mongo/Sleeper) изолировались и корректно
очищались, а остановка одного запроса не роняла весь сервер.

### Протокол ответа (команды записи)

Ответ — это последовательность команд, передаваемых из PHP через `MethodHttpRespond`:

- `full` — одноразовый ответ: статус + заголовки + тело, затем завершить.
- `head` — начать стрим: статус + заголовки, сбросить клиенту.
- `chunk` — кусок тела, сбросить.
- `end` — завершить стрим.

Каждая команда подтверждается обратно (ack) только после применения — это и даёт
backpressure записи. Если соединение к моменту записи отвалилось или сработал
таймаут, обработчик получает ошибку (`abandoned`) и корректно разворачивается, а не
зависает.

## Чего нет в отличие от типовых серверов

| Что | Статус | Комментарий |
|---|---|---|
| **PHP-FPM / mod_php** | ❌ нельзя | Только долгоживущий **CLI**. Расширение держит Go-рантайм на уровне процесса; модель FPM этому противоречит. |
| **`pcntl_fork` после загрузки расширения** | ❌ нельзя | Go-рантайм не переживает `fork`. Форкайтесь **до** первого обращения к расширению или запускайте отдельные процессы (`exec`). |
| **ZTS-сборка PHP** | ❌ нет | Только NTS (non-thread-safe). |
| **TLS / HTTPS** | ❌ пока нет | Только plain TCP. Терминируйте TLS впереди (nginx/HAProxy/балансировщик). |
| **HTTP/2, WebSocket** | ❌ нет | `net/http` без TLS — HTTP/1.1; h2c и WebSocket не включены. |
| **Параллелизм на ядра в одном процессе** | ❌ нет | Один процесс = один PHP-поток. Масштаб — несколькими процессами (`SO_REUSEPORT` — в планах). |
| **CPU-bound обработчики** | ⚠️ опасно | Блокируют весь сервер: нет вытеснения. Только I/O-bound через фичи SConcur. |
| **Синхронный I/O в обработчике** | ⚠️ опасно | Нативный `sleep`/PDO/`curl`/файлы замораживают цикл. Используйте async-фичи SConcur. |
| **Стриминг **тела запроса**** | ❌ нет | Тело читается целиком в память (лимит `maxRequestBody`). |
| **Роутер / middleware** | ❌ нет | Низкоуровневый контракт `(Request): Response`. Роутинг — на вашей стороне. |
| **`exit()`/`die()` при активных задачах** | ❌ нельзя | Поведение не определено. Сначала доведите/остановите задачи. |

Что, наоборот, **работает** (и иногда удивляет): keep-alive, конвейер таймаутов,
chunked/SSE-стриминг, несколько значений одного заголовка (например, несколько
`Set-Cookie`), бинарные тела, лимит конкурентности, `413`/`503`/`504`, graceful
shutdown.

## Нюансы и подводные камни

- **Один обработчик — один поток исполнения.** Параллелизм достигается тем, что
  обработчики **уступают** на async-вызовах. Спроектируйте обработчики так, чтобы
  любая долгая работа шла через фичи SConcur.
- **`query` не распарсен.** `Request::query` — сырая строка; используйте
  `parse_str()`.
- **Заголовки запроса** приходят с канонизированными именами (`Content-Type`,
  `X-Request-Id`), значений может быть несколько. Имя сравнивайте регистронезависимо.
- **204/304** — тело в ответе будет отброшено `net/http` (как и положено).
- **Лимит тела** проверяется через `MaxBytesReader`: превышение → `413`, без тихого
  усечения.
- **Память.** Без `maxConcurrency` число одновременных обработчиков и буферизованных
  тел не ограничено — под флудом крупными телами возможен OOM. Задавайте лимит.
- **Idle-shutdown** срабатывает в пределах ~250 мс (интервал поллинга `waitAny`).

## Запуск в Docker

В `docker-compose.yml` есть отдельный сервис `http-server` (демо-сервер из
`tests/servers/http/http-server.php`). Порт публикуется на хост через `.env`
(`HTTP_SERVER_PORT`/`HTTP_SERVER_DOCKER_PORT`). Пересобрать и перезапустить:

```shell
make http-server-restart
```

Это пересобирает расширение (`make ext-build`) и пересоздаёт контейнер.

## Тестирование

Автотесты **не зависят** от docker-сервиса: они поднимают сервер отдельным
процессом через харнесс `SConcur\Tests\Impl\HttpServer\TestHttpServer`
(`tests/impl/HttpServer/TestHttpServer.php`). Опции запуска именуются **точно как
параметры конструктора `HttpServer`** и передаются процессу как `--name=value`:

```php
use SConcur\Tests\Impl\HttpServer\TestHttpServer;

$server = TestHttpServer::start(['maxConcurrency' => 2, 'handlerTimeoutMs' => 200]);

// $server->baseUrl(), $server->signal(SIGTERM), $server->waitForExit(3.0), $server->stop()
```

`BaseHttpServerTestCase` поднимает по серверу на тест-класс; переопределите
`serverOptions()` для нужных настроек. Демо-сервер
(`tests/servers/http/http-server.php`) содержит маршруты под все сценарии тестов:
`/`, `/method`, `/echo`, `/query`, `/echo-header`, `/meta`, `/empty`, `/cookies`,
`/stream`, `/msleep/{ms}`, `/throw`, `/status/{code}`.

Покрытие (`tests/feature/Features/HttpServer/`): маршрутизация и методы, query и
заголовки запроса, бинарное тело, мульти-заголовки ответа, стриминг, лимит
конкурентности, `413`, таймаут хендлера (`504`), graceful drain (`SIGTERM` с
in-flight).

---

См. также: [README → Принцип работы](../README.md#принцип-работы),
[план HTTP-сервера](../.ai/plans/http-server.md),
[Как добавить новую фичу](adding-a-feature.ru.md).
