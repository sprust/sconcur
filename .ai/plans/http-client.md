# HTTP-клиент (PSR-18) со стримингом

Статус: **реализовано** (v1 + фаза 2). Пользовательская документация —
[docs/http-client.ru.md](../../docs/http-client.ru.md). Стриминг тела запроса
(upload, фаза 2) реализован через опцию `streamRequestBody`; под-операции
(`Request`/`UploadChunk`/`UploadEnd`) различаются командой в конверте payload
(`HttpClientCommandEnum`, как у MongoDB), а не отдельным `MethodEnum` —
`uploadSession` + `io.Pipe` на Go-стороне.

Асинхронный HTTP-клиент SConcur: PHP остаётся тонким слоем-оркестратором, а весь
сетевой I/O (DNS, соединение, TLS, отправка запроса, чтение ответа) живёт в
Go-расширении на стандартном `net/http.Client`. Запрос уходит в горутину, корутина
(Fiber) приостанавливается — десятки запросов летят «веером», как и все прочие
фичи SConcur. Вне `WaitGroup` тот же API работает синхронно (см.
[README → Применение](../../README.md)).

Клиент — реализация **`Psr\Http\Client\ClientInterface`** (PSR-18) и **обязан
поддерживать стриминг ответа**: тело ответа отдаётся `StreamInterface`-ом, который
лениво подтягивает чанки из Go (как курсор Mongo / тело запроса HTTP-сервера), не
буферизуя весь ответ в памяти.

> Эта фича — пара к [HTTP-серверу](http-server.md): сервер принимает запросы и
> стримит их в PHP, клиент отправляет запросы и стримит ответы из PHP. Обе строятся
> на одном механизме **стримингового состояния** (`StateContract` + `states.Get()`).

---

## Оглавление

