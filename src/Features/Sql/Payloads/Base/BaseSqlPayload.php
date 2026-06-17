<?php

declare(strict_types=1);

namespace SConcur\Features\Sql\Payloads\Base;

use SConcur\Features\MethodEnum;
use SConcur\Features\Sql\Payloads\Dto\Connection;
use SConcur\Features\Sql\SqlCommandEnum;
use SConcur\Transport\PayloadInterface;

/**
 * Builds the command envelope (cm/dsn/to/mo/mi/cl/dt) every SQL payload sends:
 * the sub-operation command, the connection settings and the command body.
 * Mirrors Base\BaseMongodbPayload and Base\BaseHttpClientPayload.
 *
 * The concrete MethodEnum (Mysql now, Pgsql later) is supplied by the driver
 * facade, so one shared payload serves every SQL driver.
 *
 * Go: payloads.Envelope (ext/internal/features/sql/payloads/payloads.go).
 */
abstract readonly class BaseSqlPayload implements PayloadInterface
{
    abstract protected function getCommand(): SqlCommandEnum;

    /**
     * @return array<string, mixed>
     */
    abstract protected function getCommandData(): array;

    public function __construct(
        protected MethodEnum $method,
        protected Connection $connection,
    ) {
    }

    public function getMethod(): MethodEnum
    {
        return $this->method;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return [
            'cm'  => $this->getCommand()->value,
            'dsn' => $this->connection->dsn,
            'to'  => $this->connection->timeoutMs,
            'mo'  => $this->connection->maxOpenConns,
            'mi'  => $this->connection->maxIdleConns,
            'cl'  => $this->connection->connMaxLifetimeMs,
            'dt'  => $this->getCommandData(),
        ];
    }
}
