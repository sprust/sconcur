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
use SConcur\Features\Files\Support\FilesFailure;
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
     * How much read() will pull across the boundary before refusing (64 MiB). The limit
     * exists because a one-shot read holds the file twice — once in the extension, once
     * in PHP; readChunks() has no such peak. 0 lifts it.
     */
    public const int DEFAULT_MAX_READ_BYTES = 67_108_864;

    /**
     * Reads a file whole, or the byte range asked for.
     *
     * $lengthBytes of 0 reads to the end of the file. $maxReadBytes refuses a file bigger
     * than the limit with a FileTooLargeException instead of spending the memory.
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
            static::execute(
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
     */
    public static function writeAtomic(
        string $path,
        string $contents,
        int $permissions = 0644,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): int {
        return static::count(
            static::execute(
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
     */
    public static function truncate(
        string $path,
        int $sizeBytes = 0,
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
            static::execute(
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
     * Moves a file. A rename within one filesystem; across filesystems the extension
     * copies and removes the source, which is what PHP's rename() does too.
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
            static::execute(
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
            static::execute(
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
            static::execute(
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
     */
    public static function removeDirectory(
        string $path,
        bool $recursive = false,
        bool $missingOk = false,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): void {
        static::execute(
            command: FilesCommandEnum::RemoveDirectory,
            timeoutMs: $timeoutMs,
            data: [
                'p'  => $path,
                'rc' => $recursive,
                'mo' => $missingOk,
            ],
        );
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
        $decoded = MessagePackTransport::unpack($result->payload);

        return (int) ($decoded['n'] ?? 0);
    }

    /**
     * The single path a result carries.
     */
    protected static function text(TaskResultDto $result): string
    {
        $decoded = MessagePackTransport::unpack($result->payload);

        return (string) ($decoded['p'] ?? '');
    }
}
