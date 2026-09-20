<?php

declare(strict_types=1);

namespace SConcur\Features\Files;

use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\Files\Dto\DirectoryEntry;
use SConcur\Features\Files\Dto\FileStat;
use SConcur\Features\Files\Payloads\FilesPayload;
use SConcur\Features\Files\Results\ChunksResult;
use SConcur\Features\Files\Results\LinesResult;
use SConcur\Features\Files\Results\WalkResult;
use SConcur\Features\Files\Support\FilesFailure;
use SConcur\Exceptions\UnexpectedResponseFormatException;
use SConcur\Transport\MessagePackTransport;

/**
 * Async counterparts of PHP's file functions. The whole operation — opening, seeking,
 * reading, writing, waiting for the disk — happens inside the extension, on the runtime's
 * blocking pool, so the PHP thread is free while it runs.
 *
 * Inside a WaitGroup a call suspends the coroutine and many operations run at the same
 * time; outside one it is an ordinary blocking call. Concurrency is the caller's choice,
 * not the feature's.
 *
 * The gain is not in beating a native call: a boundary crossing is added to the same
 * work, so a single small file with a warm page cache is faster read natively. It is in
 * what the thread does meanwhile, and in the operations whose bytes never cross at all
 * (copy, hashFile, list). See docs/files.md.
 */
class Files
{
    /** The deadline a call carries when none is given. 0 means no deadline. */
    public const int DEFAULT_TIMEOUT_MS = 30_000;

    /**
     * How much read() will pull across the boundary before refusing (64 MiB).
     *
     * It bounds what the call asks for, not the file: reading a 1 KiB range out of a
     * gigabyte file is allowed, and refusing happens only when the range itself is over
     * the limit. The limit exists because a one-shot read holds the range twice — once in
     * the extension, once in PHP; readChunks() has no such peak. 0 lifts it.
     */
    public const int DEFAULT_MAX_READ_BYTES = 67_108_864;

    /**
     * Reads a file whole, or the byte range asked for.
     *
     * $lengthBytes of 0 reads to the end of the file. $maxReadBytes refuses a read whose
     * range is bigger than the limit with a FileTooLargeException instead of spending the
     * memory — so it bounds the range, not the file.
     */
    public static function read(
        string $path,
        int $offsetBytes = 0,
        int $lengthBytes = 0,
        int $maxReadBytes = self::DEFAULT_MAX_READ_BYTES,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): string {
        // The contents come back raw rather than wrapped in MessagePack: wrapping would
        // copy the whole file once more on each side just to be unwrapped again.
        return static::execute(
            command: FilesCommandEnum::Read,
            timeoutMs: $timeoutMs,
            data: [
                'p'  => $path,
                'of' => $offsetBytes,
                'ln' => $lengthBytes,
                'mx' => $maxReadBytes,
            ],
        )->payload;
    }

    /**
     * Writes a file in one shot and answers with the number of bytes written.
     *
     * $permissions apply only when the file is created, as they do for open(2).
     */
    public static function write(
        string $path,
        string $contents,
        FileWriteMode $mode = FileWriteMode::Replace,
        int $permissions = 0644,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): int {
        return static::count(
            result: static::execute(
                command: FilesCommandEnum::Write,
                timeoutMs: $timeoutMs,
                data: [
                    'p'  => $path,
                    'c'  => $contents,
                    'm'  => $mode->value,
                    'pm' => $permissions,
                ],
            ),
        );
    }

