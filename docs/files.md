English | [Русский](files.ru.md)

# Files

Asynchronous file operations. The whole operation — opening, seeking, reading,
writing, waiting for the disk — happens inside the extension, so the PHP thread
is free while it runs. Inside a `WaitGroup` many operations run at the same
time; outside one the same API works synchronously.

That is what the feature is for. A single small file with a warm page cache is
faster read natively — the boundary crossing is added to the same work — and
the [benchmarks](benchmarks.md) say so plainly. What the feature buys is
elsewhere: the thread that is not held while a slow disk answers, and the
operations whose bytes never cross the boundary at all.

## Quick start

```php
use SConcur\Features\Files\Files;
use SConcur\Features\Files\FileWriteMode;

$contents = Files::read(path: '/var/app/storage/report.csv');

Files::write(
    path: '/var/log/app/audit.log',
    contents: $line,
    mode: FileWriteMode::Append,
);

// the bytes never cross into PHP
Files::copy(source: '/tmp/upload', destination: '/var/app/storage/file.bin');

// one crossing instead of file_exists + is_file + filesize + filemtime
$stat = Files::stat(path: '/var/app/storage/file.bin');
```

Inside `WaitGroup::add(...)` the same calls run concurrently.

## Where the gain is, and where it is not

| Operation | Against | Verdict |
| --- | --- | --- |
| `copy` of 64 MiB | PHP's `copy()` | 2x faster synchronously, 9x concurrently — the bytes stay inside the extension |
| `list` of 10 000 entries with metadata | `scandir()` + `filesize()` + `filemtime()` per entry | 1.8x faster concurrently; the per-entry syscalls never reach PHP |
| `hashFile` of 64 MiB | `hash_file()` | 1.3x faster concurrently; the read loop leaves the PHP thread free |
| `readChunks` of 64 MiB | `Files::read()` of the same file | same bytes at a 4 MB peak instead of 132 MB |
| `read`/`write` of 64 MiB | `file_get_contents()` / `file_put_contents()` | slower — the payload crosses the boundary; what is bought is a free thread, not throughput |
| anything on a 1 KiB file with a warm cache | the native function | slower, and there is no reason to use the feature for it |

The numbers are in [benchmarks](benchmarks.md). Two things they do not measure,
and both are the point: a coroutine waiting on a disk does not hold the worker,
and a request that reads a large file no longer delays every other request the
process is serving.

## Deadlines and cancellation

Every call takes `timeoutMs`, defaulting to `Files::DEFAULT_TIMEOUT_MS`
(30 000). `0` means no deadline.

A deadline here means the caller stops waiting — not that the syscall is
interrupted. File work runs on the runtime's blocking pool, and a thread already
inside `read(2)` stays there until the kernel returns. For an operation that
proceeds in pieces — a streamed read, a copy, a tree walk, the chunks of a
writer — cancellation is real: it is checked between pieces, and an unfinished
file is removed.

The size of that pool is what bounds how many file operations a process runs at
once: 64 by default, set by `SCONCUR_BLOCKING_THREADS`.

## Reading and writing

```php
Files::read(
    path: '/var/app/storage/report.csv',
    offsetBytes: 0,
    lengthBytes: 0,
    maxReadBytes: Files::DEFAULT_MAX_READ_BYTES,
);
```

| Parameter | Meaning |
| --- | --- |
| `offsetBytes` | where the read starts; with `lengthBytes` it replaces `fopen` + `fseek` + `fread` |
| `lengthBytes` | how much to read; `0` reads to the end of the file |
| `maxReadBytes` | refuses a file bigger than this with `FileTooLargeException`; 64 MiB by default, `0` lifts it |

The limit exists because a one-shot read holds the file twice — once in the
extension and once in PHP. `readChunks()` has no such peak and no such limit.

```php
Files::write(
    path: '/var/log/app/audit.log',
    contents: $line,
    mode: FileWriteMode::Append,
    permissions: 0644,
);
```

`FileWriteMode` is `Replace` (create or truncate, like `w`), `Create` (create,
failing if the path is taken, like `x`) and `Append` (create or append, like
`a`). `permissions` apply only when the file is created, as they do for
`open(2)`.

