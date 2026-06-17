# File (файловая I/O-фича) — план

Асинхронная работа с файлами поверх Go `os.File`. Открытие, чтение, запись,
позиционирование и закрытие уходят в Go-расширение и исполняются в горутинах,
пока корутина приостановлена, — десятки файловых операций летят «веером», а
блокирующий syscall одного файла не тормозит остальные корутины. Вне `WaitGroup`
тот же API работает синхронно.

API «максимально по PHP-стайлу»: `open($path, $mode)` с полным набором режимов
`fopen` (`r`, `r+`, `w`, `w+`, `a`, `a+`, `x`, `x+`, `c`, `c+`), затем
`read()`/`write()`/`seek()`/`tell()`/`eof()`/`truncate()`/`flush()`/`close()` —
как `fread`/`fwrite`/`fseek`/`ftell`/`feof`/`ftruncate`/`fflush`/`fclose`.

**Эталон для копирования: `Sql`-транзакции** (`Transaction.php`,
`ext/internal/features/sql/transactions.go`). Файловый хэндл устроен так же:
удерживающая задача с `HasNext` закрепляет ресурс (`*os.File` вместо `*sql.Tx`)
за серией под-задач; под-команды роутятся по id хэндла через `sync.Map`; `close`
финализирует и освобождает удерживающую задачу через `next()`; обрыв флоу
(отмена контекста) закрывает fd автоматически.

---

## Цели и охват

**v1 (этот план):**

- **Открытие с полным набором режимов** — `FileSystem::open($path, $mode)`. Режимы
  `fopen`: `r`, `r+`, `w`, `w+`, `a`, `a+`, `x`, `x+`, `c`, `c+`. Суффикс `b`/`t`
  допускается и игнорируется (Unix — бинарно-безопасный по умолчанию).
- **Чтение** — `File::read(int $length): string` (как `fread`), плюс удобный
  `File::getContents(): string` (вычитывает до конца чанками).
- **Запись** — `File::write(string $data): int` возвращает число записанных байт
  (как `fwrite`).
- **Стриминг-I/O** — каждый `read()`/`write()` — отдельная async-задача; большой
  файл читается/пишется в цикле чанками, и на каждом чанке корутина
  приостанавливается, отдавая управление другим корутинам.
- **Позиционирование** — `seek(int $offset, int $whence = SEEK_SET): int`,
  `tell(): int`, `rewind(): void`, `eof(): bool`.
- **Прочие операции хэндла** — `truncate(int $size): void` (`ftruncate`),
  `flush(): void` (`fsync` на диск), `stat(): FileStat` (размер, mtime).
- **Закрытие** — `close(): void`; идемпотентно; `__destruct` подстраховывает
  освобождение незакрытого хэндла на синхронном пути.
- **Удобный фасад одноразовых операций** — `FileSystem::read($path)` (открыть `r` →
  прочитать всё → закрыть), `FileSystem::write($path, $data, $mode = 'w')`,
  `FileSystem::append($path, $data)`, `FileSystem::exists($path)`.

**Фаза 2 (заметки на будущее, не в v1):**

- Файлово-системные операции: `unlink`, `rename`, `copy`, `mkdir`, `rmdir`,
  `scandir`/`glob`, `chmod`/`chown`, `realpath`, `symlink`.
- Стриминг-курсор чтения `readStream(): Iterator` (батчи через `next`, как
  `RowsResult`) — поверх per-call `read`.
- Файловые блокировки (`flock`), advisory-lock на хэндле.
- Песочница/ограничение базовым каталогом, политика разрешённых путей.
- `sendfile`/zero-copy между двумя хэндлами; копирование «файл→HTTP-ответ».

---

## Соответствие `Method` PHP ↔ Go

Новый домен — один `Method`, мульти-командный (конверт), как `Sql`/`Mongodb`.

- PHP: `SConcur\Features\MethodEnum::File = 8`
- Go: `ext/internal/types/method.go` → `MethodFile Method = 8`

Под-операции выбираются полем команды в конверте (как `SqlCommandEnum`):

`FileCommandEnum` / Go `types.FileCommand`:

| Команда   | Значение | Аналог PHP   |
|-----------|----------|--------------|
| Open      | 1        | `fopen`      |
| Read      | 2        | `fread`      |
| Write     | 3        | `fwrite`     |
| Seek      | 4        | `fseek`      |
| Truncate  | 5        | `ftruncate`  |
| Sync      | 6        | `fflush`/`fsync` |
| Stat      | 7        | `fstat`      |
| Close     | 8        | `fclose`     |