    /**
     * Writes through a temporary file beside the destination and a rename, so a
     * concurrent reader sees either the old contents or the new ones — never a half-write.
     * Answers with the number of bytes written.
     *
     * $permissions of 0 — the default — keeps the bits the destination already has, and
     * uses 0644 for a file that was not there. Naming any other value sets it. The
     * default is 0 rather than 0644 on purpose: a rename replaces the destination's
     * inode, so an atomic write that always named 0644 would widen a 0600 secret every
     * time it updated it, which is the opposite of what this method is for.
     *
     * Ownership is not carried over — the new inode belongs to whoever this process runs
     * as — so an atomic write over a file owned by somebody else changes its owner.
     */
    public static function writeAtomic(
        string $path,
        string $contents,
        int $permissions = 0,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): int {
        return static::count(
            result: static::execute(
                command: FilesCommandEnum::WriteAtomic,
                timeoutMs: $timeoutMs,
                data: [
                    'p'  => $path,
                    'c'  => $contents,
                    'pm' => $permissions,
                ],
            ),
        );
    }

    /**
     * Cuts a file to $sizeBytes. A size past the end of the file grows it with zeroes,
     * as ftruncate() does.
     *
     * $sizeBytes is required and deliberately has no default: `truncate($path)` would
     * read as "tidy this file" and empty it, which is the one call in this class that
     * destroys data by being written carelessly. ftruncate() requires it too.
     */
    public static function truncate(
        string $path,
        int $sizeBytes,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): void {
        static::execute(
            command: FilesCommandEnum::Truncate,
            timeoutMs: $timeoutMs,
            data: [
                'p'  => $path,
                'sz' => $sizeBytes,
            ],
        );
    }

    /**
     * Copies a file inside the extension and answers with the number of bytes copied. The
     * bytes never cross into PHP, so memory stays flat whatever the size.
     *
     * $mode and $permissions open the destination exactly as write() opens its file, so a
     * copy can refuse an existing destination (FileWriteMode::Create) or add to one
     * (Append). $bufferSizeBytes tunes the copy granularity — 0 means 64 KiB, and
     * anything above 8 MiB is clamped to it.
     */
    public static function copy(
        string $source,
        string $destination,
        FileWriteMode $mode = FileWriteMode::Replace,
        int $permissions = 0644,
        int $bufferSizeBytes = 0,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): int {
        return static::count(
            result: static::execute(
                command: FilesCommandEnum::Copy,
                timeoutMs: $timeoutMs,
                data: [
                    's'  => $source,
                    'd'  => $destination,
                    'm'  => $mode->value,
                    'pm' => $permissions,
                    'bs' => $bufferSizeBytes,
                ],
            ),
        );
    }

    /**
     * Moves a file, replacing the destination if one is there.
     *
     * A rename within one filesystem, so the destination ends up with the source's
     * permissions. Across filesystems the extension copies through a temporary beside
     * the destination and renames that into place — PHP's rename() falls back too, but
     * copies straight onto the destination — so a failure before that rename leaves the
     * destination untouched, and it keeps its own permissions. Setuid and setgid are
     * dropped on that path.
     *
     * Unlike write() and copy() there is no mode to refuse an existing destination:
     * rename(2) replaces, and the only way to make it not replace — renameat2's
     * RENAME_NOREPLACE — is not portable, while a check followed by a rename would be a
     * race dressed up as a guarantee. Check with exists() if it matters, knowing what
     * that check is worth.
     */
    public static function move(
        string $source,
        string $destination,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): void {
        static::execute(
            command: FilesCommandEnum::Move,
            timeoutMs: $timeoutMs,
            data: [
                's' => $source,
                'd' => $destination,
            ],
        );
    }

    /**
     * Removes a file. With $missingOk a path that is not there is a success rather than a
     * FileNotFoundException; the answer says whether something was actually removed.
     */
    public static function delete(
        string $path,
        bool $missingOk = false,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): bool {
        return static::count(
            result: static::execute(
                command: FilesCommandEnum::Delete,
                timeoutMs: $timeoutMs,
                data: [
                    'p'  => $path,
                    'mo' => $missingOk,
                ],
            ),
        ) > 0;
    }

