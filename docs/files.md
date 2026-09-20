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
| `list` of 10 000 entries with metadata | `scandir()` + `filesize()` + `filemtime()` per entry | 2.2x faster concurrently; the per-entry syscalls never reach PHP |
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

A writer is the exception, and deliberately so: `openWriter()` takes the
deadline once and every `write()` and the `close()` are bounded by it. A session
is one operation spread over many calls, and giving each call its own budget
would mean a writer with no bound at all.

On a stream the deadline bounds one batch, not the whole read: a stream lasts as
long as the caller keeps pulling, and what must not hang is a single pull.

A deadline here means the caller stops waiting — not that the syscall is
interrupted. File work runs on the runtime's blocking pool, and a thread already
inside `read(2)` stays there until the kernel returns.

For an operation that proceeds in pieces — a streamed read, a copy, a tree walk,
the chunks of a writer — the deadline and a `WaitGroup::stop()` land between the
pieces rather than at the end. A write or a copy that ends that way removes what
it created (see below); a read or a walk has nothing to remove and simply stops.

That pool is the process's, not the feature's: name resolution and anything else
that hands over blocking work share it. It is left at tokio's own 512 threads for
that reason, and `SCONCUR_BLOCKING_THREADS` changes it — lowering it to bound a
file fan-out bounds DNS in the same breath.

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
| `maxReadBytes` | refuses a read whose range is bigger than this with `FileTooLargeException`; 64 MiB by default, `0` lifts it |

The limit bounds what the call asks for, not the file: reading a 1 KiB range out
of a gigabyte file is allowed. It exists because a one-shot read holds that range
twice — once in the extension and once in PHP. `readChunks()` has no such peak
and no such limit.

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

A write that fails, or that runs out of time, removes the file **only if it was
the thing that created it** — which means only in `Create` mode, the one mode
that fails when the path is already taken.

A failed `Replace` therefore leaves a partial file where a whole one used to be.
That is deliberate. `Replace` truncated the old contents on open, which is what
was asked for; removing the file on top of that would destroy an inode, its
permissions and its ownership that the call never created, and when the path is a
symlink it would unlink the link and leave its target empty. A partial file is
the lesser loss, and it is the caller's own file either way. Open with `Create`
when the file must be this call's or nothing.

### Atomic writes

```php
Files::writeAtomic(path: '/var/app/cache/routes.php', contents: $compiled);
```

Writes a temporary file beside the destination, flushes it to the disk and
renames it over the target. A concurrent reader sees either the old contents or
the new ones, never a half-write. The temporary file is a sibling because rename
is atomic only within one filesystem.

`permissions` defaults to `0` here, which means "keep what the destination
already has" — 0644 only for a file that was not there. The rename replaces the
destination's inode, so a default of 0644 would widen a 0600 secret every time
this method updated it. Ownership is not carried over either: the new inode
belongs to whoever the process runs as.

## Copying, moving, removing

```php
$copiedBytes = Files::copy(source: $source, destination: $destination);

Files::move(source: '/tmp/upload-9f2c', destination: '/var/app/storage/42.jpg');

Files::delete(path: $path, missingOk: true);
Files::truncate(path: $path, sizeBytes: 0);
```

`copy` streams the file inside the extension; only a path and a count cross the
boundary. It takes the same `mode` and `permissions`, plus a `bufferSizeBytes`
that tunes the copy granularity — 64 KiB by default, 8 MiB at most.

`move` renames within one filesystem and copies-then-renames across two, which
is what PHP's `rename()` does as well. It replaces an existing destination and
has no mode to refuse one: `rename(2)` replaces, the portable alternative does
not exist, and a check followed by a rename would be a race dressed up as a
guarantee.

Across filesystems the copy goes to a temporary beside the destination and is
renamed into place, so a failure at any point leaves the destination exactly as
it was — the move either happens or does not. The destination keeps its own
permissions; a new one takes the source's.

