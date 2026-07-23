<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestPgsqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'pgsql-insert',
);

// Inserts land in a table already holding the seeded dataset — a realistic
// insert into a non-empty table with a primary key.
TestPgsqlResolver::prepareBenchmarkTable(rows: $benchmarker->getDatasetRows());

$table = TestPgsqlResolver::$benchmarkTable;

$connection = TestPgsqlResolver::getConnection(maxOpenConns: 50);

$pdo       = TestPgsqlResolver::getPdo();
$pdoInsert = $pdo->prepare("INSERT INTO $table (name, amount) VALUES (?, ?)");

$benchmarker->run(
    nativeCallback: static function () use ($pdoInsert): void {
        $pdoInsert->execute(['native', 1]);
    },
    syncCallback: static function () use ($connection, $table): int {
        return $connection->exec(
            sql: "INSERT INTO $table (name, amount) VALUES (\$1, \$2)",
            bindings: ['sync', 1],
        )->affectedRows;
    },
    asyncCallback: static function () use ($connection, $table): int {
        return $connection->exec(
            sql: "INSERT INTO $table (name, amount) VALUES (\$1, \$2)",
            bindings: ['async', 1],
        )->affectedRows;
    },
);