    /**
     * The checksum of a file, as lowercase hex — the same spelling hash_file() answers in.
     *
     * The bytes are read inside the extension and never cross into PHP, so the file's size
     * costs nothing here. Unlike hash_file(), the read loop and every disk wait in it
     * happen on the runtime: checksumming a multi-gigabyte file stops being a stall of the
     * whole worker.
     */
    public static function hashFile(
        string $path,
        FileHashAlgorithm $algorithm = FileHashAlgorithm::Sha256,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): string {
        return static::text(
            result: static::execute(
                command: FilesCommandEnum::HashFile,
                timeoutMs: $timeoutMs,
                data: [
                    'p' => $path,
                    'a' => $algorithm->value,
                ],
            ),
            key: 'h',
        );
    }

    /**
     * Everything one stat(2) knows about a path, in a single crossing.
     *
     * A path that is not there is not a failure: the answer carries exists = false. With
     * $followSymlinks off a symlink is described as itself rather than as what it points
     * at, which is the lstat() half of the same call.
     */
    public static function stat(
        string $path,
        bool $followSymlinks = true,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): FileStat {
        $result = static::execute(
            command: FilesCommandEnum::Stat,
            timeoutMs: $timeoutMs,
            data: [
                'p'  => $path,
                'fs' => $followSymlinks,
            ],
        );

        return FileStat::fromArray(MessagePackTransport::unpack($result->payload));
    }

    /**
     * Whether the path is there. A reading of stat(), not a command of its own, so it
     * costs exactly one crossing like every other question about a path.
     *
     * Not total, unlike file_exists(): a path whose parent directory the process may not
     * search raises FilePermissionException rather than answering false. That is the
     * honest answer — "there is no such file" and "I am not allowed to look" are
     * different facts, and file_exists() conflating them is what makes it hard to debug.
     */
    public static function exists(
        string $path,
        bool $followSymlinks = true,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): bool {
        return static::stat(
            path: $path,
            followSymlinks: $followSymlinks,
            timeoutMs: $timeoutMs,
        )->exists;
    }

    /**
     * Changes a path's permission bits.
     */
    public static function chmod(
        string $path,
        int $permissions,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): void {
        static::execute(
            command: FilesCommandEnum::Chmod,
            timeoutMs: $timeoutMs,
            data: [
                'p'  => $path,
                'pm' => $permissions,
            ],
        );
    }

    /**
     * Creates the file if it is not there, and sets its modification time — touch(1) in
     * one call. $modifiedAtMs of 0 means now; the contents are never touched.
     */
    public static function touch(
        string $path,
        int $modifiedAtMs = 0,
        int $permissions = 0644,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): void {
        static::execute(
            command: FilesCommandEnum::Touch,
            timeoutMs: $timeoutMs,
            data: [
                'p'  => $path,
                'mt' => $modifiedAtMs,
                'pm' => $permissions,
            ],
        );
    }

    /**
     * Canonicalizes a path: symlinks resolved, `.` and `..` removed. The path has to
     * exist, as it does for PHP's realpath(); a missing one is a FileNotFoundException
     * rather than the `false` that is so easy to forget to check.
     */
    public static function realPath(
        string $path,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): string {
        return static::text(
            result: static::execute(
                command: FilesCommandEnum::RealPath,
                timeoutMs: $timeoutMs,
                data: [
                    'p' => $path,
                ],
            ),
        );
    }

    /**
     * Creates a file no one else holds and answers with its path.
     *
     * The name is drawn and created in one step, so there is no window in which another
     * process takes it — which tempnam() followed by fopen() does have. An empty
     * $directory means the system temporary directory.
     */
    public static function temporaryFile(
        string $directory = '',
        string $prefix = 'sconcur-',
        string $suffix = '',
        int $permissions = 0600,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): string {
        return static::text(
            result: static::execute(
                command: FilesCommandEnum::TemporaryFile,
                timeoutMs: $timeoutMs,
                data: [
                    'd'  => $directory,
                    'pf' => $prefix,
                    'sf' => $suffix,
                    'pm' => $permissions,
                ],
            ),
        );
    }

