<?php

declare(strict_types=1);

namespace SConcur\Features\Files\Dto;

/**
 * One entry of a directory listing.
 *
 * $sizeBytes and $modifiedAtMs are null when the listing was taken without metadata: the
 * caller asked not to pay a stat per entry, and null says "not asked for" rather than
 * pretending the file is empty.
 *
 * Rust: the maps encode_entries() writes (ext/src/features/files/dirs.rs).
 */
readonly class DirectoryEntry
{
    public function __construct(
        public string $name,
        public string $path,
        public bool $isDirectory,
        public bool $isSymlink = false,
        public ?int $sizeBytes = null,
        public ?int $modifiedAtMs = null,
    ) {
    }

    /**
     * @param array<string, mixed> $decoded
     */
    public static function fromArray(array $decoded): self
    {
        return new self(
            name: (string) ($decoded['n'] ?? ''),
            path: (string) ($decoded['p'] ?? ''),
            isDirectory: (bool) ($decoded['d'] ?? false),
            isSymlink: (bool) ($decoded['l'] ?? false),
            sizeBytes: isset($decoded['sz']) ? (int) $decoded['sz'] : null,
            modifiedAtMs: isset($decoded['mt']) ? (int) $decoded['mt'] : null,
        );
    }
}
