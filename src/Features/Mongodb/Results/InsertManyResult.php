<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use SConcur\Bson\Int64;
use SConcur\Bson\ObjectId;

readonly class InsertManyResult
{
    public int $insertedCount;

    /**
     * @param array<ObjectId|Int64|string|int|float> $insertedIds
     */
    public function __construct(
        public array $insertedIds,
    ) {
        $this->insertedCount = count($insertedIds);
    }
}
