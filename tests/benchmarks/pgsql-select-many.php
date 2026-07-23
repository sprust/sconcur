<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestPgsqlResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'pgsql-select-many',
);

TestPgsqlResolver::prepareBenchmarkTable(rows: $benchmarker->getDatasetRows());

$table = TestPgsqlResolver::$benchmarkTable;

$connection = TestPgsqlResolver::getConnection(maxOpenConns: 50);

$pdo       = TestPgsqlResolver::getPdo();
$pdoSelect = $pdo->prepare("SELECT id, name, amount FROM $table WHERE id BETWEEN ? AND ? ORDER BY id");

// Every call reads a 100-row window sliding over the mode's own id range of the
// seeded dataset (wraps around when the range is exhausted).
$rowsPerSelect  = 100;
$modeStrideRows = $benchmarker->getModeIdBase(modeNumber: 1);
$nativeIdBase   = $benchmarker->getModeIdBase(modeNumber: 0);
$syncIdBase     = $benchmarker->getModeIdBase(modeNumber: 1);
$asyncIdBase    = $benchmarker->getModeIdBase(modeNumber: 2);

$windowStart = static function (int $idBase, int $callIndex) use ($rowsPerSelect, $modeStrideRows): int {
    return $idBase + (($callIndex * $rowsPerSelect) % ($modeStrideRows - $rowsPerSelect)) + 1;
};

$benchmarker->run(
    nativeCallback: static function (int $callIndex) use ($pdoSelect, $windowStart, $nativeIdBase, $rowsPerSelect): array {
        $firstRowId = $windowStart($nativeIdBase, $callIndex);

        $pdoSelect->execute([$firstRowId, $firstRowId + $rowsPerSelect - 1]);

        return $pdoSelect->fetchAll(PDO::FETCH_ASSOC);
    },
    syncCallback: static function (int $callIndex) use ($connection, $table, $windowStart, $syncIdBase, $rowsPerSelect): array {
        $firstRowId = $windowStart($syncIdBase, $callIndex);

        return $connection->fetchAll(
            sql: "SELECT id, name, amount FROM $table WHERE id BETWEEN \$1 AND \$2 ORDER BY id",
            bindings: [$firstRowId, $firstRowId + $rowsPerSelect - 1],
        );
    },
    asyncCallback: static function (int $callIndex) use ($connection, $table, $windowStart, $asyncIdBase, $rowsPerSelect): array {
        $firstRowId = $windowStart($asyncIdBase, $callIndex);

        return $connection->fetchAll(
            sql: "SELECT id, name, amount FROM $table WHERE id BETWEEN \$1 AND \$2 ORDER BY id",
            bindings: [$firstRowId, $firstRowId + $rowsPerSelect - 1],
        );
    },
);