    /**
     * Creates a directory. Recursive creates the parents too, and then a directory that
     * is already there is a success — the mkdir -p behaviour the recursive form is asked
     * for. $permissions are subject to the process umask, as they are for mkdir(2).
     */
    public static function makeDirectory(
        string $path,
        int $permissions = 0755,
        bool $recursive = false,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): void {
        static::execute(
            command: FilesCommandEnum::MakeDirectory,
            timeoutMs: $timeoutMs,
            data: [
                'p'  => $path,
                'pm' => $permissions,
                'rc' => $recursive,
            ],
        );
    }

    /**
     * Removes a directory: empty by default, with everything under it when $recursive.
     * The whole walk happens inside the extension, so a deep tree is one crossing.
     *
     * With $missingOk an absent path is a success; the answer says whether something was
     * actually removed, the way delete() does.
     */
    public static function removeDirectory(
        string $path,
        bool $recursive = false,
        bool $missingOk = false,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): bool {
        return static::count(
            result: static::execute(
                command: FilesCommandEnum::RemoveDirectory,
                timeoutMs: $timeoutMs,
                data: [
                    'p'  => $path,
                    'rc' => $recursive,
                    'mo' => $missingOk,
                ],
            ),
        ) > 0;
    }

    /**
     * Lists one directory, sorted by name.
     *
     * $pattern filters inside the extension — `*`, `?` and `[...]` against the entry name
     * — so a directory of a hundred thousand files does not cross the boundary just to be
     * filtered in PHP. $withMetadata adds a size and a modification time per entry, at the
     * cost of a stat each; without it those fields are null.
     *
     * Not recursive: a tree is walk(), which streams instead of being held whole.
     *
     * @return list<DirectoryEntry>
     */
    public static function list(
        string $path,
        string $pattern = '',
        bool $withMetadata = false,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): array {
        $result = static::execute(
            command: FilesCommandEnum::List,
            timeoutMs: $timeoutMs,
            data: [
                'p'  => $path,
                'pt' => $pattern,
                'wm' => $withMetadata,
            ],
        );

        $decoded = MessagePackTransport::unpack($result->payload);

        /** @var list<array<string, mixed>> $entries */
        $entries = $decoded['e'] ?? [];

        return array_map(
            static fn(array $entry): DirectoryEntry => DirectoryEntry::fromArray($entry),
            $entries,
        );
    }

    /**
     * Reads a file in raw batches.
     *
     * Where read() holds the file twice — once in the extension, once here — this holds
     * one buffer whatever the size. Breaking out early is safe: the flow ends and the
     * extension closes the file.
     *
     * @return ChunksResult<string>
     */
    public static function readChunks(
        string $path,
        int $bufferSizeBytes = 0,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): ChunksResult {
        return new ChunksResult(
            command: FilesCommandEnum::ReadChunks,
            timeoutMs: $timeoutMs,
            data: [
                'p'  => $path,
                'bs' => $bufferSizeBytes,
            ],
        );
    }

    /**
     * Reads a file line by line, in batches.
     *
     * The lines are cut in the extension, so a batch of them costs one crossing where
     * fgets() in a loop costs one per line. Separators are removed, `\r\n` included, and
     * a last line without a terminator is still a line.
     *
     * $maxLineBytes bounds a single line, so a file with no newline in it cannot be
     * buffered whole in the name of streaming.
     *
     * @return LinesResult<string>
     */
    public static function readLines(
        string $path,
        int $batchLines = 0,
        int $bufferSizeBytes = 0,
        int $maxLineBytes = 0,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): LinesResult {
        return new LinesResult(
            command: FilesCommandEnum::ReadLines,
            timeoutMs: $timeoutMs,
            data: [
                'p'  => $path,
                'b'  => $batchLines,
                'bs' => $bufferSizeBytes,
                'ml' => $maxLineBytes,
            ],
        );
    }

