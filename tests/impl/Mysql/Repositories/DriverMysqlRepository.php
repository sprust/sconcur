<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Mysql\Repositories;

use PDO;
use RuntimeException;
use SConcur\Tests\Impl\Mysql\Dto\TestMysqlDto;
use SConcur\Tests\Impl\Mysql\Sql\SqlQueries;

readonly class DriverMysqlRepository implements RepositoryInterface
{
    public function __construct(
        protected PDO $pdo,
        protected string $tableName,
    ) {
    }

    public function dropTableIfExists(): void
    {
        $this->pdo->exec(
            SqlQueries::dropTableIfExists($this->tableName)
        );
    }

    public function createTableIfNotExists(): void
    {
        $this->pdo->exec(
            SqlQueries::createTableIfNotExists($this->tableName)
        );
    }

    public function insert(TestMysqlDto $dto): int
    {
        $query = SqlQueries::insert($dto, $this->tableName);

        $statement = $this->pdo->prepare($query->sql);

        if ($statement === false) {
            throw new RuntimeException('Failed to prepare statement');
        }

        foreach ($query->bindings as $key => $value) {
            $statement->bindValue(
                param: is_string($key) ? $key : $key + 1,
                value: $value,
                type: match (true) {
                    is_int($value) => PDO::PARAM_INT,
                    is_resource($value) => PDO::PARAM_LOB,
                    default => PDO::PARAM_STR
                },
            );
        }

        $result = $statement->execute();

        if ($result === false) {
            throw new RuntimeException('Failed to execute statement');
        }

        return (int) $this->pdo->lastInsertId();
    }
}
