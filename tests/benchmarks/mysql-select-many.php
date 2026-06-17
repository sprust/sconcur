<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMysqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mysql-select-many',
);

TestMysqlResolver::prepareBenchmarkTable(rows: 100);

$table = TestMysqlResolver::$benchmarkTable;

$connection = TestMysqlResolver::getConnection(maxOpenConns: 50);

$pdo       = TestMysqlResolver::getPdo();
$pdoSelect = $pdo->prepare("SELECT id, name, amount FROM $table ORDER BY id");

$benchmarker->run(
    nativeCallback: static function () use ($pdoSelect): array {
        $pdoSelect->execute();

        return $pdoSelect->fetchAll(PDO::FETCH_ASSOC);
    },
    syncCallback: static function () use ($connection, $table): array {
        return $connection->fetchAll(
            sql: "SELECT id, name, amount FROM $table ORDER BY id",
        );
    },
    asyncCallback: static function () use ($connection, $table): array {
        return $connection->fetchAll(
            sql: "SELECT id, name, amount FROM $table ORDER BY id",
        );
    },
);
