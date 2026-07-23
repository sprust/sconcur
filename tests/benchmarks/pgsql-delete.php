<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestPgsqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'pgsql-delete',
);

TestPgsqlResolver::prepareBenchmarkTable(rows: $benchmarker->getDatasetRows());

$table = TestPgsqlResolver::$benchmarkTable;

$connection = TestPgsqlResolver::getConnection(maxOpenConns: 50);

$pdo       = TestPgsqlResolver::getPdo();
$pdoDelete = $pdo->prepare("DELETE FROM $table WHERE id = ?");

// Every call deletes its own row of the seeded dataset (per-mode id ranges), so
// each call is a real delete paying a real commit — no no-op calls.
$nativeIdBase = $benchmarker->getModeIdBase(modeNumber: 0);
$syncIdBase   = $benchmarker->getModeIdBase(modeNumber: 1);
$asyncIdBase  = $benchmarker->getModeIdBase(modeNumber: 2);

$benchmarker->run(
    nativeCallback: static function (int $callIndex) use ($pdoDelete, $nativeIdBase): int {
        $pdoDelete->execute([$nativeIdBase + $callIndex + 1]);

        return $pdoDelete->rowCount();
    },
    syncCallback: static function (int $callIndex) use ($connection, $table, $syncIdBase): int {
        return $connection->exec(
            sql: "DELETE FROM $table WHERE id = \$1",
            bindings: [$syncIdBase + $callIndex + 1],
        )->affectedRows;
    },
    asyncCallback: static function (int $callIndex) use ($connection, $table, $asyncIdBase): int {
        return $connection->exec(
            sql: "DELETE FROM $table WHERE id = \$1",
            bindings: [$asyncIdBase + $callIndex + 1],
        )->affectedRows;
    },
);
