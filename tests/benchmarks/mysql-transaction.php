<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMysqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mysql-transaction',
);

TestMysqlResolver::prepareBenchmarkTable();

$table = TestMysqlResolver::$benchmarkTable;

$connection = TestMysqlResolver::getConnection(maxOpenConns: 50);

$pdo       = TestMysqlResolver::getPdo();
$pdoInsert = $pdo->prepare("INSERT INTO $table (name, amount) VALUES (?, ?)");

// One transaction per call: begin -> insert -> commit. Shows the multi-round-trip
// transaction cost (and how the async path overlaps those round-trips across tasks).
$benchmarker->run(
    nativeCallback: static function () use ($pdo, $pdoInsert): void {
        $pdo->beginTransaction();

        $pdoInsert->execute(['native', 1]);

        $pdo->commit();
    },
    syncCallback: static function () use ($connection, $table): void {
        $transaction = $connection->begin();

        $transaction->exec(
            sql: "INSERT INTO $table (name, amount) VALUES (?, ?)",
            bindings: ['sync', 1],
        );

        $transaction->commit();
    },
    asyncCallback: static function () use ($connection, $table): void {
        $transaction = $connection->begin();

        $transaction->exec(
            sql: "INSERT INTO $table (name, amount) VALUES (?, ?)",
            bindings: ['async', 1],
        );

        $transaction->commit();
    },
);
