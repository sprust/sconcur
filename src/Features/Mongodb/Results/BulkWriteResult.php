<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use SConcur\Bson\Int64;
use SConcur\Bson\ObjectId;

readonly class BulkWriteResult
{
    /**
     * @param array<ObjectId|Int64|string|int|float|null> $upsertedIds
     */
    public function __construct(
        public int $insertedCount,
        public int $matchedCount,
        public int $modifiedCount,
        public int $deletedCount,
        public int $upsertedCount,
        public array $upsertedIds,
    ) {
    }
}
