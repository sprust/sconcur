<?php

declare(strict_types=1);

namespace SConcur\Features\Mysql\Results;

readonly class QueryResult
{
    /**
     * @param array<int, string>            $columns
     * @param array<int, array<int, mixed>> $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
    ) {
    }
}
