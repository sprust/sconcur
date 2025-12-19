<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

use SConcur\Features\Mongodb\Types\ObjectId;

readonly class UpdateResult
{
    public function __construct(
        public int $matchedCount,
        public int $modifiedCount,
        public int $upsertedCount,
        public ObjectId|string|int|float|null $upsertedId,
    ) {
    }
}
