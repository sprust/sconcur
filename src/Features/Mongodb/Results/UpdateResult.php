<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use SConcur\Bson\Int64;
use SConcur\Bson\ObjectId;

readonly class UpdateResult
{
    public function __construct(
        public int $matchedCount,
        public int $modifiedCount,
        public int $upsertedCount,
        public ObjectId|Int64|string|int|float|null $upsertedId,
    ) {
    }
}