    /**
     * Walks a directory tree, a batch of entries at a time.
     *
     * The frontier lives in the extension, so breaking out after the first match costs
     * the first batch and nothing more. $pattern filters the entries that are reported,
     * not where the walk goes.
     *
     * Directory symlinks are listed but never descended into: a tree with a link back
     * into itself has no end.
     *
     * @return WalkResult<DirectoryEntry>
     */
    public static function walk(
        string $path,
        string $pattern = '',
        bool $withMetadata = false,
        int $batchEntries = 0,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): WalkResult {
        return new WalkResult(
            command: FilesCommandEnum::Walk,
            timeoutMs: $timeoutMs,
            data: [
                'p'  => $path,
                'pt' => $pattern,
                'wm' => $withMetadata,
                'b'  => $batchEntries,
            ],
        );
    }

    /**
     * Opens a file to be filled chunk by chunk.
     *
     * Each write() waits until the extension has written the chunk, so a coroutine cannot
     * outrun the disk. close() finishes the file and answers with the total.
     *
     * A writer dropped without a close has its file closed — and removed only if this
     * writer created it, which means only in FileWriteMode::Create. A Replace writer
     * leaves the partial file and an Append writer leaves everything, for the same reason
     * a failed write() does: what the call did not create is not the call's to take.
     *
     * $timeoutMs is taken once and bounds every later write() and the close(): a session
     * is one operation spread over many calls.
     */
    public static function openWriter(
        string $path,
        FileWriteMode $mode = FileWriteMode::Replace,
        int $permissions = 0644,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): FileWriter {
        // Drawn here, like HttpClient draws the request id of a streamed upload: the
        // extension needs one name for the three commands of a session, and the side
        // that opens the session is the side that can hand it to the other two.
        $id = uniqid('fw_', more_entropy: true);

        $result = static::execute(
            command: FilesCommandEnum::WriteOpen,
            timeoutMs: $timeoutMs,
            data: [
                'i'  => $id,
                'p'  => $path,
                'm'  => $mode->value,
                'pm' => $permissions,
            ],
        );

        return new FileWriter(
            id: $id,
            path: $path,
            taskKey: $result->key,
            timeoutMs: $timeoutMs,
        );
    }

    /**
     * Runs one sub-operation, turning a task failure into the exception named for the
     * case. Every public method goes through here.
     *
     * @param array<string, mixed> $data
     */
    protected static function execute(
        FilesCommandEnum $command,
        int $timeoutMs,
        array $data,
    ): TaskResultDto {
        try {
            return FeatureExecutor::exec(
                payload: new FilesPayload(
                    command: $command,
                    timeoutMs: $timeoutMs,
                    data: $data,
                ),
            );
        } catch (TaskErrorException | TaskExecutionException $exception) {
            // Only a task failure is translated. A FlowStoppedException is the deliberate
            // unwind signal and has to reach the coroutine as itself.
            throw FilesFailure::from($exception);
        }
    }

    /**
     * The byte or entry count a structured result carries.
     */
    protected static function count(TaskResultDto $result): int
    {
        return (int) static::field(result: $result, key: 'n');
    }

    /**
     * The single string a result carries — a path under `p`, a digest under `h`.
     */
    protected static function text(TaskResultDto $result, string $key = 'p'): string
    {
        return (string) static::field(result: $result, key: $key);
    }

    /**
     * One field of a structured result, refusing rather than guessing.
     *
     * A missing key used to read as 0 or an empty string, which turns a malformed answer
     * into a plausible one: delete() would report "nothing was there", hashFile() would
     * answer an empty digest that silently mismatches, and temporaryFile() would hand
     * back an empty path for the caller to write to. The rest of this feature refuses to
     * guess — FilesFailure reads the kind rather than matching it, DirectoryEntry says
     * null for "not asked for" — and this is the one place that did.
     */
    protected static function field(TaskResultDto $result, string $key): mixed
    {
        $decoded = MessagePackTransport::unpack($result->payload);

        if (!array_key_exists($key, $decoded)) {
            throw new UnexpectedResponseFormatException(
                message: "The files result carries no '$key' field.",
            );
        }

        return $decoded[$key];
    }
}