- [Идея и модель](#идея-и-модель)
- [Публичный API (PSR-18)](#публичный-api-psr-18)
- [Зависимости PSR](#зависимости-psr)
- [Стриминг ответа](#стриминг-ответа)
- [Стриминг тела запроса](#стриминг-тела-запроса-фаза-2--реализовано)
- [Обработка ошибок (PSR-18)](#обработка-ошибок-psr-18)
- [Параметры клиента и таймауты](#параметры-клиента-и-таймауты)
- [Протокол PHP ↔ Go (payload + результаты)](#протокол-php--go-payload--результаты)
- [Go-сторона](#go-сторона)
- [PHP-сторона](#php-сторона)
- [Жизненный цикл и очистка ресурсов](#жизненный-цикл-и-очистка-ресурсов)
- [Тестирование](#тестирование)
- [Объём и будущие расширения](#объём-и-будущие-расширения)
- [Чеклист реализации](#чеклист-реализации)
- [Открытые вопросы](#открытые-вопросы)

---

## Идея и модель

```
PHP                                                  │ Go (httpclient)
$client->sendRequest($psrRequest)                    │
  exec(RequestPayload, MethodHttpClient) ──────────►│ Handle → state.New → states.Start
  Fiber::suspend()  (управление → Scheduler)         │   Next#1: http.Client.Do(ctx) — соединение, отправка,
                                                     │            чтение СТАТУСА+ЗАГОЛОВКОВ + первый чанк тела
  ◄── result#1: {status, headers, firstChunk} ───────│   → SuccessResultWithNext (тело не дочитано) / Success
  build PSR-7 Response + ResponseBodyStream           │
  return $response                                    │
                                                     │
$response->getBody()->read() / getContents()         │
  next(bodyKey) ────────────────────────────────────►│   Next#2..N: следующий чанк resp.Body
  ◄── result#k: <raw body chunk> ────────────────────│   → ...WithNext, последний → Success (HasNext=false)
  (поток исчерпан → состояние удаляется, Body.Close)  │   Close(): resp.Body.Close на свежем контексте
```

Ключевое: запрос — это **стриминговое состояние** (как агрегатный курсор Mongo и
тело запроса HTTP-сервера). Первый «батч» несёт метаданные ответа (статус +
заголовки + первый кусок тела); последующие «батчи» — чанки тела. PHP-обёртка
`ResponseBodyStream` (реализация `StreamInterface`) тянет чанки по требованию.

Почему это естественно ложится на SConcur:

- `sendRequest()` внутри корутины **приостанавливает** её, не блокируя остальные
  запросы (тот же `Scheduler`, тот же `waitAny`);
- вне Fiber работает синхронно (`Extension::wait`) — единый API;
- очистка незавершённого ответа (ранний `break`, разрушение объекта) уже
  обеспечена машинерией стриминговых состояний (отмена контекста → `Close()`).

---

## Публичный API (PSR-18)

`src/Features/HttpClient/HttpClient.php` — реализует `ClientInterface`:

```php
namespace SConcur\Features\HttpClient;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

readonly class HttpClient implements ClientInterface
{
    public function __construct(
        protected ResponseFactoryInterface $responseFactory, // PSR-17, обязателен
        protected HttpClientOptions $options = new HttpClientOptions(),
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface;
}
```

Использование — одинаково синхронно и в корутине:

```php
// синхронно (вне WaitGroup): дожидается результата сразу
$response = $client->sendRequest($request);
$status   = $response->getStatusCode();
$body     = (string) $response->getBody();      // дочитает поток целиком

// конкурентно: десятки запросов «веером», общее время ≈ самого медленного
$waitGroup = WaitGroup::create();
foreach ($urls as $url) {
    $waitGroup->add(function () use ($client, $factory, $url) {
        return $client->sendRequest($factory->createRequest('GET', $url));
    });
}
$responses = $waitGroup->waitResults();

// стриминг ответа: тело не буферизуется целиком
$response = $client->sendRequest($request);
$stream   = $response->getBody();
while (!$stream->eof()) {
    $chunk = $stream->read(64 * 1024);   // приостанавливает корутину до прихода данных
    // ...обработать чанк...
}
```

PSR-18 синхронен по контракту (`sendRequest(): ResponseInterface`); приостановка
Fiber'а прозрачна для вызывающего — он всё равно получает готовый
`ResponseInterface`, просто его построение конкурентно с другими корутинами.

---

## Зависимости PSR

Чтобы остаться независимыми от конкретной PSR-7 реализации, зависим только от
**интерфейсов** и принимаем фабрику ответа от пользователя:

`composer.json` → `require`:
- `psr/http-client` (`^1.0`) — `ClientInterface`, исключения PSR-18.
- `psr/http-message` (`^2.0`) — `RequestInterface`/`ResponseInterface`/`StreamInterface`/`UriInterface`.
- `psr/http-factory` (`^1.0`) — `ResponseFactoryInterface` (создание `Response`).

`require-dev`:
- `nyholm/psr7` (`^1.8`) — конкретная PSR-7/PSR-17 реализация для тестов и примеров.

`ResponseFactoryInterface` — **строго обязательный** аргумент конструктора
`HttpClient` (явная инъекция, без авто-discovery): ядро не завязано на конкретную
PSR-7 реализацию, пользователь передаёт свою. Тело ответа мы строим **своей**
реализацией `StreamInterface` (`ResponseBodyStream`), поэтому
`StreamFactoryInterface` не нужен — только `ResponseFactoryInterface` для создания
пустого `Response` (статус), на который навешиваем заголовки (`withAddedHeader`) и
поток тела (`withBody`).

---

## Стриминг ответа

`src/Features/HttpClient/Dto/ResponseBodyStream.php` — реализация
`Psr\Http\Message\StreamInterface`. По смыслу — близнец
[`RequestBody`](../../src/Features/HttpServer/Dto/RequestBody.php) HTTP-сервера,
адаптированный под интерфейс PSR-7:

- **Однонаправленный, read-only, не seekable.** `isSeekable()=false`,
  `isWritable()=false`, `isReadable()=true`. `seek()/rewind()/write()` бросают
  `RuntimeException` (по PSR-7 это допустимо для неперематываемых потоков).
- Конструируется из `firstChunk` (инлайн-кусок тела из первого результата) и
  `bodyKey` (taskKey стримингового состояния; `''` если тело уже целиком в
  `firstChunk`).
- `read($length)` — отдаёт до `$length` байт: сначала инлайн-чанк, потом
  подтягивает остаток через `FeatureExecutor::next($bodyKey)` (как `RequestBody`).
  Внутри корутины `next()` **приостанавливает** её — медленный сервер не блокирует
  другие запросы.
- `getContents()` — дочитывает поток до конца, мемоизирует.
- `__toString()` — `getContents()` (PSR-7); ошибки чтения по контракту PSR-7
  нельзя бросать из `__toString` до PHP 7.4-, но в PHP 8.4 — можно; следуем
  поведению Guzzle (бросаем).
- `eof()` — поток исчерпан.
- `getSize()` — `null`, **либо** значение `Content-Length`, если заголовок есть и
  тело не chunked (прокинуть из метаданных первого результата). По умолчанию `null`.
- `getMetadata($key)` — минимальный набор (`null`/`[]`).
- `close()` / `detach()` / `__destruct()` — освобождают Go-флоу при раннем отказе
  (см. [Жизненный цикл](#жизненный-цикл-и-очистка-ресурсов)). Повторяет приём
  `IteratorResult::releaseTask()` / `State::releaseSyncTaskFlow()`.

Транспортная гранулярность фиксирована (как у HTTP-сервера, 64 KiB): тело ≤ этого
размера приходит инлайн с первым результатом без лишних round-trip'ов; большее —
кусками за round-trip, а `read($length)` нарезает их под размер приложения.

---

## Стриминг тела запроса (фаза 2) — реализовано

PSR-7 `RequestInterface::getBody()` — это `StreamInterface`. Два режима отправки,
переключаемые опцией `HttpClientOptions::streamRequestBody` (по умолчанию `false`):

- **Буферизованный (по умолчанию).** Читаем тело целиком
  (`(string) $request->getBody()`) и кладём в payload. Просто, покрывает
  большинство случаев (JSON, формы, мелкие загрузки). Ограничено лимитом
  тела/памятью.
- **Стриминговая загрузка (`streamRequestBody = true`).** Большие тела отправляем
  чанками PHP → Go под-командами envelope'а (зеркально `ResponseStream::write` /
  `MethodHttpRespond` сервера): `Request` открывает запрос с «телом-потоком»
  (`streamBody=true` + `requestId`), `UploadChunk` досылает чанки гранулярностью
  `chunkSize`, `UploadEnd` закрывает тело; Go пишет чанки в `io.Pipe`, отданный как
  `req.Body` (write-backpressure). Метаданные ответа доступны только после полной
  отправки тела, поэтому статус+заголовки тянутся `next(taskKey)` после `UploadEnd`.
  Под-операции различаются командой в конверте payload (`HttpClientCommandEnum`,
  как у MongoDB), а не отдельным `MethodEnum`. См.
  [HttpClient::sendStreaming()](../../src/Features/HttpClient/HttpClient.php) и
  Go-сторону `ext/internal/features/httpclient/upload.go`.

---

## Обработка ошибок (PSR-18)

PSR-18 требует три типа исключений; заводим custom-классы в
`src/Exceptions/HttpClient/`, реализующие соответствующие интерфейсы и
наследующие базовый built-in по [правилам исключений](../README.md#exceptions):

| Случай | Интерфейс PSR-18 | Класс SConcur | База |
|---|---|---|---|
| Сеть недоступна (refused, DNS-fail, таймаут соединения, оборван) | `NetworkExceptionInterface` | `HttpClient\NetworkException` | `RuntimeException` |
| Запрос некорректен (битый URL/метод, не отправлен) | `RequestExceptionInterface` | `HttpClient\RequestException` | `RuntimeException` |
| Прочая ошибка клиента | `ClientExceptionInterface` | `HttpClient\HttpClientException` | `RuntimeException` |

`NetworkExceptionInterface`/`RequestExceptionInterface` обязаны нести
`getRequest(): RequestInterface` — храним исходный запрос в исключении.

Go-сторона различает классы ошибок и кодирует их маркером в payload ошибки
(например, `net`/`req`), а PHP мапит маркер → нужный класс (приём из
`RequestBody`, где `request body too large` → `RequestBodyTooLargeException`).
`4xx`/`5xx` **не являются** ошибками клиента — это нормальный `ResponseInterface`
с соответствующим статусом (PSR-18).

---

## Параметры клиента и таймауты

`src/Features/HttpClient/HttpClientOptions.php` (`readonly`), все таймауты в мс,
дефолты PHP зеркалят дефолты Go. **Требование 2** (предельное время выполнения)
выполняется здесь: `requestTimeoutMs` обязателен и применяется на Go-стороне как
жёсткий лимит всей операции (через `context.WithTimeout(task.GetContext(), ...)`).

| Параметр | Дефолт | Назначение |
|---|---|---|
| `requestTimeoutMs` | `30000` | Полный предел запроса (соединение + отправка + чтение всего тела). Жёсткий лимит контекста. `0` — выкл (не рекомендуется). |
| `connectTimeoutMs` | `10000` | Предел установки TCP/TLS-соединения (`net.Dialer.Timeout`). |
| `responseHeaderTimeoutMs` | `15000` | Предел ожидания статуса+заголовков (`Transport.ResponseHeaderTimeout`). |
| `maxResponseBody` | `0` (без лимита) | Лимит тела ответа в байтах; превышение → ошибка чтения потока. |
| `followRedirects` | `true` | Следовать ли 3xx-редиректам. |
| `maxRedirects` | `10` | Предел числа редиректов. |
| `chunkSize` | `65536` | Гранулярность чтения тела ответа и отправки тела запроса (как у HTTP-сервера). |
| `verifyTls` | `true` | Проверять ли TLS-сертификаты (для самоподписанных в dev). |
| `maxIdleConns` | `100` | Всего idle keep-alive соединений в пуле. |
| `maxIdleConnsPerHost` | `16` | Idle keep-alive соединений на хост. |
| `idleConnTimeoutMs` | `90000` | Сколько держать idle keep-alive соединение до закрытия. |
| `tlsHandshakeTimeoutMs` | `10000` | Предел TLS-рукопожатия. |
| `streamRequestBody` | `false` | Стримить тело запроса чанками вместо буферизации целиком (фаза 2). |

`0` для таймаутов — «взять Go-дефолт»; для `maxResponseBody`/`requestTimeoutMs` —
«выключено».

**Пул соединений / keep-alive.** На Go-стороне держим **переиспользуемый**
`http.Client` + `http.Transport` (синглтон через `sync.Once`), чтобы keep-alive и
пул соединений работали между запросами в рамках процесса. Параметры пула
(`maxIdleConns`, `maxIdleConnsPerHost`, `idleConnTimeoutMs`, `tlsHandshakeTimeoutMs`)
вынесены в опции (таблица выше) с разумными дефолтами. Транспорт освобождается в
`features.Shutdown()` (`CloseIdleConnections`), рядом с MongoDB-клиентами.

---

## Протокол PHP ↔ Go (payload + результаты)

### Method

Новый домен (см. [adding-a-feature](../../docs/adding-a-feature.ru.md)):

- PHP: `SConcur\Features\MethodEnum::HttpClient = 5`
- Go: `ext/internal/types/method.go` → `MethodHttpClient Method = 5`

### Конверт команд (envelope)

Под одним `MethodEnum::HttpClient` под-операции различаются командой в конверте
payload (`HttpClientCommandEnum`, как у MongoDB под `MethodEnum::Mongodb`).
`Base\BaseHttpClientPayload::getData()` отдаёт `{ cm: int command, p: parameters }`.

- PHP: `SConcur\Features\HttpClient\HttpClientCommandEnum` — `Request = 1`,
  `UploadChunk = 2`, `UploadEnd = 3`.
- Go: `ext/internal/types/httpclient.go` → `types.HttpClientCommand`.

### `RequestPayload` / `RequestPayloadParameters` (PHP → Go)

`src/Features/HttpClient/Payloads/RequestPayload.php` (`PayloadInterface`,
команда `Request`) оборачивает `RequestPayloadParameters` (`PayloadParametersInterface`,
`readonly`), зеркальная Go-структура `payloads.RequestParams`. Несёт предельное
время выполнения (`requestTimeoutMs`) — требование 2.

| PHP-поле | ключ `getData()` | Go-тег | Назначение |
|---|---|---|---|
| `method` | `m` | `m` | HTTP-метод |
| `url` | `u` | `u` | абсолютный URL (из `RequestInterface::getUri()`) |
| `headers` | `h` | `h` | `array<string, array<int,string>>` |
| `body` | `b` | `b` | тело запроса (буферизованный режим — целиком; строка) |
| `streamBody` | `sb` | `sb` | стримить тело запроса (фаза 2) |
| `requestId` | `rid` | `rid` | id сессии загрузки тела (для `UploadChunk`/`UploadEnd`) |
| `requestTimeoutMs` | `rt` | `rt` | жёсткий лимит всей операции |
| `connectTimeoutMs` | `ct` | `ct` | лимит соединения |
| `responseHeaderTimeoutMs` | `rht` | `rht` | лимит статуса+заголовков |
| `maxResponseBody` | `mrb` | `mrb` | лимит тела ответа |
| `followRedirects` | `fr` | `fr` | следовать 3xx |
| `maxRedirects` | `mr` | `mr` | предел редиректов |
| `chunkSize` | `cs` | `cs` | гранулярность чтения/отправки тела |
| `verifyTls` | `vt` | `vt` | проверять TLS |
| `maxIdleConns` | `mic` | `mic` | всего idle keep-alive соединений |
| `maxIdleConnsPerHost` | `mih` | `mih` | idle keep-alive соединений на хост |
| `idleConnTimeoutMs` | `ict` | `ict` | таймаут idle-соединения |
| `tlsHandshakeTimeoutMs` | `tht` | `tht` | таймаут TLS-рукопожатия |

### `UploadChunkPayload` / `UploadEndPayload` (PHP → Go, фаза 2)

Команды `UploadChunk`/`UploadEnd` оборачивают `UploadPayloadParameters`
(зеркаль Go-структуры `payloads.UploadParams`):

| PHP-поле | ключ `getData()` | Назначение |
|---|---|---|
| `requestId` | `rid` | id сессии загрузки (совпадает с `rid` открывшего `Request`) |
| `body` | `b` | байты чанка (пусто для `UploadEnd`) |

### Результаты (Go → PHP)

Одно стриминговое состояние, **двухфазный** payload:

- **Первый результат** (из `exec`) — метаданные ответа, msgpack-структура:
  ```
  { st: int status, hd: map<string,[]string> headers, b: string firstChunk }
  ```
  `HasNext=true`, если тело не дочитано; иначе `HasNext=false` (тело целиком в `b`).
- **Последующие результаты** (из `next(bodyKey)`) — **сырой чанк тела** (string),
  как у `bodyState` HTTP-сервера. Последний чанк — `HasNext=false`.
- **Ошибка** — `NewErrorResult` с маркером класса (`net`/`req`/общий) в payload.

PHP различает фазы по taskKey: результат `exec` → метаданные (декодируем
структуру), результаты `next` стрима тела → сырые чанки.

---

## Go-сторона

`ext/internal/features/httpclient/`:

- `payloads/payloads.go` — `RequestPayload` (1:1 с PHP, msgpack-теги, кросс-ссылка
  `// PHP: SConcur\Features\HttpClient\Payloads\RequestPayload`).
- `client.go` — синглтон `*http.Client` + `*http.Transport` (`sync.Once`):
  пул соединений, keep-alive, таймауты транспорта, TLS-конфиг, политика
  редиректов (`CheckRedirect`). `CloseIdleConnections()` для shutdown.
- `response_state.go` — `responseState` (`contracts.StateContract`):
  - поля: `mutex`, `ctx`, `message`, `request *http.Request`, `chunkSize`,
    `maxResponseBody`, `startTime`, `resp *http.Response`, `bodyReader io.Reader`,
    `headersSent bool`.
  - `Next()`:
    - **первый вызов** (`resp == nil`): `client.Do(req.WithContext(ctx))` →
      получить `resp`; обернуть `resp.Body` в `http.MaxBytesReader`-аналог при
      `maxResponseBody>0`; прочитать первый чанк (до `chunkSize`); вернуть
      **метаданные** (msgpack `{st, hd, b:firstChunk}`); `HasNext` = есть ли ещё
      тело. Ошибки `Do` → классифицировать (`net`/`req`) → `NewErrorResult`.
    - **последующие вызовы**: `readChunk(bodyReader, chunkSize)` (переиспользовать
      хелпер из `body_state.go` или вынести в общий `internal/helpers`) → сырой
      чанк; `HasNext` по EOF. Превышение `maxResponseBody` → маркер ошибки.
  - `Close()`: `resp.Body.Close()` на **свежем** контексте
    (`context.Background()` + таймаут) — контекст задачи к очистке уже отменён.
- `upload.go` — стриминг тела запроса (фаза 2): сессия загрузки `uploadSession` +
  `io.Pipe`, отданный как `req.Body`; команды `UploadChunk`/`UploadEnd` пишут чанки
  в pipe (write-backpressure), метаданные ответа отдаются после `UploadEnd`.
- `feature.go` — `HttpClientFeature` (`contracts.FeatureContract`, синглтон):
  `Handle(task)`: разобрать `RequestPayload`, собрать `*http.Request`
  (метод, URL, заголовки, тело-`strings.NewReader`), `ctx, cancel :=
  context.WithTimeout(task.GetContext(), requestTimeout)`, создать `responseState`
  и запустить через `states.Get().Start(ctx, message.TaskKey, state)`; вернуть
  первый результат (метаданные). Тот же `Handle` обслуживает `next` через общую
  маршрутизацию состояний (отдельно настраивать не нужно).
- Регистрация в `ext/internal/features/factory.go`:
  ```go
  case types.MethodHttpClient:
      return httpclient_feature.Get(), nil
  ```
  и `CloseIdleConnections()` в `features.Shutdown()`.

> ⚠️ Оба обязательных требования: работа на `task.GetContext()` (отмена при стопе),
> жёсткий лимит времени из payload, `Close()` на свежем контексте.

---

## PHP-сторона

`src/Features/HttpClient/`:

- `HttpClient.php` — `ClientInterface`. `sendRequest()`:
  1. собрать `RequestPayload` из `RequestInterface` (метод, URL, заголовки, тело —
     v1 целиком) + опции;
  2. `$result = FeatureExecutor::exec($payload)` (в корутине — suspend, иначе wait);
     обернуть ошибки расширения в PSR-18 исключения по маркеру;
  3. декодировать метаданные (`status`, `headers`, `firstChunk`);
  4. `$response = $this->responseFactory->createResponse($status)`; навесить
     заголовки (`withAddedHeader` для мультизначных);
  5. `$body = new ResponseBodyStream($firstChunk, $result->hasNext ? $result->key : '')`;
     `$response = $response->withBody($body)`;
  6. вернуть `$response`.
- `HttpClientOptions.php` — `readonly` DTO опций (таблица выше).
- `Payloads/RequestPayload.php` — payload (таблица выше).
- `Dto/ResponseBodyStream.php` — `StreamInterface` (см. [Стриминг ответа](#стриминг-ответа)).
- `src/Exceptions/HttpClient/` — `HttpClientException`, `NetworkException`,
  `RequestException` (PSR-18 интерфейсы + базы по правилам исключений).

---

## Жизненный цикл и очистка ресурсов

- **Async (в корутине).** Флоу принадлежит корутине; `exec`/`next` приостанавливают
  её, `Scheduler` возобновляет. По завершении корутины флоу очищается, отмена
  контекста закрывает `resp.Body` через `Close()` состояния.
- **Sync (вне Fiber).** `exec` с `HasNext=true` регистрирует sync-task-flow
  (`State::registerSyncTaskFlow`), `next` его переиспользует, последний батч/ранний
  отказ — `State::releaseSyncTaskFlow` (вся машинерия уже есть в `FeatureExecutor`).
- **Ранний отказ от тела.** Если пользователь не дочитал поток и отпустил
  `ResponseBodyStream` (`close()`/`__destruct`), он зовёт `releaseTask()` —
  как `IteratorResult`. На Go отмена контекста задачи → хук состояний → `Close()` →
  `resp.Body.Close()`. **Поэтому `Close()` обязан работать на свежем контексте.**
- **Полностью инлайновое тело** (`bodyKey===''`): состояние не регистрируется,
  освобождать нечего.

---

## Тестирование

**PHP feature-тест** (`tests/feature/Features/HttpClient/`), родитель
`BaseAsyncTestCase` (обязательно): два конкурентных `sendRequest` через
`WaitGroup`, проверка порядка событий, **конкурентности** (общее время ≈ самого
медленного запроса, а не суммы) и пути с исключением (sync + async). Эталон —
`SleeperTest`. Дополнительно краевые тесты от `BaseTestCase`:

- статус/заголовки/тело, мультизначные заголовки;
- бинарное тело;
- **стриминг ответа**: chunked/SSE-эндпоинт, чтение `read()` по кускам, проверка
  что тело не буферизуется целиком и корутина уступает между чанками;
- ранний `break`/отказ от тела → отсутствие висящих задач (`BaseTestCase::tearDown`);
- сетевые ошибки → `NetworkException` (PSR-18), битый запрос → `RequestException`;
- `4xx`/`5xx` → нормальный `ResponseInterface`, не исключение;
- таймаут запроса → `NetworkException`.

**Тестовый сервер-цель.** Переиспользовать существующий HTTP-сервер SConcur:
поднимать `tests/servers/http/http-server.php` через харнесс
`SConcur\Tests\Impl\HttpServer\TestHttpServer` (уже есть `/stream`, `/echo`,
`/status/{code}`, `/msleep/{ms}` — почти всё нужное; добавить недостающие
маршруты при необходимости). Это даёт сквозную проверку **клиент↔сервер** в одной
кодовой базе.

**Go-тесты** (`make ext-test`): `httptest.Server` как цель; проверка метаданного
первого результата, стриминга тела чанками, лимита `maxResponseBody`, таймаутов,
отмены контекста (`Close` закрывает `resp.Body`), классификации ошибок.

**Совместимость PSR-18.** Прогнать `php-http/client-integration-tests` (опционально
в dev) — стандартный набор для проверки соответствия `ClientInterface`.

**Бенчмарк** (`tests/benchmarks/`): N конкурентных GET через `WaitGroup` vs
нативный последовательный `curl`/stream — показать «веер».

---

## Объём и будущие расширения

| Что | Статус | Комментарий |
|---|---|---|
| Стриминг **тела запроса** (upload) | ✅ фаза 2 | `streamRequestBody`; write-команды envelope'а (`UploadChunk`/`UploadEnd`), `io.Pipe` на Go. |
| Стриминг тела запроса из `StreamInterface` лениво | ✅ фаза 2 | `HttpClient::sendStreaming()` читает тело чанками `chunkSize`. |
| HTTP/2, h2c | ❌ | `net/http` HTTP/1.1 по умолчанию; h2 — позже. |
| Cookie-jar, авто-`Set-Cookie` | ❌ | На стороне приложения/PSR-7 middleware. |
| Прокси, кастомный CA-bundle | ❌ | Позже опциями (Go `Transport.Proxy`/`TLSClientConfig`). |
| PSR-18 **async** (`sendAsyncRequest`/HTTPlug) | ❌ | Конкурентность достигается через `WaitGroup`, не через промисы. |

---

## Чеклист реализации

**PHP:**
- [x] `composer.json`: `psr/http-client`, `psr/http-message`, `psr/http-factory`
      в `require`; `nyholm/psr7` в `require-dev`.
- [x] `MethodEnum::HttpClient = 5`.
- [x] `Payloads/RequestPayload` + `RequestPayloadParameters` (envelope, таймаут, кросс-ссылка `Go:`).
- [x] `HttpClientCommandEnum` + `Payloads/UploadChunkPayload`/`UploadEndPayload` (фаза 2).
- [x] `HttpClientOptions` (`readonly`, включая опции пула и `streamRequestBody`).
- [x] `HttpClient implements ClientInterface` (`sendRequest`, `sendStreaming`).
- [x] `Dto/ResponseBodyStream implements StreamInterface` (стрим + `releaseTask`).
- [x] `Exceptions/HttpClient/{HttpClientException,NetworkException,RequestException}`.
- [x] Тесты: `HttpClientConcurrencyTest` (конкурентность) + `HttpClientTest` (краевые:
      стриминг, ошибки PSR-18, ранний отказ, таймаут).

**Go:**
- [x] `types/method.go`: `MethodHttpClient = 5`; `types/httpclient.go`: `HttpClientCommand`.
- [x] `features/httpclient/payloads/payloads.go` (1:1 с PHP, кросс-ссылка `// PHP:`).
- [x] `features/httpclient/client.go` (синглтон `http.Client`/`Transport`, пул).
- [x] `features/httpclient/response_state.go` (`StateContract`: метаданные → чанки,
      `Close` на свежем контексте).
- [x] `features/httpclient/upload.go` (стриминг тела запроса через `io.Pipe`, фаза 2).
- [x] `features/httpclient/feature.go` (`Handle`: ctx+timeout, `states.Start`).
- [x] Регистрация в `features/factory.go` + `CloseIdleConnections` в `Shutdown`.
- [x] Go-тесты с `httptest.Server` (`feature_test.go`, `response_state_test.go`).

**Документация:**
- [x] `docs/http-client.ru.md` (по образцу `docs/http-server.ru.md`).
- [x] Ссылка из README `## Планы` и из `.ai/README.md`.

**Бенчмарк:**
- [x] `tests/benchmarks/http-client.php`, `tests/benchmarks/http-client-google.php`.

**Не сделано (опционально):**
- [ ] Прогон `php-http/client-integration-tests` для формальной PSR-18-совместимости.

**Проверка:** `make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test`.

---

## Открытые вопросы

1. ~~**Источник `ResponseFactoryInterface`.**~~ Решено: строго обязательный
   аргумент конструктора, без авто-discovery.
2. ~~**`maxResponseBody` по умолчанию.**~~ Решено: `0` (без лимита), с
   предупреждением про OOM в докблоке `HttpClientOptions`.
3. ~~**Редиректы.**~~ Решено: на Go (`http.Client.CheckRedirect`). При стриминге
   тела запроса (фаза 2) редирект с повторной отправкой тела нетривиален —
   совместимы редиректы + буферизованное тело.
4. ~~**`getSize()` потока.**~~ Решено: прокидываем `Content-Length` в метаданные
   первого результата (ключ `cl`), `ResponseBodyStream` отдаёт его из `getSize()`,
   иначе `null`.
5. ~~**Фаза 2 (стриминг upload)**~~ Решено: реализована в этом же плане (envelope
   `HttpClientCommandEnum`), отдельный план-файл не понадобился.
