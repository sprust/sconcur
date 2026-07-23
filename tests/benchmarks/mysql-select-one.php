<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMysqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mysql-select-one',
);

TestMysqlResolver::prepareBenchmarkTable(rows: $benchmarker->getDatasetRows());

$table = TestMysqlResolver::$benchmarkTable;

$connection = TestMysqlResolver::getConnection(maxOpenConns: 50);

$pdo       = TestMysqlResolver::getPdo();
$pdoSelect = $pdo->prepare("SELECT id, name, amount FROM $table WHERE id = ?");

// Every call reads its own row out of the seeded dataset (per-mode id ranges),
// so modes never share a hot row.
$nativeIdBase = $benchmarker->getModeIdBase(modeNumber: 0);
$syncIdBase   = $benchmarker->getModeIdBase(modeNumber: 1);
$asyncIdBase  = $benchmarker->getModeIdBase(modeNumber: 2);

$benchmarker->run(
    nativeCallback: static function (int $callIndex) use ($pdoSelect, $nativeIdBase): array {
        $pdoSelect->execute([$nativeIdBase + $callIndex + 1]);

        return $pdoSelect->fetchAll(PDO::FETCH_ASSOC);
    },
    syncCallback: static function (int $callIndex) use ($connection, $table, $syncIdBase): array {
        return $connection->fetchAll(
            sql: "SELECT id, name, amount FROM $table WHERE id = ?",
            bindings: [$syncIdBase + $callIndex + 1],
        );
    },
    asyncCallback: static function (int $callIndex) use ($connection, $table, $asyncIdBase): array {
        return $connection->fetchAll(
            sql: "SELECT id, name, amount FROM $table WHERE id = ?",
            bindings: [$asyncIdBase + $callIndex + 1],
        );
    },
);
