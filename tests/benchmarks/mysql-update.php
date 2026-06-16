<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMysqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mysql-update',
);

TestMysqlResolver::prepareBenchmarkTable(rows: 1);

$table = TestMysqlResolver::$benchmarkTable;

$connection = TestMysqlResolver::getConnection(maxOpenConns: 50);

$pdo       = TestMysqlResolver::getPdo();
$pdoUpdate = $pdo->prepare("UPDATE $table SET amount = amount + 1 WHERE id = ?");

$benchmarker->run(
    nativeCallback: static function () use ($pdoUpdate): int {
        $pdoUpdate->execute([1]);

        return $pdoUpdate->rowCount();
    },
    syncCallback: static function () use ($connection, $table): int {
        return $connection->exec(
            sql: "UPDATE $table SET amount = amount + 1 WHERE id = ?",
            bindings: [1],
        )->affectedRows;
    },
    asyncCallback: static function () use ($connection, $table): int {
        return $connection->exec(
            sql: "UPDATE $table SET amount = amount + 1 WHERE id = ?",
            bindings: [1],
        )->affectedRows;
    },
);
