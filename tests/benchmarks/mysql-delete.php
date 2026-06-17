<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMysqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mysql-delete',
);

TestMysqlResolver::prepareBenchmarkTable(rows: 1);

$table = TestMysqlResolver::$benchmarkTable;

$connection = TestMysqlResolver::getConnection(maxOpenConns: 50);

$pdo       = TestMysqlResolver::getPdo();
$pdoDelete = $pdo->prepare("DELETE FROM $table WHERE id = ?");

// Like the MongoDB delete-one benchmark, the filter is fixed: the first call
// removes the row and later ones are no-ops, but each still measures the round-trip.
$benchmarker->run(
    nativeCallback: static function () use ($pdoDelete): int {
        $pdoDelete->execute([1]);

        return $pdoDelete->rowCount();
    },
    syncCallback: static function () use ($connection, $table): int {
        return $connection->exec(
            sql: "DELETE FROM $table WHERE id = ?",
            bindings: [1],
        )->affectedRows;
    },
    asyncCallback: static function () use ($connection, $table): int {
        return $connection->exec(
            sql: "DELETE FROM $table WHERE id = ?",
            bindings: [1],
        )->affectedRows;
    },
);
