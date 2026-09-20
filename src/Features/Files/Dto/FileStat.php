<?php

declare(strict_types=1);

namespace SConcur\Features\Files\Dto;

/**
 * Everything one stat(2) knows about a path, answered in a single crossing.
 *
 * PHP asks the same questions with file_exists(), is_file(), is_dir(), filesize() and
 * filemtime() — five calls, five pauses of the thread. This is one.
 *
 * A path that is not there is not a failure: $exists is false and every other field holds
 * its zero value, which is what lets Files::exists() be a reading of this rather than a
 * command of its own.
 *
 * Rust: the map encode_stat() writes (ext/src/features/files/meta.rs).
 */
readonly class FileStat
{
    /**
     * @param int $sizeBytes    the file's size; 0 for a directory or a path that does not
     *                          report one (a file under /proc, say)
     * @param int $modifiedAtMs milliseconds since the epoch, negative for a stamp before
     *                          it, 0 when the filesystem does not carry the time
     * @param int $permissions  the permission bits alone, without the type bits above
     *                          them — comparable against 0644 with no masking
     */
    public function __construct(
        public bool $exists,
        public bool $isFile = false,
        public bool $isDirectory = false,
        public bool $isSymlink = false,
        public int $sizeBytes = 0,
        public int $modifiedAtMs = 0,
        public int $accessedAtMs = 0,
        public int $createdAtMs = 0,
        public int $permissions = 0,
        public int $userId = 0,
        public int $groupId = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $decoded
     */
    public static function fromArray(array $decoded): self
    {
        return new self(
            exists: (bool) ($decoded['ex'] ?? false),
            isFile: (bool) ($decoded['isf'] ?? false),
            isDirectory: (bool) ($decoded['isd'] ?? false),
            isSymlink: (bool) ($decoded['isl'] ?? false),
            sizeBytes: (int) ($decoded['sz'] ?? 0),
            modifiedAtMs: (int) ($decoded['mt'] ?? 0),
            accessedAtMs: (int) ($decoded['at'] ?? 0),
            createdAtMs: (int) ($decoded['ct'] ?? 0),
            permissions: (int) ($decoded['pm'] ?? 0),
            userId: (int) ($decoded['ui'] ?? 0),
            groupId: (int) ($decoded['gi'] ?? 0),
        );
    }
}
