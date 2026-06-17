<?php

declare(strict_types=1);

namespace SConcur\Features\Sql\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Features\Sql\Payloads\Base\BaseSqlPayload;
use SConcur\Features\Sql\Payloads\Dto\Connection;
use SConcur\Features\Sql\SqlCommandEnum;

/**
 * The Exec command: a non-row statement (INSERT/UPDATE/DELETE/DDL) with positional
 * bindings, returning affected-rows and last-insert-id. A non-empty transactionId
 * runs it on the pinned transaction; an empty one runs it autocommit.
 *
 * Go: payloads.ExecParams (ext/internal/features/sql/payloads/payloads.go).
 */
readonly class ExecPayload extends BaseSqlPayload
{
    /**
     * @param list<mixed> $bindings
     */
    public function __construct(
        MethodEnum $method,
        Connection $connection,
        protected string $sql,
        protected array $bindings,
        protected string $transactionId = '',
    ) {
        parent::__construct(
            method: $method,
            connection: $connection,
        );
    }

    protected function getCommand(): SqlCommandEnum
    {
        return SqlCommandEnum::Exec;
    }

    protected function getCommandData(): array
    {
        return [
            'q'  => $this->sql,
            'b'  => $this->bindings,
            'tx' => $this->transactionId,
        ];
    }
}
