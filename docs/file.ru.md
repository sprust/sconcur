# Файлы (File)

Асинхронная работа с файлами поверх Go `os.File`. Открытие, чтение, запись,
позиционирование и закрытие уходят в Go-расширение и исполняются в горутинах,
пока корутина приостановлена, — десятки файловых операций летят «веером», а
блокирующий syscall одного файла не тормозит остальные корутины. Вне `WaitGroup`
тот же API работает синхронно.

API «по PHP-стайлу»: `FileSystem::open($path, $mode)` с полным набором режимов
`fopen`, затем методы хэндла `read`/`write`/`seek`/`tell`/`rewind`/`eof`/
`truncate`/`flush`/`stat`/`getContents`/`close` — как `fread`/`fwrite`/`fseek`/
`ftell`/`rewind`/`feof`/`ftruncate`/`fflush`/`fstat`/`fclose`.

---

## Быстрый старт

```php
use SConcur\Features\File\FileSystem;

$fileSystem = new FileSystem();

// Запись/чтение хэндлом
$file = $fileSystem->open(path: '/tmp/data.txt', mode: 'w+');
$file->write('hello world');
$file->rewind();
echo $file->getContents(); // hello world
$file->close();

// Одноразовые удобные операции
$fileSystem->write(path: '/tmp/out.txt', data: "line\n");
$fileSystem->append(path: '/tmp/out.txt', data: "more\n");
$content = $fileSystem->read('/tmp/out.txt');
```

Конкурентно (внутри `WaitGroup`) — несколько файлов обрабатываются «веером»,
общее время ≈ самой медленной цепочке, а не сумме:

```php
use SConcur\WaitGroup;

$waitGroup = WaitGroup::create();

foreach ($paths as $path) {
    $waitGroup->add(function () use ($fileSystem, $path) {
        $file = $fileSystem->open(path: $path, mode: 'r');

        try {
            return strlen($file->getContents());
        } finally {
            $file->close();
        }
    });
}

foreach ($waitGroup->iterate() as $key => $size) {
    echo "$key: $size bytes\n";
}
```

---

## Режимы открытия

Поддержан полный набор режимов `fopen`. Опциональный суффикс `b`/`t`
принимается и игнорируется (Unix бинарно-безопасен по умолчанию). Невалидный
режим бросает `InvalidFileModeException`.

| Режим | Чтение | Запись | Создаёт | Усекает | Особенности                       |
|-------|:------:|:------:|:-------:|:-------:|-----------------------------------|
| `r`   | ✓      | —      | —       | —       | файл должен существовать           |
| `r+`  | ✓      | ✓      | —       | —       | файл должен существовать           |
| `w`   | —      | ✓      | ✓       | ✓       |                                    |
| `w+`  | ✓      | ✓      | ✓       | ✓       |                                    |
| `a`   | —      | ✓      | ✓       | —       | запись всегда в конец              |
| `a+`  | ✓      | ✓      | ✓       | —       | запись всегда в конец              |
| `x`   | —      | ✓      | ✓       | —       | ошибка, если файл существует       |
| `x+`  | ✓      | ✓      | ✓       | —       | ошибка, если файл существует       |
| `c`   | —      | ✓      | ✓       | —       | без усечения, позиция в начале     |
| `c+`  | ✓      | ✓      | ✓       | —       | без усечения, позиция в начале     |

Маппинг режима в флаги `os.O_*` делается на Go-стороне
(`ext/internal/features/file/mode.go`) — единственное место платформенных
констант; PHP только валидирует строку режима.

Права при создании файла задаёт параметр `open(perm:)`, по умолчанию `0644`:

```php
$file = $fileSystem->open(path: '/tmp/secret', mode: 'w', perm: 0600);
```

Чтение из write-only хэндла (`w`/`a`/`x`/`c`) и запись в read-only (`r`)
бросают `FileException` ещё до обращения к расширению.

---

## API

### `FileSystem`

```php
new FileSystem(?int $timeoutMs = null) // дефолт 30000 мс
```

`$timeoutMs` — предельное время одной блокирующей под-команды (read/write/sync).

| Метод | Описание |
|-------|----------|
| `open(string $path, string $mode, int $perm = 0644): File` | Открыть файл, вернуть хэндл. |
| `read(string $path): string` | Открыть `r`, прочитать всё, закрыть. |
| `write(string $path, string $data, string $mode = 'w'): int` | Открыть `$mode`, записать, закрыть; вернуть число байт. |
| `append(string $path, string $data): int` | То же с режимом `a`. |
| `exists(string $path): bool` | Локальный `file_exists` (не round-trip в расширение). |

### `File`

