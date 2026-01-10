<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Results;

readonly class DeleteResult
{
    public function __construct(
        public int $deletedCount,
    ) {
    }
}