`tell()`/`rewind()`/`eof()` отдельными командами **не делаем**: позиция
отслеживается на PHP-стороне (см. ниже), `rewind` — это `seek(0)`, `eof` —
кэш-флаг из последнего `read`. Это и про-PHP-стайл, и экономит round-trip'ы.

---

## Архитектура: хэндл как удерживаемый ресурс

Файл — это **долгоживущий ресурс с произвольными операциями в любом порядке**
(в отличие от «одна задача → много батчей» курсора). Поэтому копируем не
курсор-стейт, а **сессию транзакции**:

```
open()      ↔ begin()      держащая задача (HasNext) пинит *os.File; holder-state;
                            context.AfterFunc закрывает fd на стопе флоу
read/write/                 отдельные exec-задачи, несут handleId; роутятся к
seek/...    ↔ query/exec    пиннутому *os.File через pendingFiles sync.Map
close()     ↔ commit/       финализация: закрыть fd, затем next() освобождает
              rollback      держащую задачу
заброшен    ↔ заброшенная   отмена контекста закрывает fd; holder.Close() —
(без close)   транзакция    safety-net (идемпотентно через sync.Once); __destruct
                            на синхронном пути дёргает releaseSyncTaskFlow
```

### Жизненный цикл хэндла

1. `FileSystem::open($path, $mode)` → `FeatureExecutor::exec(OpenPayload)`.
   `handleOpen` валидирует/мапит режим → флаги, `os.OpenFile(path, flags, perm)`,
   кладёт `fileSession` в `pendingFiles` под `handleId = message.TaskKey`,
   регистрирует `fileHolderState` (`states.Get().Register`), вешает
   `context.AfterFunc(ctx, ...)` на закрытие, отдаёт
   `NewSuccessResultWithNext` (HasNext держит задачу/контекст живыми). Возвращает
   `File` с `handleId = $taskResult->key`.
2. `File::read/write/seek/...` → `exec(*Payload{handleId})`. `handleRead` и пр.
   достают сессию из `pendingFiles`, делают операцию под мьютексом сессии,
   возвращают результат. Это новые короткоживущие задачи (свой флоу на
   синхронном пути), пиннутый fd живёт в держащей задаче.
3. `File::close()` → `exec(ClosePayload{handleId})` закрывает fd и убирает
   сессию (идемпотентно), затем `FeatureExecutor::next($handleId)` освобождает
   держащую задачу (точная калька `Transaction::finish`).
4. Хэндл заброшен (исключение/ранний выход без `close`): отмена контекста флоу →
   `holder.Close()` закрывает fd; `File::__destruct` зовёт
   `State::releaseSyncTaskFlow($handleId)`, чтобы на синхронном пути не висла
   задача (no-op в async и после явного `close`).

### Сериализация операций на одном хэндле

`*os.File` имеет внутреннюю позицию — операции по одному хэндлу обязаны быть
сериализованы. В рамках одной корутины они и так последовательны
(suspend/resume между вызовами), но для надёжности `fileSession` несёт
`sync.Mutex`, сериализующий любую под-команду против `Close` (как `rowsState`
сериализует `Next` против `Close`).

---

## Протокол PHP ↔ Go (payloads)

Конверт (как `Sql`):

```
Envelope: { cm: int, to: int, dt: <тело команды> }
```

- `cm` — `FileCommand`.
- `to` — предельное время выполнения **одной** под-команды, мс (требование 2).
- `dt` — тело конкретной команды.

Тела команд (зеркалятся PHP `*Payload` ↔ Go-структуры 1:1, теги `msgpack`):

| Команда  | `dt` поля                                         | Результат (msgpack)                |
|----------|---------------------------------------------------|------------------------------------|
| Open     | `p` path, `md` mode, `pm` perm(int, 0644)         | ключ = `TaskKey` (handleId), HasNext |
| Read     | `h` handleId, `n` length                          | `{ b: bytes, e: eofBool }`         |
| Write    | `h` handleId, `b` bytes                           | `{ n: written }`                   |
| Seek     | `h` handleId, `o` offset, `w` whence              | `{ p: newPos }`                    |
| Truncate | `h` handleId, `s` size                            | `""`                               |
| Sync     | `h` handleId                                      | `""`                               |
| Stat     | `h` handleId                                      | `{ sz: size, mt: mtimeMs, md: mode }` |
| Close    | `h` handleId                                      | `""` (затем `next` отпускает held) |

**Режим открытия.** PHP **валидирует** строку режима против разрешённого набора
(иначе бросает `InvalidFileModeException`) и шлёт её как есть; **маппинг
режим→флаги делает Go** — там, где живут платформенные константы
`os.O_RDONLY/O_RDWR/O_CREATE/O_TRUNC/O_APPEND/O_EXCL`. Таблица маппинга:

