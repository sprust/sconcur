<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Mysql\Repositories;

use SConcur\Features\Mysql\Connection\Client;
use SConcur\Features\Mysql\Serialization\BindingSerializer;
use SConcur\Tests\Impl\Mysql\Dto\TestMysqlDto;
use SConcur\Tests\Impl\Mysql\Sql\SqlQueries;

readonly class SconcurMysqlRepository implements RepositoryInterface
{
    public function __construct(
        protected Client $client,
        protected string $tableName,
    ) {
    }

    public function dropTableIfExists(): void
    {
        $this->client->exec(
            sql: SqlQueries::dropTableIfExists($this->tableName)
        );
    }

    public function createTableIfNotExists(): void
    {
        $this->client->exec(
            sql: SqlQueries::createTableIfNotExists($this->tableName)
        );
    }

    public function insert(TestMysqlDto $dto): int
    {
        $dtoClone = clone $dto;

        $dtoClone->binaryCol     = $dto->binaryCol ? BindingSerializer::bin($dto->binaryCol) : null;
        $dtoClone->varbinaryCol  = $dto->varbinaryCol ? BindingSerializer::bin($dto->varbinaryCol) : null;
        $dtoClone->blobCol       = $dto->blobCol ? BindingSerializer::bin($dto->blobCol) : null;
        $dtoClone->tinyblobCol   = $dto->tinyblobCol ? BindingSerializer::bin($dto->tinyblobCol) : null;
        $dtoClone->mediumblobCol = $dto->mediumblobCol ? BindingSerializer::bin($dto->mediumblobCol) : null;
        $dtoClone->longblobCol   = $dto->longblobCol ? BindingSerializer::bin($dto->longblobCol) : null;

        $query = SqlQueries::insert(
            dto: $dtoClone,
            tableName: $this->tableName,
        );

        return $this->client->exec(
            sql: $query->sql,
            bindings: $query->bindings
        )->lastInsertId;
    }
}