`truncate` past the end of the file grows it with zeroes, as `ftruncate()` does.

`delete` with `missingOk` treats an absent path as a success and answers whether
something was actually removed — the check-then-delete race written once here
instead of at every call site. `removeDirectory` answers the same way.

`truncate` takes its size as a required argument. A default would make
`truncate($path)` read as "tidy this file" and empty it, which is the one call
here that destroys data by being written carelessly.

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

`exists()` is not total, unlike `file_exists()`: a path whose parent directory
the process may not search raises `FilePermissionException` rather than answering
false. "There is no such file" and "I am not allowed to look" are different
facts, and conflating them is what makes `file_exists()` hard to debug.

With `followSymlinks: false` the call describes the link itself rather than what
it points at — the `lstat` half of the same command.

`permissions` carries the permission bits alone, without the type bits above
them, so it compares against `0644` with no masking. The times are milliseconds
since the epoch, negative for a stamp before it, and `0` where the filesystem
does not carry one.

`chmod` is the one command whose `permissions` are required, and it takes them
literally: `0` there is `chmod 000`. Every other command takes them optionally
and reads `0` as "the usual default" — 0644 for a file, 0755 for a directory,
0600 for a temporary one, and the destination's own bits for `writeAtomic`.

```php
Files::exists(path: $path);
Files::chmod(path: $path, permissions: 0600);
Files::touch(path: $path, modifiedAtMs: 0);      // 0 means now
Files::realPath(path: $path);
Files::temporaryFile(directory: '/var/app/tmp', prefix: 'upload-', suffix: '.bin');
```

`touch` creates the file if it is missing and never changes its contents.
`modifiedAtMs` of `0` means now; a negative value is a stamp before 1970, which
`stat` reports the same way, so a time read from one can be given back to the
other. The epoch second itself is the one value that cannot be set, because `0`
is spoken for.

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
`withMetadata` the size and the time are `null` — "not asked for" rather than
"empty" — and no `stat` is paid for them; the entry's type still comes from the
directory read itself, which is free on the filesystems that carry it.

An entry removed between the directory read and its `stat` is skipped rather
than reported: a directory being written to is the ordinary case, and failing a
whole listing over one vanished file would make this unusable anywhere real.

`pattern` filters inside the extension, so a directory of a hundred thousand
files does not cross the boundary to be filtered in PHP. It understands `*`, `?`
and `[...]`, and it is matched against the entry name alone — never against a
path, so there is nothing for a separator to mean. It is not `glob(3)`: no `**`,
no brace expansion, no escaping.

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

`readLines` takes `batchLines` (lines per crossing, 200 by default),
`bufferSizeBytes` (64 KiB) and `maxLineBytes` (1 MiB) — the last bounds a single
line, so a file with no newline in it cannot be buffered whole in the name of
streaming. A line over it raises `FileTooLargeException`, the same exception a
one-shot read over `maxReadBytes` raises.

`0` means the default for every size here, and every one of them is capped:
buffers and `maxLineBytes` at 8 MiB, batches at 100 000 lines. A size chosen by
mistake is clamped rather than handed to the allocator — `bufferSizeBytes:
PHP_INT_MAX` is a clamp, not a crash.

A batch also stops at 64 MiB of lines however many it has collected, because
100 000 lines of 8 MiB is not a batch anyone can hold. So a batch can be shorter
than `batchLines` asked for; it is never longer.

### Walking a tree

```php
foreach (Files::walk(path: $directory, pattern: '*.tmp') as $entry) {
    Files::delete(path: $entry->path);
}
```

The list of directories still to visit lives in the extension, so breaking out
after the first match costs the first batch and nothing more — not the whole
tree, and not one directory either: a batch reads as many directories as it
needs to fill itself. The pattern picks what is reported, not where the walk
goes; `batchEntries` (200 by default) is how many entries a crossing carries,
and `withMetadata` works as it does for `list` — without it an entry costs no
stat and its size and time are `null`.