```
r  → O_RDONLY
r+ → O_RDWR
w  → O_WRONLY|O_CREATE|O_TRUNC
w+ → O_RDWR  |O_CREATE|O_TRUNC
a  → O_WRONLY|O_CREATE|O_APPEND
a+ → O_RDWR  |O_CREATE|O_APPEND
x  → O_WRONLY|O_CREATE|O_EXCL
x+ → O_RDWR  |O_CREATE|O_EXCL
c  → O_WRONLY|O_CREATE
c+ → O_RDWR  |O_CREATE
(суффикс b/t отбрасывается)
```

`whence`: `SEEK_SET=0`, `SEEK_CUR=1`, `SEEK_END=2` — совпадают с PHP-константами
и `io.Seek*` Go.

---

## Требование 1 — отмена контекста

- Держащая задача `open` живёт на `task.GetContext()` (как begin-задача
  транзакции). На стопе флоу/`destroy`/раннем `break` контекст отменяется,
  `context.AfterFunc` зовёт `states.Get().DeleteState(handleId)` →
  `holder.Close()` закрывает `*os.File`.
- **Закрытие fd — это и есть механизм отмены залипшего read/write:** syscall на
  обычном файле контекст не слушает, но закрытие дескриптора разблокирует
  висящий read/write на медленном носителе (NFS/pipe) с ошибкой. Это честный
  способ «отменить» под-команду на стопе флоу.

## Требование 2 — предельное время выполнения

- Каждая **под-команда** (read/write/sync) несёт `to` в конверте и оборачивается
  per-op дедлайном. Поскольку `os.File` не принимает контекст, потенциально
  блокирующую операцию исполняем в фоновой горутине и `select`-им против
  `ctx.Done()` (паттерн `closeDb`): по таймауту — ошибка-результат; реально
  залипший fd добивается закрытием хэндла (см. требование 1). Мгновенные
  операции (`seek`/`truncate`/`stat`) дедлайном не оборачиваем.
- Держащая `open`-задача дедлайном **не** ограничивается — она живёт весь срок
  хэндла (как begin-задача транзакции).

---

## PHP-слой (`src/Features/File/`)

- `MethodEnum::File = 8` — новый кейс в `src/Features/MethodEnum.php`.
- `FileCommandEnum` — под-операции (Open…Close), как `SqlCommandEnum`.
- `FileMode` — value-object/enum: валидирует строку режима, нормализует (срезает
  `b`/`t`), отдаёт каноническую строку; единственная точка списка допустимых
  режимов.
- `Payloads/Base/BaseFilePayload` — конверт `cm/to/dt` (как `BaseSqlPayload`).
- `Payloads/OpenPayload`, `ReadPayload`, `WritePayload`, `SeekPayload`,
  `TruncatePayload`, `SyncPayload`, `StatPayload`, `ClosePayload` (+ кросс-ссылки
  `Go: payloads.<Type>`).
- `File.php` — объект хэндла: `read`, `write`, `seek`, `tell`, `rewind`, `eof`,
  `truncate`, `flush`, `stat`, `close`, `getContents`; хранит `handleId`,
  `position` (обновляется по результату read/write/seek), `eofReached`, `closed`;
  `__destruct` → `releaseSyncTaskFlow`. Калька `Transaction.php`.
- `FileSystem.php` — фасад: `open($path, $mode, $perm = 0644): File` и одноразовые
  `read`/`write`/`append`/`exists`.
- `Results/FileStat.php` — DTO `stat()` (`readonly`: size, modifiedAtMs, mode).
- Исключения в `src/Exceptions/File/`: `FileException` (RuntimeException-база),
  `InvalidFileModeException` (LogicException-база, как usage-ошибка),
  при необходимости `FileNotReadableException` и пр. — по правилам «Exceptions».

### `tell`/`eof`/`rewind` без round-trip

- `tell()` — возвращает `position`, поддерживаемую на PHP-стороне (Go-результаты
  read/write/seek несут новую позицию). Для режима `a`/`a+` запись всегда уходит
  в конец (`O_APPEND`), и Go возвращает фактическую позицию после записи.
- `eof()` — возвращает `eofReached`, выставленный по флагу `e` последнего
  `read` (как PHP `feof`: истина после чтения за концом).
- `rewind()` — `seek(0, SEEK_SET)`.

---

## Go-слой (`ext/internal/features/file/`)

- `types/method.go` → `MethodFile Method = 8`; `types/file.go` → `FileCommand`
  + константы (зеркало `types/sql.go`).
