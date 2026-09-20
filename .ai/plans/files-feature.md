# Фича Files: файловые операции в расширении

`Files::*` — SConcur-эквиваленты файловых функций PHP. Нативные `file_get_contents`,
`fwrite`, `copy`, `scandir` ждут диск, блокируя весь PHP-поток: ни автоматическая
преемпция (см. [docs/coroutine-switching.ru.md](../../docs/coroutine-switching.ru.md)),
ни планировщик тут ничего не могут — поток стоит в syscall. В HTTP-воркере одно чтение
с холодного диска задерживает все остальные корутины процесса. Со `Files` вызов уезжает
в задачу рантайма: подвисает только своя корутина.

Фича вынесена из [std-feature.md](std-feature.md): там файловая группа была одной из
трёх, а домен на два десятка команд со стримингом придавил бы sleep и `password_*`. За
`Std` остаются sleep и пароли.

## Решения владельца (зафиксированы)

- Отдельная фича: `MethodEnum::Files` (`fls`), `src/Features/Files/`,
  `ext/src/features/files/`, своя дока и своя группа бенчмарков.
- Объём v1 — полный: разовые операции, метаданные, каталоги, `copy`/`move`/`hashFile`,
  плюс стриминг чтения и записи.
- Публичный API — статический фасад `Files::*`, как у `Sleeper`. Объект с корневым
  каталогом (песочница в духе Laravel Storage) — возможное продолжение, не v1.
- Отдача файла в HTTP-ответ мимо PHP (аналог `X-Sendfile`) в эту фичу не входит: это
  правка `HttpServer`, а не файловой фичи.

## Цель и не-цели

- Цель: ни один файловый вызов не замораживает воркер. Единый API для sync и async, как
  у каждой фичи.
- Цель: операции, где байты и syscall'ы вообще не пересекают границу — `copy`,
  `hashFile`, `list` с метаданными, обход каталога. Там выигрыш не зависит от замера.
- Не-цель: обогнать нативный вызов на синхронном пути. Граница прибавляется к той же
  работе; вне корутин нативные функции остаются правильным выбором.
- Не-цель: заменить потоковый API PHP целиком. Дескриптора в духе `fopen` не будет,
  см. «Сознательно исключено».

## Почему это вообще работает: blocking-пул

`ext/src/core.rs` собирает рантайм с `worker_threads` = 1 по умолчанию
(`SCONCUR_RUNTIME_THREADS` поднимает). Значит файловый I/O обязан идти через
`tokio::fs`, которая уводит каждый syscall в blocking-пул (`spawn_blocking`), а не на
единственный worker-поток. Прямой `std::fs` в обработчике встал бы поперёк всего
рантайма — включая HTTP-сервер того же процесса. Это требование корректности фичи, а не
деталь реализации; в `ext/src/features/files/` не должно быть ни одного синхронного
файлового вызова.

Следствия, которые надо закрыть в этой же работе:

- `tokio` в `ext/Cargo.toml` собирается без фичи `fs` — сейчас `tokio::fs` в
  `httpclient` компилируется только потому, что `fs` включает кто-то из зависимостей
  через унификацию фич. Добавить `"fs"` явно.
- Размер blocking-пула (дефолт tokio — 512 потоков) становится настоящей границей
  параллелизма файловых операций. Задать его явно через `.max_blocking_threads()` с
  env-override `SCONCUR_BLOCKING_THREADS`: `Files` — первая фича, которая этот пул
  реально грузит, и молчаливый дефолт библиотеки не должен решать за воркер.

## Отмена и дедлайн: честная семантика

Оба обязательных требования
[docs/adding-a-feature.ru.md](../../docs/adding-a-feature.ru.md) соблюдаются: каждый payload
несёт `timeoutMs`, каждый обработчик слушает `task.context()`. Но для файлов надо
записать в доку то, чего нет у сетевых фич:

- Задачу в blocking-пуле нельзя прервать посреди syscall. Дедлайн и `WaitGroup::stop()`
  означают «перестать ждать результат», а не «прервать чтение». Поток blocking-пула
  освободится, когда ядро вернёт управление.
