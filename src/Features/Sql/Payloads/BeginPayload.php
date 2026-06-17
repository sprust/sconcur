<?php

declare(strict_types=1);

namespace SConcur\Features\Sql\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Features\Sql\Payloads\Base\BaseSqlPayload;
use SConcur\Features\Sql\Payloads\Dto\Connection;
use SConcur\Features\Sql\SqlCommandEnum;

/**
 * The Begin command: opens a transaction on a pooled connection and keeps it
 * alive (the result carries hasNext) so later query/exec/commit/rollback commands
 * reach it by the returned task key (the transaction id).
 *
 * Go: payloads.BeginParams (ext/internal/features/sql/payloads/payloads.go).
 */
readonly class BeginPayload extends BaseSqlPayload
{
    public function __construct(
        MethodEnum $method,
        Connection $connection,
        protected int $isolationLevel = 0,
        protected bool $readOnly = false,
    ) {
        parent::__construct(
            method: $method,
            connection: $connection,
        );
    }

    protected function getCommand(): SqlCommandEnum
    {
        return SqlCommandEnum::Begin;
    }

    protected function getCommandData(): array
    {
        return [
            'iso' => $this->isolationLevel,
            'ro'  => $this->readOnly,
        ];
    }
}
