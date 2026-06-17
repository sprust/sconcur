<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestPgsqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'pgsql-select-one',
);

TestPgsqlResolver::prepareBenchmarkTable(rows: 1);

$table = TestPgsqlResolver::$benchmarkTable;

$connection = TestPgsqlResolver::getConnection(maxOpenConns: 50);

$pdo       = TestPgsqlResolver::getPdo();
$pdoSelect = $pdo->prepare("SELECT id, name, amount FROM $table WHERE id = ?");

$benchmarker->run(
    nativeCallback: static function () use ($pdoSelect): array {
        $pdoSelect->execute([1]);

        return $pdoSelect->fetchAll(PDO::FETCH_ASSOC);
    },
    syncCallback: static function () use ($connection, $table): array {
        return $connection->fetchAll(
            sql: "SELECT id, name, amount FROM $table WHERE id = \$1",
            bindings: [1],
        );
    },
    asyncCallback: static function () use ($connection, $table): array {
        return $connection->fetchAll(
            sql: "SELECT id, name, amount FROM $table WHERE id = \$1",
            bindings: [1],
        );
    },
);
