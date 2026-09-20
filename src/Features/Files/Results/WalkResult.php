<?php

declare(strict_types=1);

namespace SConcur\Features\Files\Results;

use SConcur\Dto\TaskResultDto;
use SConcur\Features\Files\Dto\DirectoryEntry;
use SConcur\Features\Files\Support\BatchIterator;
use SConcur\Transport\MessagePackTransport;

/**
 * A directory tree walked batch by batch. The frontier lives in the extension, so
 * breaking out after the first match costs the first batch and nothing more.
 *
 * Directory symlinks are listed but never descended into: a tree with a link back into
 * itself has no end.
 *
 * @extends BatchIterator<DirectoryEntry>
 */
class WalkResult extends BatchIterator
{
    /**
     * @return list<DirectoryEntry>
     */
    protected function decode(TaskResultDto $result): array
    {
        $decoded = MessagePackTransport::unpack($result->payload);

        /** @var list<array<string, mixed>> $entries */
        $entries = $decoded['e'] ?? [];

        return array_map(
            static fn(array $entry): DirectoryEntry => DirectoryEntry::fromArray($entry),
            $entries,
        );
    }
}