A batch also stops after examining ten thousand entries, even if the pattern
matched none of them, and answers an empty one that says there is more. Without
that, a pattern matching nothing would walk a whole tree inside a single batch,
where neither a deadline nor a stop could reach it.

Directory symlinks are listed but never descended into. That is deliberate: a
tree with a link back into an ancestor has no end. A subdirectory that cannot be
read — no permission, or gone since it was listed — is stepped over rather than
ending the walk.

### Writing in chunks

```php
$writer = Files::openWriter(
    path: '/var/app/storage/export.csv',
    mode: FileWriteMode::Create,
);

foreach ($rows as $row) {
    $writer->write(chunk: implode(',', $row) . "\n");
}

$writtenBytes = $writer->close();
```

`openWriter` takes the same `mode` and `permissions` as `write()`.

`write()` does not answer until the extension has written the chunk, so a
coroutine producing faster than the disk accepts waits on its own next call
instead of piling megabytes into memory. It answers the running total, which
`writtenBytes()` repeats and `path()` accompanies.

Not closing is safe but lossy: when the coroutine ends, so does its flow — the
group of tasks the extension holds for it, see
[architecture](architecture.md) — and the extension closes the file. Whether the
file goes with it follows the rule every write here follows: only a writer that
created the file removes it, so only in `Create` mode. A `Replace` writer leaves
the partial file, an `Append` writer leaves everything. `close()` is what turns
the bytes into a finished file and answers with the total.

A `close()` that runs out of time or hits a transient error can be tried again:
the extension keeps the session and its open file for exactly that, and the file
is not removed — every chunk had been handed over and only the flush was in
doubt.

Anything else ends the handle. A chunk that did not complete — cut off by a
deadline or a stop, or failed part-way with an error — leaves the file holding
bytes no total accounts for, so the writer is unusable: its `write()` and its
`close()` are both refused, the handle is spent, its flow is given back, and a
further call raises `FileStreamClosedException`. `writtenBytes()` reports the
last total the extension acknowledged, which after such a chunk is less than the
file holds.

### Abandoning a stream

Breaking out of any of them early is safe. The coroutine's flow — the group of
tasks the extension holds for it, see [architecture](architecture.md) — ends,
and with it the extension releases what the stream held. That release is what
`make mem-leak-files scenario=abandoned` soaks, and what the feature tests
assert by counting the process's own open descriptors before and after.

## Errors

Every failure is named for its case. The kind is decided in the extension, which
writes it as a prefix; PHP reads that prefix and never matches the message text —
a file named "permission denied" must not pick the exception an application
catches.

| Exception | Case |
| --- | --- |
| `FileNotFoundException` | the path is not there |
| `FilePermissionException` | the process may not do this to it |
| `FileAlreadyExistsException` | the destination is taken and the mode forbids replacing it |
| `UnexpectedFileTypeException` | a directory where a file was wanted, or the other way round, or a directory that still holds entries |
| `FileTooLargeException` | the read's range is over `maxReadBytes`, or a line is over `maxLineBytes` |
| `FileTimeoutException` | the deadline ran out |
| `FileStoppedException` | the flow was stopped under the operation — `WaitGroup::stop()`, an early break, shutdown |
| `FileStreamClosedException` | the stream this handle names is gone: closed, cut off mid-chunk, or released with its coroutine |
| `FileOperationException` | any other input-output failure |
| `InvalidFileArgumentException` | the call itself is wrong |
| `FilesException` | the base class, raised directly when the failure carries no kind this package knows — a core newer than the package, or a failure raised before the core saw the call |

Every one of them descends from `FilesException`, which descends from
`RuntimeException` — a missing file and a full disk are runtime conditions
whatever the caller does — with one exception on purpose.
`InvalidFileArgumentException` is a `LogicException` and sits outside that tree:
a negative length or an unknown algorithm is a bug in the code, and a handler
written for the filesystem's own failures must not swallow it.

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
