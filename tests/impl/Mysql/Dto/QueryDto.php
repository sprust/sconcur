<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Mysql\Dto;

readonly class QueryDto
{
    /**
     * @param array<int, mixed> $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
    ) {
    }
}