A write that fails removes what it created and leaves alone what it did not: a
`Create` refused because the path was taken does not delete the file that caused
the refusal, and an append never removes anything.

### Atomic writes

```php
Files::writeAtomic(path: '/var/app/cache/routes.php', contents: $compiled);
```

Writes a temporary file beside the destination, flushes it to the disk and
renames it over the target. A concurrent reader sees either the old contents or
the new ones, never a half-write. The temporary file is a sibling because rename
is atomic only within one filesystem.

## Copying, moving, removing

```php
$copiedBytes = Files::copy(source: $source, destination: $destination);

Files::move(source: '/tmp/upload-9f2c', destination: '/var/app/storage/42.jpg');

Files::delete(path: $path, missingOk: true);
Files::truncate(path: $path, sizeBytes: 0);
```

`copy` streams the file inside the extension; only a path and a count cross the
boundary. It takes the same `mode`, `permissions` and a `bufferSizeBytes` that
tunes the copy granularity.

`move` renames within one filesystem and copies-then-removes across two, which
is what PHP's `rename()` does as well.

`delete` with `missingOk` treats an absent path as a success and answers whether
something was actually removed — the check-then-delete race written once here
instead of at every call site.

## Checksums

```php
use SConcur\Features\Files\FileHashAlgorithm;

$digest = Files::hashFile(path: $path, algorithm: FileHashAlgorithm::Sha256);
```

Lowercase hex, under the names `hash_file()` knows, so a checksum written by one
side verifies on the other. The algorithms are `Sha256` (the default), `Sha512`,
`Sha1` and `Md5`; the last two are there for ETags and existing manifests, not
as a security choice.

Like `hash_file()`, nothing is held whole. Unlike it, the read loop and every
disk wait inside it run on the runtime.

## Metadata

```php
$stat = Files::stat(path: $path, followSymlinks: true);
```

Answers a `Dto\FileStat`: `exists`, `isFile`, `isDirectory`, `isSymlink`,
`sizeBytes`, `modifiedAtMs`, `accessedAtMs`, `createdAtMs`, `permissions`,
`userId`, `groupId`.

A path that is not there is not a failure — `exists` is false and the rest hold
their zero values. That is what lets `Files::exists()` be a reading of this
rather than a command of its own.

`permissions` carries the permission bits alone, without the type bits above
them, so it compares against `0644` with no masking. The times are milliseconds
since the epoch, negative for a stamp before it, and `0` where the filesystem
does not carry one.

```php
Files::exists(path: $path);
Files::chmod(path: $path, permissions: 0600);
Files::touch(path: $path, modifiedAtMs: 0);      // 0 means now
Files::realPath(path: $path);
Files::temporaryFile(directory: '/var/app/tmp', prefix: 'upload-', suffix: '.bin');
```

`touch` creates the file if it is missing and never changes its contents.
`realPath` refuses a missing path with `FileNotFoundException` rather than
answering the `false` that is so easy to forget to check.

`temporaryFile` draws a name and creates it in one step, so there is no window
in which another process takes it — which `tempnam()` followed by `fopen()` does
have. An empty `directory` means the system temporary directory.

## Directories

```php
Files::makeDirectory(path: '/var/app/storage/exports/2026-09', permissions: 0755, recursive: true);
Files::removeDirectory(path: '/var/app/storage/tmp', recursive: true);

$entries = Files::list(path: $directory, pattern: '*.csv', withMetadata: true);
```

`makeDirectory` with `recursive` creates the parents and treats an existing
directory as a success — the `mkdir -p` behaviour. Without it, an existing
directory is a `FileAlreadyExistsException`. `permissions` are subject to the
process umask, as they are for `mkdir(2)`.

`removeDirectory` needs `recursive` for a directory that still holds entries.

`list` answers a list of `Dto\DirectoryEntry` (`name`, `path`, `isDirectory`,
`isSymlink`, `sizeBytes`, `modifiedAtMs`), sorted by name. Without
`withMetadata` an entry costs no stat and its `sizeBytes` and `modifiedAtMs` are
`null` — "not asked for" rather than "empty".

