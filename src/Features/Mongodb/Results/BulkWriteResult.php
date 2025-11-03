<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use MongoDB\BSON\ObjectId;
use RuntimeException;

class BulkWriteResult
{
    /**
     * @param array<ObjectId|string|int|float|null> $upsertedIds
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

    public function getDeletedCount(): int
    {
        return $this->deletedCount;
    }

    public function getInsertedCount(): int
    {
        return $this->insertedCount;
    }

    /**
     * @return array<ObjectId|string|int|float|null>
     */
    public function getInsertedIds(): array
    {
        throw new RuntimeException('Not implemented');
    }

    public function getMatchedCount(): int
    {
        return $this->matchedCount;
    }

    public function getModifiedCount(): int
    {
        return $this->modifiedCount;
    }

    public function getUpsertedCount(): int
    {
        return $this->upsertedCount;
    }

    /**
     * @return array<ObjectId|string|int|float|null>
     */
    public function getUpsertedIds(): array
    {
        return $this->upsertedIds;
    }

    public function isAcknowledged(): true
    {
        return true;
    }

}
