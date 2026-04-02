<?php

namespace SConcur\Tests\Impl\Mysql\Repositories;

use SConcur\Tests\Impl\Mysql\Dto\TestMysqlDto;

interface RepositoryInterface
{
    public function dropTableIfExists(): void;

    public function createTableIfNotExists(): void;

    public function insert(TestMysqlDto $dto): int;
}