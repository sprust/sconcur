<?php

declare(strict_types=1);

namespace SConcur\Features\Sql\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Features\Sql\Payloads\Base\BaseSqlPayload;
use SConcur\Features\Sql\Payloads\Dto\Connection;
use SConcur\Features\Sql\SqlCommandEnum;

/**
 * The Commit command: commits the transaction identified by transactionId.
 *
 * Go: payloads.TransactionRefParams (ext/internal/features/sql/payloads/payloads.go).
 */
readonly class CommitPayload extends BaseSqlPayload
{
    public function __construct(
        MethodEnum $method,
        Connection $connection,
        protected string $transactionId,
    ) {
        parent::__construct(
            method: $method,
            connection: $connection,
        );
    }

    protected function getCommand(): SqlCommandEnum
    {
        return SqlCommandEnum::Commit;
    }

    protected function getCommandData(): array
    {
        return [
            'tx' => $this->transactionId,
        ];
    }
}
