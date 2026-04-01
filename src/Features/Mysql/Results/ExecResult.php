<?php

declare(strict_types=1);

namespace SConcur\Features\Mysql\Results;

readonly class ExecResult
{
    public function __construct(
        public int $rowsAffected,
        public int $lastInsertId,
    ) {
    }
}
