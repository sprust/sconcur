<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use SConcur\Features\Mongodb\Types\ObjectId;

readonly class InsertManyResult
{
    protected int $insertedCount;

    /**
     * @param array<ObjectId|string|int|float> $insertedIds
     */
    public function __construct(
        private array $insertedIds,
    ) {
        $this->insertedCount = count($insertedIds);
    }

    public function getInsertedCount(): int
    {
        return $this->insertedCount;
    }

    /**
     * @return array<ObjectId|string|int|float>
     */
    public function getInsertedIds(): array
    {
        return $this->insertedIds;
    }
}