- Для операций, идущих кусками (стриминговое чтение, `copy`, обход каталога, слив
  чанков writer'а), отмена настоящая: проверяется между кусками, и незавершённый файл
  удаляется по тому же правилу, что `drop_partial` в `httpclient`.

## Состав v1

Одна `MethodEnum::Files` и командный конверт (`FilesCommandEnum`, поле `cm`) — паттерн
`Redis`/`Amqp`: параметры файловых команд это плоские карты коротких ключей без логики,
класс на команду не купил бы ничего сверх именованного аргумента на месте вызова.

### Содержимое, разовые операции

| Команда | Код | API | Что делает |
| --- | --- | --- | --- |
| Read | `rd` | `Files::read(path, offsetBytes, lengthBytes)` | Чтение целиком или диапазона. Диапазон закрывает `fseek`+`fread` без дескриптора |
| Write | `wr` | `Files::write(path, contents, mode, permissions)` | Запись/дозапись/создание |
| WriteAtomic | `wra` | `Files::writeAtomic(path, contents, permissions)` | Временный файл рядом → `sync_all` → `rename` |
| Truncate | `tr` | `Files::truncate(path, sizeBytes)` | Обрезка |

`mode` — enum `FileWriteMode` с той же семантикой, что `DownloadFileMode` у
`HttpClient` (Replace `rpl`, Create `crt`, Append `app`). Второй enum, а не
переиспользование чужого: `DownloadFileMode` документирован как режим открытия sink'а
HTTP-загрузки, и файловая фича не должна зависеть от фичи HTTP-клиента. Значения на
проводе совпадают, и обе стороны это комментируют.

`writeAtomic` — ровно то, что `src/Worker/MasterStateFile.php` делает руками и что
нужно файловому кэшу: читатель видит либо старое содержимое, либо новое, никогда
полузаписанное. При ошибке временный файл удаляется.

### Байты не пересекают границу

| Команда | Код | API | Что делает |
| --- | --- | --- | --- |
| Copy | `cp` | `Files::copy(source, destination, mode, permissions)` | Копирование целиком внутри расширения |
| Move | `mv` | `Files::move(source, destination)` | `rename`, а через границу устройств — копирование с удалением исходника |
| Delete | `dl` | `Files::delete(path, missingOk)` | Удаление файла |
| HashFile | `hsh` | `Files::hashFile(path, algorithm)` | Контрольная сумма файла любого размера |

Это самая однозначная группа: выигрыш есть при любом размере, потому что через границу
едут только путь и итог.

`algorithm` — enum `FileHashAlgorithm`: sha256, sha512, sha1, md5. Крейты уже лежат в
`ext/Cargo.lock` транзитивно (`sha2`, `sha1`, `md-5`) — их надо только поднять в
`[dependencies]` как прямые, с комментарием в стиле остальных записей файла. Ничего
нового не скачивается.

### Метаданные

| Команда | Код | API | Что делает |
| --- | --- | --- | --- |
| Stat | `st` | `Files::stat(path, followSymlinks)` | Один вызов вместо `file_exists`+`is_dir`+`filesize`+`filemtime` |
| Chmod | `chm` | `Files::chmod(path, permissions)` | Права |
| Touch | `tch` | `Files::touch(path, modifiedAtMs, permissions)` | Создание/обновление времени |
| RealPath | `rp` | `Files::realPath(path)` | Канонизация пути |
| TemporaryFile | `tmp` | `Files::temporaryFile(directory, prefix, suffix, permissions)` | Уникальный файл, возвращается путь |

`Files::exists(path)` — обёртка над `Stat` на стороне PHP, не отдельная команда.

`Stat` отвечает DTO `Dto\FileStat`: `exists`, `isFile`, `isDirectory`, `isSymlink`,
`sizeBytes`, `modifiedAtMs`, `accessedAtMs`, `createdAtMs`, `permissions`, `userId`,
`groupId`.

### Каталоги

| Команда | Код | API | Что делает |
| --- | --- | --- | --- |
| MakeDirectory | `mkd` | `Files::makeDirectory(path, permissions, recursive)` | Создание |
| RemoveDirectory | `rmd` | `Files::removeDirectory(path, recursive, missingOk)` | Удаление |
| List | `ls` | `Files::list(path, pattern, withMetadata)` | Список записей одним переходом границы |

`list` — второй по силе кейс после `copy`. `scandir` + `filesize`/`filemtime` на каждую
запись это 1 + N syscall'ов, каждый из которых держит PHP-поток. Здесь все N остаются
внутри расширения, а через границу едет готовый список `Dto\DirectoryEntry`
(`name`, `path`, `isDirectory`, `isSymlink`, `sizeBytes`, `modifiedAtMs`). `withMetadata: false`
пропускает `metadata()` на записях, когда нужны только имена. `pattern` — простой
шаблон (`*`, `?`, `[...]`), фильтрация в расширении, чтобы каталог на сто тысяч файлов
не ехал в PHP целиком.

### Стриминг

| Команда | Код | API | Что делает |
| --- | --- | --- | --- |
| ReadChunks | `rdc` | `Files::readChunks(path, bufferSizeBytes)` | `Iterator<int, string>` — файл батчами |
| ReadLines | `rdl` | `Files::readLines(path, batchLines, bufferSizeBytes, maxLineBytes)` | `Iterator<int, string>` — строками |
| Walk | `wlk` | `Files::walk(path, pattern, withMetadata, batchEntries)` | `Iterator<int, DirectoryEntry>` — обход дерева батчами |
| WriteOpen | `wro` | `Files::openWriter(path, mode, permissions)` | Открывает писателя, отдаёт `FileWriter` |
| WriteChunk | `wrc` | `$writer->write(chunk)` | Чанк; ждёт, пока расширение его заберёт |
| WriteClose | `wrx` | `$writer->close()` | Закрывает и отдаёт число записанных байт |

Стриминг чтения закрывает главную дыру разового: `Files::read()` большого файла держит
пик памяти в два размера файла (буфер в расширении плюс строка в PHP). `readChunks`
держит один буфер.

`readLines` — не сахар: `fgets` в цикле это худший блокирующий паттерн, какой есть в
обработке логов и CSV. Разбиение на строки делается в расширении, батчами.

Писатель задуман был по образцу upload-сессий `HttpClient` (`UploadSession`) — с
каналом чанков. **Сделан проще:** сессия это файл под мьютексом, `WriteChunk` пишет
в него напрямую и не отвечает, пока запись не прошла, — ожидание на этом вызове и
есть весь backpressure. Канал нужен `HttpClient`, потому что reqwest требует поток
тела; здесь писать некому, кроме нас самих.

`WriteOpen` регистрирует сессию в `Registries` (живут на `Core`, чтобы fork не
унаследовал дескрипторы) и заводит состояние через
`states::get().register_with_flow()` под ключом с префиксом. Идентификатор писателя
генерит PHP, как `requestId` у `HttpClient`. Брошенный писатель — корутина умерла,
флоу кончился — закрывается хуком состояния; файл удаляется **только если писатель
сам его создал**, то есть только в режиме Create, по тому же правилу, что
`drop_partial`.

Ограничитель разового чтения: payload несёт `maxReadBytes` (аналог `maxResponseBody` у
`HttpClient`). Расширение отказывается читать **запрошенный диапазон** больше лимита
вместо того, чтобы съесть память процесса. Дефолт — 64 МиБ, переопределяется на
вызове; `0` снимает лимит.

### Дедлайн вызова

Каждый payload обязан нести `timeoutMs` — это требование
[docs/adding-a-feature.ru.md](../../docs/adding-a-feature.ru.md). У фасада без состояния его
неоткуда взять, кроме как с вызова, поэтому `timeoutMs` — последний необязательный
параметр каждой команды, по умолчанию `Files::DEFAULT_TIMEOUT_MS` (30 000). `0` значит
«без дедлайна», как везде в проекте, где дедлайн берётся.

Писатель — единственное исключение: `openWriter()` принимает `timeoutMs` один раз, и он
действует на каждый `write()` и на `close()`, потому что дедлайн у сессии записи общий.

## Как это выглядит в коде

Ниже — целевой вид публичного API. Это и есть то, что уедет в `docs/files.md`, когда
фича будет готова.

### Разовые операции

```php
use SConcur\Features\Files\FileHashAlgorithm;
use SConcur\Features\Files\Files;
use SConcur\Features\Files\FileWriteMode;

$contents = Files::read(path: '/var/app/storage/report.csv');

// Диапазон вместо fopen + fseek + fread: первый килобайт, чтобы определить тип.
$header = Files::read(
    path:        '/var/app/storage/upload.bin',
    offsetBytes: 0,
    lengthBytes: 1024,
);

$writtenBytes = Files::write(
    path:     '/var/log/app/audit.log',
    contents: $line,
    mode:     FileWriteMode::Append,
);

// Читатель видит либо старое содержимое, либо новое — никогда полузаписанное.
Files::writeAtomic(
    path:     '/var/app/cache/routes.php',
    contents: $compiled,
);

// Байты не пересекают границу: расширение стримит файл само.
$copiedBytes = Files::copy(
    source:      '/var/app/storage/original.zip',
    destination: '/var/app/backup/original.zip',
);

Files::move(
    source:      '/tmp/upload-9f2c',
    destination: '/var/app/storage/avatars/42.jpg',
);

Files::delete(path: '/tmp/upload-9f2c', missingOk: true);

// Контрольная сумма файла любого размера: через границу едут только путь и итог.
$checksum = Files::hashFile(
    path:      '/var/app/storage/release.tar.gz',
    algorithm: FileHashAlgorithm::Sha256,
);
```

### Метаданные и каталоги

```php
$fileStat = Files::stat(path: '/var/app/storage/report.csv');

if ($fileStat->exists && $fileStat->sizeBytes > 10_000_000) {
    // ...
}

// Один переход границы вместо file_exists + is_dir + filesize + filemtime.
$modifiedAtMs = $fileStat->modifiedAtMs;

Files::makeDirectory(
    path:        '/var/app/storage/exports/2026-09',
    permissions: 0755,
    recursive:   true,
);

// Все stat-вызовы по записям остаются внутри расширения; в PHP едет готовый список.
$entries = Files::list(
    path:         '/var/app/storage/exports/2026-09',
    pattern:      '*.csv',
    withMetadata: true,
);

foreach ($entries as $directoryEntry) {
    echo $directoryEntry->name . ' ' . $directoryEntry->sizeBytes . PHP_EOL;
}

Files::removeDirectory(path: '/var/app/storage/tmp', recursive: true);
```

### Стриминг чтения

```php
// Пик памяти — один буфер, а не два размера файла.
foreach (Files::readChunks(path: '/var/app/storage/huge.bin', bufferSizeBytes: 262_144) as $chunk) {
    $digest->update($chunk);
}

// Замена fgets в цикле: разбиение на строки делается в расширении, батчами.
foreach (Files::readLines(path: '/var/log/app/access.log') as $line) {
    if (str_contains($line, ' 500 ')) {
        ++$serverErrorCount;
    }
}

// Ранний break безопасен: флоу кончается, хук состояния закрывает файл.
foreach (Files::walk(path: '/var/app/storage', pattern: '*.tmp') as $directoryEntry) {
    if ($directoryEntry->modifiedAtMs < $threshold) {
        Files::delete(path: $directoryEntry->path);
    }
}
```

### Стриминг записи

```php
use SConcur\Exceptions\Files\FilesException;

$fileWriter = Files::openWriter(
    path: '/var/app/storage/export.csv',
    mode: FileWriteMode::Replace,
);

try {
    foreach ($rows as $row) {
        // Ждёт, пока расширение заберёт чанк, так что быстрый писатель не обгонит диск.
        $fileWriter->write(chunk: implode(',', $row) . "\n");
    }

    $writtenBytes = $fileWriter->close();
} catch (FilesException $exception) {
    // Недописанный файл удаляется расширением; close() здесь уже не нужен.
    throw $exception;
}
```

Писателя можно и не закрывать: если корутина умерла, флоу кончился, и хук состояния
закроет файл и уберёт недописанное сам. `close()` нужен, чтобы узнать число записанных
байт и поймать ошибку записи на месте.

### Конкурентность — то, ради чего фича существует

```php
use SConcur\WaitGroup;

$waitGroup = WaitGroup::create();

foreach ($paths as $path) {
    $waitGroup->add(static fn (): string => Files::hashFile(
        path:      $path,
        algorithm: FileHashAlgorithm::Sha256,
    ));
}

// Время ≈ самой долгой операции, а не их сумме. PHP-поток всё это время свободен.
$checksums = $waitGroup->waitResults();
```

Тот же вызов вне `WaitGroup` — обычный синхронный: конкурентность выбирает вызывающий,
а не автор фичи.

```php
$contents = Files::read(path: '/etc/app/config.json'); // блокирующий вызов, без корутины
```

### В обработчике HTTP-сервера

Главный кейс: тяжёлая файловая работа в одном запросе не задерживает соседние.

```php
$temporaryPath = Files::temporaryFile(directory: '/var/app/storage/tmp', prefix: 'upload-');

$fileWriter = Files::openWriter(path: $temporaryPath, mode: FileWriteMode::Replace);

while (!$request->getBody()->eof()) {
    $fileWriter->write(chunk: $request->getBody()->read(65_536));
}

$fileWriter->close();

$checksum = Files::hashFile(path: $temporaryPath, algorithm: FileHashAlgorithm::Sha256);

Files::move(
    source:      $temporaryPath,
    destination: '/var/app/storage/uploads/' . $checksum,
);
```

### Обработка ошибок

```php
use SConcur\Exceptions\Files\FileNotFoundException;
use SConcur\Exceptions\Files\FilePermissionException;
use SConcur\Exceptions\Files\FilesException;

try {
    $contents = Files::read(path: $path);
} catch (FileNotFoundException $exception) {
    $contents = '';
} catch (FilePermissionException $exception) {
    $this->logger->warning('no access to ' . $path, ['exception' => $exception]);

    throw $exception;
} catch (FilesException $exception) {
    // Любая другая ошибка ввода-вывода; причина — в getPrevious().
    throw $exception;
}
```

## Ошибки

Расширение префиксует текст ошибки коротким маркером вида ошибки, как `httpclient`
делает с `net:`/`req:`; PHP по маркеру выбирает исключение. Маппинг с
`std::io::ErrorKind` — одна функция в `ext/src/features/files/errors.rs`, покрытая
unit-тестом.

| ErrorKind | Маркер | Исключение (`SConcur\Exceptions\Files\`) |
| --- | --- | --- |
| `NotFound` | `nf` | `FileNotFoundException` |
| `PermissionDenied` | `pd` | `FilePermissionException` |
| `AlreadyExists` | `ae` | `FileAlreadyExistsException` |
| `IsADirectory`, `NotADirectory`, `DirectoryNotEmpty` | `ft` | `UnexpectedFileTypeException` |
| остальное | `io` | `FileOperationException` |

База — `FilesException extends RuntimeException` (ошибка ввода-вывода это runtime-условие,
что бы вызывающий ни делал). Ошибки применения — отрицательная длина, неизвестный алгоритм хеша, права вне
диапазона — это `InvalidFileArgumentException extends LogicException`, отдельная ветка,
как у `Redis`. `Create` поверх существующего — не она, а `FileAlreadyExistsException`:
занятый путь это состояние файловой системы, а не ошибка в коде.
Пойманный `Throwable` уходит в `previous`, `@throws` нигде не пишется.

## Сознательно исключено

- **Дескриптор в духе `fopen`** (`open`/`seek`/`tell`/`read`/`write`/`close`). Каждый
  вызов — переход границы, то есть самый дорогой способ сделать самую дешёвую вещь.
  Произвольный доступ закрыт `read(offsetBytes, lengthBytes)`, последовательный —
  стримингом.
- **`flock` и любые блокировки**, переживающие `await`. Нужен fd, живущий в расширении,
  и модель владения им — отдельная задача. «Атомарная запись», ради которой блокировку
  обычно и берут, закрыта `writeAtomic`.
- **`symlink`/`link`/`readlink`** — редки и дёшевы; добавляются по запросу.
- **Слежение за ФС (inotify)** — другой домен: стрим событий, а не операция над файлом.
- **`fgetcsv`, `file()` и прочий PHP-специфичный разбор** — парсинг остаётся в PHP
  поверх `readLines()`.
- **`glob()` с полной семантикой** — только простой шаблон в `list`/`walk`.
- **`disk_free_space`, `chown`** — по запросу, не v1.

## Бенчмарк-гейт

`tests/benchmarks/files/`, методология [docs/benchmarks.ru.md](../../docs/benchmarks.ru.md):
`read-small.php`, `read-large.php`, `write.php`, `copy.php`, `list-dir.php` (каталог на
10 000 записей), `hash-file.php`, `read-stream.php`. Размеры 1 КиБ / 1 МиБ / 100 МиБ,
плюс фан-аут 32 операций. Make-цели `bench-files-<operation>`, строки в общем списке
`bench-all`.

Команда проходит гейт, если выполнено хотя бы одно: (а) async быстрее натива на
фан-ауте, (б) E2E-тест показывает, что она не задерживает соседние корутины. Второй
критерий нужен потому, что фича про латентность и хвосты, а не про throughput: чтение
мелкого файла с горячим page cache — микросекунды, и никакая граница его не ускорит, но
освобождение PHP-потока всё равно меняет поведение сервера под нагрузкой.

Ожидаемые вердикты записаны заранее, чтобы гейт не переоткрывали с нуля:

- Синхронный путь всегда проигрывает нативному — цена границы, как у остальных фич.
- Мелкие файлы, горячий кэш: async не быстрее, выигрыш только в свободном PHP-потоке.
- Большие файлы, холодный кэш, сетевые ФС (NFS, ceph): выигрыш реальный.
- `copy`, `hashFile`, `list(withMetadata: true)`: выигрыш при любом размере.
- Фан-аут CPU-дешёвых файловых операций упирается в диск, а не в ядра, — в доку
  оговорка: `Files` не делает диск быстрее.

## Память

- mem-leak: `make mem-leak-files scenario=<name> seconds=<n>`, сценарии `read-large`,
  `read-stream`, `write-stream`, `copy`, `walk`, `abandoned`. Печатает RSS рядом с
  PHP-кучей и число открытых дескрипторов процесса — то, что держит файловый стрим, это
  нативная память, а `Extension::count()` считает выполняющиеся задачи, а не
  зарегистрированные состояния.
- Сценарий `abandoned` — главный: он проверяет, что хук состояния действительно
  закрывает файл, когда флоу кончился.

## Тесты

- `tests/feature/Features/Files/FilesTest.php` от `BaseAsyncTestCase`: две конкурентные
  операции, порядок событий, суммарное время ≈ самой долгой, а не сумме.
- `BaseTestCase`-тесты по командам: корректность против нативной функции, синхронный
  путь, пути ошибок (нет файла, нет прав, `Create` поверх существующего, файл вместо
  каталога), символьные ссылки в `stat(followSymlinks:)`, диапазонное чтение,
  атомарность `writeAtomic` (читатель никогда не видит полузаписанное), брошенный стрим
  и брошенный писатель (`tearDown` в `BaseTestCase` поймает висящие задачи), отмена
  `WaitGroup::stop()` посреди чтения и посреди записи.
- Rust unit-тесты (`make ext-test`): маппинг `ErrorKind` → маркер; фильтр шаблона;
  разбиение на строки, когда строка разорвана границей батча (классическое место бага);
  удаление недописанного файла по режимам.
- E2E: тяжёлое файловое чтение в HTTP-обработчике не задерживает лёгкий запрос
  (паттерн из coroutine-switching).

## Версионирование

Новый метод и новые payload'ы — изменение протокола PHP↔расширение: минорный бамп
`0.13.1` → `0.14.0`, один раз на ветке, все четыре источника вместе (список в
`.ai/README.md`, «Extension versioning»).

## Доки

- `docs/files.md` + `docs/files.ru.md`: использование, параметры по командам, семантика
  отмены и дедлайна в blocking-пуле, пороги целесообразности из бенчмарков, ограничения
  (`maxReadBytes`, отсутствие дескриптора и блокировок).
- README (обе версии): строки в таблице «What it replaces» для файловых функций; строка
  роадмапа про `Std` правится — файловый I/O из неё уходит сюда.
- `.ai/README.md`: фича в списках слоёв PHP и расширения, раздел enum'ов (`Files`,
  `FilesCommandEnum`, `FileWriteMode`, `FileHashAlgorithm`), ссылка на доку.
- [std-feature.md](std-feature.md): файловый раздел заменяется ссылкой сюда.

## Чем реализация отличается от плана

Записано по итогам ревью, чтобы следующий читатель не сверялся с неверным.

- Писатель — мьютекс над файлом, а не mpsc-канал (см. выше).
- Уборка после неудачной записи удаляет файл **только в режиме Create**: план говорил
  «кроме Append», а этого мало — неудавшийся Replace над существующим файлом сносил
  чужие данные.
- `list` читает каталог одним походом в blocking-пул. Повызовный `tokio::fs` делал
  листинг на 10 000 записей в девять раз медленнее `scandir` со stat на запись.
- Выигрыш `copy` — не «при любом размере», как обещал план, а после мегабайта.
  `list` выигрывает конкурентно и проигрывает синхронно, как всякая фича здесь.
- У батчей стримов есть собственный токен отмены: без него батч при `timeoutMs: 0`
  был бы и неограничен, и неотменяем.
- `chmod` берёт права буквально, остальные команды читают `0` как «по умолчанию».
- Писателя отравляет любой не прошедший до конца чанк, а не только оборванный: после
  него и запись, и закрытие отвергаются.
- Кросс-девайсный `move` идёт через временный файл рядом с назначением, а не копирует
  поверх него: иначе сбой оставлял бы назначение обрезанным, а уборка удаляла бы чужой
  файл.
- Размер blocking-пула не трогаем: он процесса, а не фичи, и его делит разрешение имён.

## Этапы

Каждый этап — кандидат на отдельный коммит.

1. **Каркас протокола.** `MethodEnum::Files` / `Method::Files`, `FilesCommandEnum`,
   payload-конверт, модуль `ext/src/features/files/` с диспатчем по команде и
   регистрацией в `detect_message_handler`, бамп версии, `tokio` feature `fs`,
   `max_blocking_threads` с env-override.
2. **Разовые операции с содержимым**: `read`, `write`, `writeAtomic`, `truncate`,
   `copy`, `move`, `delete` + маппинг ошибок + исключения + тесты.
3. **Метаданные и каталоги**: `stat`, `chmod`, `touch`, `realPath`, `temporaryFile`,
   `makeDirectory`, `removeDirectory`, `list` + DTO + тесты.
4. **`hashFile`**: прямые записи крейтов хеширования, enum алгоритмов, тесты против
   нативных `hash_file`/`md5_file`.
5. **Стриминг**: `readChunks`, `readLines`, `walk`, писатель
   (`openWriter`/`write`/`close`) + состояния + тесты на брошенные стримы.
6. **Бенчмарки и гейт**, mem-leak-сценарии.
7. **Доки** (en+ru), README, `.ai/README.md`, финальный `make check`.
