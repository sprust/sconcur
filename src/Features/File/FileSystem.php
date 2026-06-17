<?php

declare(strict_types=1);

namespace SConcur\Features\File;

use SConcur\Features\FeatureExecutor;
use SConcur\Features\File\Payloads\OpenPayload;

/**
 * Asynchronous file I/O on top of Go `os.File`. open() returns a File handle whose
 * reads/writes run in the Go extension while the calling coroutine suspends, so a
 * loop of reads/writes streams chunk by chunk and many files can be processed
 * concurrently inside a WaitGroup. Outside a WaitGroup the same calls work
 * synchronously.
 *
 * The PHP-style mode set of fopen is supported: r, r+, w, w+, a, a+, x, x+, c, c+
 * (with an optional b/t suffix). The path is the caller's responsibility — there
 * is no sandboxing. See .ai/plans/file.md.
 */
readonly class FileSystem
{
    public int $timeoutMs;

    public function __construct(?int $timeoutMs = null)
    {
        $this->timeoutMs = $timeoutMs ?: 30000;
    }

    /**
     * Opens $path in the given fopen-style $mode and returns the handle. $perm sets
     * the permission bits used when the mode creates the file (default 0644).
     */
    public function open(string $path, string $mode, int $perm = 0644): File
    {
        $fileMode = FileMode::fromString($mode);

        $taskResult = FeatureExecutor::exec(
            payload: new OpenPayload(
                path: $path,
                mode: $fileMode->value,
                perm: $perm,
                timeoutMs: $this->timeoutMs,
            ),
        );

        return new File(
            handleId: $taskResult->key,
            mode: $fileMode,
            timeoutMs: $this->timeoutMs,
        );
    }

    /**
     * Opens $path read-only, reads it to the end and closes it. Convenience over
     * open()/getContents()/close() for small files.
     */
    public function read(string $path): string
    {
        $file = $this->open(path: $path, mode: 'r');

        try {
            return $file->getContents();
        } finally {
            $file->close();
        }
    }

    /**
     * Opens $path in $mode (truncating by default), writes $data and closes it.
     * Returns the number of bytes written.
     */
    public function write(string $path, string $data, string $mode = 'w'): int
    {
        $file = $this->open(path: $path, mode: $mode);

        try {
            return $file->write($data);
        } finally {
            $file->close();
        }
    }

    /**
     * Appends $data to $path (creating it if missing). Returns the bytes written.
     */
    public function append(string $path, string $data): int
    {
        return $this->write(
            path: $path,
            data: $data,
            mode: 'a',
        );
    }

    /**
     * Whether $path exists. A local filesystem stat (PHP file_exists), not an
     * extension round-trip: path-based filesystem operations land in a later
     * version (see .ai/plans/file.md).
     */
    public function exists(string $path): bool
    {
        return file_exists($path);
    }
}