`pattern` filters inside the extension, so a directory of a hundred thousand
files does not cross the boundary to be filtered in PHP. It understands `*`, `?`
and `[...]` against the entry name; it is not `glob(3)` — there is no `**`, no
brace expansion and no escaping, and a pattern never spans a directory
separator.

`list` reads one directory. A tree is `walk()`.

## Streams

### Reading in batches

```php
foreach (Files::readChunks(path: $path, bufferSizeBytes: 65536) as $chunk) {
    $digest->update($chunk);
}

foreach (Files::readLines(path: '/var/log/app/access.log') as $line) {
    // ...
}
```

`readChunks` holds one buffer whatever the file's size, where `read()` holds the
file twice. `readLines` cuts the lines in the extension, which is what makes a
batch of them cost one crossing where `fgets()` in a loop costs one per line;
separators are removed, `\r\n` included, and a last line without a terminator is
still a line.

`readLines` takes `batchSize` (lines per crossing), `bufferSizeBytes` and
`maxLineBytes` — the last bounds a single line, so a file with no newline in it
cannot be buffered whole in the name of streaming.

### Walking a tree

```php
foreach (Files::walk(path: $directory, pattern: '*.tmp') as $entry) {
    Files::delete(path: $entry->path);
}
```

The frontier lives in the extension, so breaking out after the first match costs
the first directory and nothing more. The pattern picks what is reported, not
where the walk goes.

Directory symlinks are listed but never descended into. That is deliberate: a
tree with a link back into an ancestor has no end.

### Writing in chunks

```php
$writer = Files::openWriter(path: '/var/app/storage/export.csv');

foreach ($rows as $row) {
    $writer->write(chunk: implode(',', $row) . "\n");
}

$writtenBytes = $writer->close();
```

`write()` does not answer until the extension has written the chunk, so a
coroutine producing faster than the disk accepts waits on its own next call
instead of piling megabytes into memory.

Not closing is safe but lossy: when the coroutine ends, the flow ends with it
and the extension closes the file — removing it if the write never finished,
unless the writer was appending. `close()` is what turns the bytes into a
finished file and answers with the total.

### Abandoning a stream

Breaking out of any of them early is safe. The flow ends, and the extension
releases what the stream held. A soak of a hundred thousand cycles of abandoned
readers, walks and writers holds flat.

## Errors

Every failure is named for its case. The kind is decided in the extension and
read from the message, never matched out of it — a file named "permission
denied" must not pick the exception an application catches.

| Exception | Case |
| --- | --- |
| `FileNotFoundException` | the path is not there |
| `FilePermissionException` | the process may not do this to it |
| `FileAlreadyExistsException` | the destination is taken and the mode forbids replacing it |
| `UnexpectedFileTypeException` | a directory where a file was wanted, or the other way round, or a directory that still holds entries |
| `FileTooLargeException` | the file is over the read limit the call carried |
| `FileTimeoutException` | the deadline ran out |
| `FileWriterClosedException` | the writer or stream this handle names is gone |
| `FileOperationException` | any other input-output failure |
| `InvalidFileArgumentException` | the call itself is wrong |

All of them but the last descend from `FilesException`, which descends from
`RuntimeException`: a missing file and a full disk are runtime conditions
whatever the caller does. `InvalidFileArgumentException` is a `LogicException`
and deliberately outside that tree — a negative length or an unknown algorithm
is a bug in the code, and a handler written for the filesystem's own failures
must not swallow it.

## What the feature does not do

- **No `fopen`-style handle** with `seek`, `tell`, `read` and `write`. Every one
  of those calls would be a boundary crossing spent on the cheapest thing there
  is. Random access is `read(offsetBytes, lengthBytes)`; sequential access is
  the streams.
- **No locks.** `flock` across a suspension needs a descriptor living in the
  extension and an ownership model for it. `writeAtomic()` covers what a lock is
  usually taken for.
- **No filesystem watching**, no `symlink`/`readlink`, no `glob(3)`, no
  `fgetcsv` — parsing stays in PHP on top of `readLines()`.
