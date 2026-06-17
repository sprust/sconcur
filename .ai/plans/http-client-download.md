# HTTP-клиент: скачивание в файл (Go-side sink) — план

Скачивание ответа **напрямую в файл на Go-стороне**: когда у запроса задан
путь-приёмник, расширение само открывает файл и копирует тело ответа в него
через `io.CopyBuffer(file, resp.Body, buf)` — тело **не выходит в PHP вообще**.

Зачем (в отличие от «тянуть тело чанками в PHP и писать `File`/`fwrite`»):
- **константная память** на любой размер файла (буфер копирования ~64 КиБ в Go);
- **ноль round-trip'ов** за чанки — одна задача, один результат с метаданными;
- **чистый async**: корутина приостанавливается один раз и просыпается, когда
  файл целиком записан; сетевая латентность прячется за другими корутинами.

Эталоны: текущий `httpclient` (`feature.go` — построение клиента/запроса,
дедлайн, маркеры ошибок) и приём «режим→`os.O_*` на Go» из File-фичи.

---

## Решения (согласовано)

1. **Энам режима** — `DownloadFileMode`: `Replace` (w), `Create` (x, ошибка если
   файл есть), `Append` (a). Человекопонятный на PHP; флаги `os.O_*` — на Go.
2. **Результат** — `DownloadResult` DTO: `statusCode`, `headers` (как отдал
   сервер), `filesize` (точное число записанных байт из `io.Copy` — доступно
   всегда, в т.ч. при chunked без `Content-Length`), `executionMs` (время).
3. **Размер батча скачивания** — параметр `bufferSize` метода `download()` с
   дефолтом (65536), уходит в Go (`io.CopyBuffer`).
4. **Только 2xx пишет файл.** Не-2xx и любая транспортная/файловая ошибка →
   **исключение** (`DownloadException`); при не-2xx файл **не трогаем** (не
   создаём и не усекаем существующий — статус проверяется до открытия файла).
5. **Версия** — бамп `0.2.2 → 0.2.3` (первая протокол-правка на ветке).

---

## Публичный API (PHP)

```php
$result = $httpClient->download(
    request: $request,                       // PSR-7 RequestInterface (обычно GET)
    path: '/var/data/big.iso',
    mode: DownloadFileMode::Replace,         // дефолт
    bufferSize: 1 << 20,                      // опц., дефолт 65536
    perm: 0644,                               // опц.
);

$result->statusCode;    // 200 (всегда 2xx — иначе исключение)
$result->bytesWritten;  // сколько байт записано в файл
$result->executionMs;   // время операции
```

`sendRequest()` (PSR-18, стриминг тела) **не трогаем** — `download()` это
отдельный удобный метод поверх той же машинерии.

Исключения:
- не-2xx → `DownloadException` (несёт `?int $statusCode`);
- сеть/файл/таймаут → `DownloadException` (с `previous`).

---

## Протокол PHP ↔ Go

Переиспользуем команду **Request** (`HttpClientCommandEnum::Request`), добавив в
`RequestPayloadParameters` / Go `RequestParams` поля приёмника:

| Поле | msgpack | Тип | Смысл |
|------|---------|-----|-------|
| `sinkPath`           | `sp`  | string | путь файла-приёмника; `''` = обычный стриминг (без sink) |
| `sinkMode`           | `sm`  | int    | `DownloadFileMode` (1/2/3); 0 = нет sink |
| `sinkPerm`           | `spm` | int    | права создания, дефолт 0644 |
| `downloadBufferSize` | `dbs` | int    | размер буфера `io.CopyBuffer`; ≤0 → дефолт |

Когда `sinkPath != ''` → Go идёт по download-ветке (см. ниже). Результат —
один success-результат с msgpack `{ st: status, hd: headers }` (без hasNext).
Размер файла не передаём — он в `Content-Length` (если сервер его прислал).

---

## Go-сторона

`handleRequest` (feature.go) ветвится: если `SinkPath != ""` → `handleDownload`.
Ограничение v1: **sink несовместим с `StreamBody`** (стриминговый upload тела
запроса) — если оба заданы, вернуть request-ошибку. Download использует
буферизованное тело запроса (GET / небольшой POST).

`download.go`:
```
func (f *HttpClientFeature) handleDownload(task, ctx, client, request, params):
    start := time.Now()
    resp, err := client.Do(request)
    if err != nil { -> networkError result }       // транспортная ошибка
    defer resp.Body.Close()

    if resp.StatusCode < 200 || resp.StatusCode >= 300 {
        // файл НЕ трогаем; PHP бросит DownloadException(status)
        return success { st: status, hd: headers }
    }

    flags, ok := downloadModeToFlags(params.SinkMode)   // Replace/Create/Append
    if !ok { -> request error "invalid sink mode" }

    perm := params.SinkPerm; if perm == 0 { perm = 0644 }
    file, err := os.OpenFile(params.SinkPath, flags, perm)
    if err != nil { -> file error }                  // напр. Create и файл есть (O_EXCL)

    buf := make([]byte, bufferSizeOrDefault(params.DownloadBufferSize))
    written, copyErr := io.CopyBuffer(file, resp.Body, buf)
    closeErr := file.Close()

    if copyErr != nil {
        removeOnFailure(params.SinkPath, params.SinkMode)  // удалить для Replace/Create
        -> file/network error
    }
    if closeErr != nil { -> file error }

    return success { st: status, hd: headers }   // written не отдаём — размер из Content-Length
```

