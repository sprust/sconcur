<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMysqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mysql-count',
);

TestMysqlResolver::prepareBenchmarkTable(rows: 100);

$table = TestMysqlResolver::$benchmarkTable;

$connection = TestMysqlResolver::getConnection(maxOpenConns: 50);

$pdo      = TestMysqlResolver::getPdo();
$pdoCount = $pdo->prepare("SELECT COUNT(*) AS c FROM $table");

$benchmarker->run(
    nativeCallback: static function () use ($pdoCount): int {
        $pdoCount->execute();

        return (int) $pdoCount->fetchColumn();
    },
    syncCallback: static function () use ($connection, $table): int {
        $rows = $connection->fetchAll(sql: "SELECT COUNT(*) AS c FROM $table");

        return (int) $rows[0]['c'];
    },
    asyncCallback: static function () use ($connection, $table): int {
        $rows = $connection->fetchAll(sql: "SELECT COUNT(*) AS c FROM $table");

        return (int) $rows[0]['c'];
    },
);
