<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMysqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mysql-insert',
);

TestMysqlResolver::prepareBenchmarkTable();

$table = TestMysqlResolver::$benchmarkTable;

$connection = TestMysqlResolver::getConnection(maxOpenConns: 50);

$pdo       = TestMysqlResolver::getPdo();
$pdoInsert = $pdo->prepare("INSERT INTO $table (name, amount) VALUES (?, ?)");

$benchmarker->run(
    nativeCallback: static function () use ($pdoInsert): void {
        $pdoInsert->execute(['native', 1]);
    },
    syncCallback: static function () use ($connection, $table): int {
        return $connection->exec(
            sql: "INSERT INTO $table (name, amount) VALUES (?, ?)",
            bindings: ['sync', 1],
        )->affectedRows;
    },
    asyncCallback: static function () use ($connection, $table): int {
        return $connection->exec(
            sql: "INSERT INTO $table (name, amount) VALUES (?, ?)",
            bindings: ['async', 1],
        )->affectedRows;
    },
);
