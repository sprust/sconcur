<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMysqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mysql-select-one',
);

TestMysqlResolver::prepareBenchmarkTable(rows: 1);

$table = TestMysqlResolver::$benchmarkTable;

$connection = TestMysqlResolver::getConnection(maxOpenConns: 50);

$pdo       = TestMysqlResolver::getPdo();
$pdoSelect = $pdo->prepare("SELECT id, name, amount FROM $table WHERE id = ?");

$benchmarker->run(
    nativeCallback: static function () use ($pdoSelect): array {
        $pdoSelect->execute([1]);

        return $pdoSelect->fetchAll(PDO::FETCH_ASSOC);
    },
    syncCallback: static function () use ($connection, $table): array {
        return $connection->fetchAll(
            sql: "SELECT id, name, amount FROM $table WHERE id = ?",
            bindings: [1],
        );
    },
    asyncCallback: static function () use ($connection, $table): array {
        return $connection->fetchAll(
            sql: "SELECT id, name, amount FROM $table WHERE id = ?",
            bindings: [1],
        );
    },
);
