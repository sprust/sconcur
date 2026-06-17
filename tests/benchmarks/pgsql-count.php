<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestPgsqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'pgsql-count',
);

TestPgsqlResolver::prepareBenchmarkTable(rows: 100);

$table = TestPgsqlResolver::$benchmarkTable;

$connection = TestPgsqlResolver::getConnection(maxOpenConns: 50);

$pdo      = TestPgsqlResolver::getPdo();
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
