<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use SConcur\Bson\Int64;
use SConcur\Bson\ObjectId;

readonly class InsertOneResult
{
    public function __construct(
        public ObjectId|Int64|string|int|float|null $insertedId,
    ) {
    }
}
