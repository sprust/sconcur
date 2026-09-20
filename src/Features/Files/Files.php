<?php

declare(strict_types=1);

namespace SConcur\Features\Files;

use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\FeatureExecutor;
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
}