- `payloads/payloads.go` — `Envelope` + `OpenParams`/`ReadParams`/…/`CloseParams`,
  1:1 с PHP, кросс-ссылки `// PHP: …`.
- `sessions.go` — `pendingFiles sync.Map`; `fileSession{ file *os.File; mutex;
  path; finalize sync.Once }` с `close()`; `fileHolderState{ session; message;
  startTime }` (`Next` = маркер релиза, `Close` = `session.close()`). Прямая
  калька `transactions.go`.
- `feature.go` — `FileFeature` (синглтон `Get()` через `sync.Once`),
  `Handle` диспетчеризует по `FileCommand`; `handleOpen/Read/Write/Seek/Truncate/
  Sync/Stat/Close` + `loadSession(handleId)` (как `loadTransaction`).
- `mode.go` — `modeToFlags(mode string) (int, error)` (таблица выше).
- Регистрация в `ext/internal/features/factory.go`:
  `case types.MethodFile: return file_feature.Get(), nil`.

---

## Стриминг — как это выглядит у пользователя

```php
$fileSystem = new \SConcur\Features\File\FileSystem();

// потоковая запись большого буфера чанками (каждый write — async-задача)
$file = $fileSystem->open('/tmp/big.bin', 'w+');
foreach ($chunks as $chunk) {
    $file->write($chunk);
}
$file->flush();

// потоковое чтение до конца (каждый read — async-задача)
$file->rewind();
while (!$file->eof()) {
    $chunk = $file->read(65536);
    // обработать $chunk
}
$file->close();

// одноразовые удобные операции
$content = $fileSystem->read('/etc/hostname');
$fileSystem->write('/tmp/out.txt', "hello\n");
```

В `WaitGroup` несколько таких сценариев на разных файлах исполняются конкурентно;
общее время ≈ самой медленной цепочке, а не сумме.

---

## Тесты (обязательно)

- **PHP, `tests/feature/Features/File/FileTest.php`** от `BaseAsyncTestCase`:
  два конкурентных таска (например, запись в два файла), проверка порядка,
  конкурентности и пути с исключением. Эталон — `SleeperTest`.
- **Краевые тесты от `BaseTestCase`**: каждый режим (`r`/`r+`/`w`/`w+`/`a`/`a+`/
  `x`/`x+`/`c`/`c+`), цикл read до `eof`, `seek`/`tell`/`rewind`, `truncate`,
  `flush`, `stat`, `getContents`; ошибки — `r` по несуществующему файлу, `x` по
  существующему, `InvalidFileModeException` на кривом режиме, запись в `r`,
  чтение из `w`. Проверить отсутствие висящих задач (tearDown `BaseTestCase`).
- **Go, `feature_test.go`**: `modeToFlags` (все режимы + ошибка), цикл
  open→write→seek→read→close, очистка `pendingFiles`/`states` после `close` и
  после отмены контекста.

---

## Документация и сопутствующее

- `docs/file.ru.md` — использование, режимы, стриминг, ограничения, внутреннее
  устройство (по образцу `docs/mysql.ru.md`).
- Ссылки: `.ai/README.md` (раздел Further Reading + PHP/Go layer bullets +
  `MethodEnum`/командные перечисления), `README.md` (раздел «Документация» +
  пункт роадмапа «работа с файлами» → ссылка на этот план).
- **Версия расширения**: первая протокол-меняющая правка на ветке
  `feature/file-io` — бамп патча `0.2.2 → 0.2.3` в `ext/main.go` (`version()`) и
  `src/Connection/Extension.php` (`REQUIRED_EXTENSION_VERSION`). Мажор/минор не
  трогаем без согласования.

## Проверка

`make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test`

---

## Решения по развилкам (согласовано)

1. **Объём v1** — **только хэндловая I/O** (open/read/write/seek/trunc/sync/
   stat/close) + одноразовые `read/write/append/exists`. ФС-операции (unlink,
   rename, mkdir, scandir…), `flock`, стриминг-курсор `readStream()` и песочница
   путей — **фаза 2**.
2. **Маппинг режим→флаги** — **на Go** (там платформенные константы
   `os.O_*`); PHP только валидирует строку режима.
3. **Безопасность путей** — без песочницы в v1; путь — ответственность
   вызывающего (отметить в доке).
4. **Права по умолчанию** при создании — `0644`, переопределяются параметром
   `open(perm:)`.
5. **Имя фасада** — **`FileSystem`** (`\SConcur\Features\File\FileSystem`).
6. **Версия расширения** — бамп `0.2.2 → 0.2.3` (фактическая текущая — `0.2.2`;
   запись «0.2.1» в `CLAUDE.md` устарела).