| Метод | Аналог PHP | Описание |
|-------|-----------|----------|
| `read(int $length): string` | `fread` | До `$length` байт с текущей позиции; `''` в конце. |
| `write(string $data): int` | `fwrite` | Записать байты; вернуть записанное число. |
| `seek(int $offset, int $whence = SEEK_SET): int` | `fseek` | Переместить позицию; вернуть новую. |
| `tell(): int` | `ftell` | Текущая позиция (локально, без round-trip). |
| `rewind(): int` | `rewind` | `seek(0)`. |
| `eof(): bool` | `feof` | Истина после чтения за концом (локально). |
| `truncate(int $size): void` | `ftruncate` | Изменить размер файла. |
| `flush(): void` | `fflush` | `fsync` на диск. |
| `stat(): FileStat` | `fstat` | Размер, mtime (мс), биты режима. |
| `getContents(): string` | `stream_get_contents` | Прочитать до конца, стримингом по 64 КиБ. |
| `close(): void` | `fclose` | Закрыть; идемпотентно. |

`FileStat`: `public int $size`, `public int $modifiedAtMs`, `public int $mode`.

---

## Стриминг

Каждый `read()`/`write()` — отдельная асинхронная задача: большой файл
читается/пишется в цикле чанками, и на каждом чанке корутина приостанавливается,
отдавая управление другим корутинам. Файл никогда не буферизуется целиком в
расширении.

```php
// потоковая запись
$file = $fileSystem->open(path: '/tmp/big.bin', mode: 'w+');
foreach ($chunks as $chunk) {
    $file->write($chunk);
}
$file->flush();

// потоковое чтение
$file->rewind();
while (!$file->eof()) {
    $chunk = $file->read(65536);
    // обработать $chunk
}
$file->close();
```

`getContents()` — это тот же цикл чтения, упакованный в один вызов.

---

## Ошибки

- Открытие несуществующего файла в `r`/`r+`, `x`/`x+` по существующему,
  нарушение прав и т.п. — Go возвращает ошибку, она поднимается как исключение
  с сообщением, начинающимся с `file:`.
- Невалидный режим — `InvalidFileModeException` (PHP-сторона,
  `SConcur\Exceptions\File`).
- Чтение/запись в режиме, который это запрещает — `FileException`.

---

## Ограничения

- **Путь — ответственность вызывающего.** Песочницы/ограничения базовым
  каталогом нет; передавайте проверенные пути.
- **Только хэндловая I/O в этой версии.** Файлово-системные операции (`unlink`,
  `rename`, `mkdir`, `scandir`, …), блокировки (`flock`) и стриминг-курсор
  чтения — в планах (см. [.ai/plans/file.md](../.ai/plans/file.md)).
- `exists()` делает локальный `file_exists` (синхронный stat), а не запрос в
  расширение — отдельной path-stat команды в этой версии нет.
- Завершать процесс (`exit`) с незакрытыми хэндлами при активных задачах нельзя
  (общее ограничение библиотеки) — сначала закройте/остановите.

---

## Внутреннее устройство

Открытый файл — это **долгоживущий удерживаемый ресурс** с произвольными
операциями в любом порядке, поэтому он устроен как **SQL-транзакция**, а не как
курсор:

- `open` открывает `*os.File` и регистрирует держащее состояние
  (`fileHolderState`) с флагом `HasNext` — задача `open` живёт весь срок хэндла и
  закрепляет дескриптор; id хэндла = ключ этой задачи. Реестр —
  `pendingFiles sync.Map` (зеркало `pendingTransactions`).
- `read`/`write`/`seek`/`truncate`/`sync`/`stat` — отдельные короткоживущие
  задачи, несущие id хэндла; обработчик достаёт сессию из `pendingFiles` и
  выполняет операцию под мьютексом сессии (сериализация против закрытия).
- `close` закрывает дескриптор, удаляет сессию, затем PHP освобождает держащую
  задачу через `next()` (калька `Sql\Transaction::finish`).
- Заброшенный хэндл (без `close`, исключение, стоп флоу): отмена контекста
  закрывает дескриптор автоматически (`fileHolderState::Close`, идемпотентно);
  закрытие fd разблокирует и залипший read/write на медленном носителе.

Каждая блокирующая под-команда (read/write/sync) ограничена переданным таймаутом:
`os.File` не принимает контекст, поэтому операция исполняется в фоновой горутине и
гонится против дедлайна; реально застрявший дескриптор добивается закрытием
хэндла на стопе флоу.

Позиция и флаг `eof` отслеживаются на PHP-стороне (Go отдаёт новую позицию в
результате каждого read/write/seek), поэтому `tell()`/`eof()`/`rewind()` не
делают лишних round-trip'ов.

Файлы реализации:

- PHP: `src/Features/File/` (`FileSystem`, `File`, `FileMode`, `FileCommandEnum`,
  `Payloads/*`, `Results/FileStat`), исключения — `src/Exceptions/File/`.
- Go: `ext/internal/features/file/` (`feature.go`, `sessions.go`, `mode.go`,
  `payloads/payloads.go`), тип команды — `ext/internal/types/file.go`.