- **Дедлайн/отмена.** Запрос строится на `task.GetContext()` + `RequestTimeoutMs`
  (как сейчас). `io.Copy` читает `resp.Body`, привязанное к контексту запроса:
  стоп флоу/дедлайн прерывают копирование, файл закрывается, частичный —
  удаляется (для Replace/Create). Это и есть «предельное время выполнения»
  (требование 2). Замечание: для больших файлов `requestTimeoutMs` нужно поднять
  (он бьёт по всей операции) — отметить в доке.
- **Маркеры ошибок** — те же `net:` / `req:`; добавим, что файловые ошибки
  тоже уходят как ошибка (PHP завернёт в `DownloadException`).
- `downloadModeToFlags`: `Replace→O_WRONLY|O_CREATE|O_TRUNC`,
  `Create→O_WRONLY|O_CREATE|O_EXCL`, `Append→O_WRONLY|O_CREATE|O_APPEND`.

Построение `client`/`request` выносим в переиспользуемый хелпер (сейчас оно
инлайн в `handleRequest`), чтобы и стриминг, и download его звали.

---

## PHP-слой (`src/Features/HttpClient/`)

- `DownloadFileMode` — enum (`Replace=1`, `Create=2`, `Append=3`),
  кросс-ссылка на Go `downloadModeToFlags`.
- `RequestPayloadParameters` — добавить `sinkPath`/`sinkMode`/`sinkPerm`/
  `downloadBufferSize` (дефолты: `''`/`0`/`0`/`0` — нет sink). `getData()` +
  ключи `sp`/`sm`/`spm`/`dbs`.
- `HttpClient::download(...)` — собрать `RequestPayload` с sink-полями,
  `FeatureExecutor::exec` (один результат), разобрать `{st, hd}`; не-2xx →
  `DownloadException(statusCode)`; иначе `DownloadResult`. Транспортные/файловые
  ошибки (`TaskErrorException`) завернуть в `DownloadException` (preserve
  previous; net/req-маркер можно сохранить в сообщении).
- `Dto/DownloadResult` — `readonly { int statusCode; array<string, list<string>>
  headers; int filesize; int executionMs }` (filesize — байты из `io.Copy`).
- Исключение `SConcur\Exceptions\HttpClient\DownloadException extends
  RuntimeException implements ClientExceptionInterface` (рядом с
  `HttpClientException`), с `?int $statusCode`.
- `HttpClientOptions` — опц. дефолт `downloadBufferSize` (или только параметр
  метода). Предлагаю: параметр метода `download(bufferSize:)` с дефолтом-константой,
  без раздувания опций.

---

## Тесты

- **PHP `DownloadTest`** (от `BaseHttpClientTestCase` — реальный `TestHttpServer`):
  - GET 200 → файл создан, содержимое == тело ответа, `bytesWritten`/`statusCode`
    корректны;
  - не-2xx (роут 404/500) → `DownloadException`, `statusCode` проставлен, файл не
    создан/не изменён;
  - `Create` по существующему файлу → `DownloadException` (O_EXCL);
  - `Append` дописывает к существующему; `Replace` усекает;
  - кастомный `bufferSize`;
  - сетевая ошибка (битый хост/порт) → `DownloadException`;
  - **конкурентное** скачивание нескольких файлов в `WaitGroup` (async) — проверка
    «веера» и отсутствия висящих задач.
  - Файлы — во временный каталог (`tests/storage/files` если ветка вольётся после
    File-фичи; иначе `sys_get_temp_dir()` — независимо от File-ветки).
- **Go** (`feature_test.go` httpclient): `downloadModeToFlags` (все режимы +
  невалидный); `handleDownload` против `httptest.Server` — 200 пишет файл, не-2xx
  не пишет, copy на отменённом контексте чистит частичный файл.

---

## Документация и сопутствующее

- `docs/http-client.ru.md` — раздел «Скачивание в файл»: API, режимы, поведение
  при не-2xx, таймаут на больших файлах, отличие от стриминга тела в PHP.
- `.ai/README.md` — описание httpclient дополнить sink-веткой; `README.md` —
  при необходимости.
- Бамп `0.2.2 → 0.2.3` в `ext/main.go` и `src/Connection/Extension.php`.

Проверка: `make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test`.

---

## Открытые вопросы / заметки

1. **`sink + streamRequestBody`** — в v1 не поддерживаем (ошибка). Расширяемо
   позже (download с потоковым upload-телом — редкий кейс).
2. **Частичный файл при обрыве** — удаляем для `Replace`/`Create`, для `Append`
   оставляем (нельзя безопасно откатить дозапись). Отметить в доке.
3. **`bufferSize`** — параметр метода с дефолтом 65536 (не глобальная опция).
4. **Редиректы** — следуются как обычно (`followRedirects`), к моменту записи
   статус уже финальный.
