<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use SConcur\Features\Mongodb\Types\ObjectId;

readonly class InsertOneResult
{
    public function __construct(
        public ObjectId|string|int|float|null $insertedId,
    ) {
    }
}
