<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use MongoDB\BSON\ObjectId;

class InsertOneResult
{
    public function __construct(
        public ObjectId|string|int|float|null $insertedId,
    ) {
    }

    public function getInsertedCount(): int
    {
        return 1;
    }

    public function getInsertedId(): ObjectId|string|int|float|null
    {
        return $this->insertedId;
    }

    public function isAcknowledged(): true
    {
        return true;
    }
}
