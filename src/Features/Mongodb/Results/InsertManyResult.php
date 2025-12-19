<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use SConcur\Features\Mongodb\Types\ObjectId;

readonly class InsertManyResult
{
    public int $insertedCount;

    /**
     * @param array<ObjectId|string|int|float> $insertedIds
     */
    public function __construct(
        public array $insertedIds,
    ) {
        $this->insertedCount = count($insertedIds);
    }
}
