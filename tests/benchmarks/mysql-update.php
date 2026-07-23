<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMysqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mysql-update',
);

TestMysqlResolver::prepareBenchmarkTable(rows: $benchmarker->getDatasetRows());

$table = TestMysqlResolver::$benchmarkTable;

$connection = TestMysqlResolver::getConnection(maxOpenConns: 50);

$pdo       = TestMysqlResolver::getPdo();
$pdoUpdate = $pdo->prepare("UPDATE $table SET amount = amount + 1 WHERE id = ?");

// Every call updates its own row of the seeded dataset (per-mode id ranges), so
// a shared-row lock never serializes the fan-out.
$nativeIdBase = $benchmarker->getModeIdBase(modeNumber: 0);
$syncIdBase   = $benchmarker->getModeIdBase(modeNumber: 1);
$asyncIdBase  = $benchmarker->getModeIdBase(modeNumber: 2);

$benchmarker->run(
    nativeCallback: static function (int $callIndex) use ($pdoUpdate, $nativeIdBase): int {
        $pdoUpdate->execute([$nativeIdBase + $callIndex + 1]);

        return $pdoUpdate->rowCount();
    },
    syncCallback: static function (int $callIndex) use ($connection, $table, $syncIdBase): int {
        return $connection->exec(
            sql: "UPDATE $table SET amount = amount + 1 WHERE id = ?",
            bindings: [$syncIdBase + $callIndex + 1],
        )->affectedRows;
    },
    asyncCallback: static function (int $callIndex) use ($connection, $table, $asyncIdBase): int {
        return $connection->exec(
            sql: "UPDATE $table SET amount = amount + 1 WHERE id = ?",
            bindings: [$asyncIdBase + $callIndex + 1],
        )->affectedRows;
    },
);
