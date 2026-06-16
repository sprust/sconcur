<?php

declare(strict_types=1);

namespace SConcur\Features\Sql\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Features\Sql\Payloads\Base\BaseSqlPayload;
use SConcur\Features\Sql\Payloads\Dto\Connection;
use SConcur\Features\Sql\SqlCommandEnum;

/**
 * The Query command: a row-returning statement (SELECT) executed with positional
 * bindings, streamed back batch by batch. A non-empty transactionId runs it on the
 * pinned transaction; an empty one runs it autocommit on a pooled connection.
 *
 * Go: payloads.QueryParams (ext/internal/features/sql/payloads/payloads.go).
 */
readonly class QueryPayload extends BaseSqlPayload
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
        protected int $batchSize = 50,
    ) {
        parent::__construct(
            method: $method,
            connection: $connection,
        );
    }

    protected function getCommand(): SqlCommandEnum
    {
        return SqlCommandEnum::Query;
    }

    protected function getCommandData(): array
    {
        return [
            'q'  => $this->sql,
            'b'  => $this->bindings,
            'tx' => $this->transactionId,
            'bs' => $this->batchSize,
        ];
    }
}
