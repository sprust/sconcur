<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMysqlResolver;

require_once __DIR__ . '/_benchmarker.php';
require_once __DIR__ . '/_payload.php';

$benchmarker = new Benchmarker(
    name: 'mysql-payload-read',
);

$payload = benchmarkPayload();
$table   = 'sconcur_payload_bench';

$pdo = TestMysqlResolver::getPdo();

$pdo->exec("DROP TABLE IF EXISTS $table");
$pdo->exec(
    "CREATE TABLE $table (
        id INT PRIMARY KEY,
        payload MEDIUMTEXT NOT NULL
    ) ENGINE=InnoDB",
);

// One hot row per mode: every call re-reads it, so the page cache serves the
// data and what is measured is the transfer + decode path, not the disk.
$nativeIdBase = $benchmarker->getModeIdBase(modeNumber: 0);
$syncIdBase   = $benchmarker->getModeIdBase(modeNumber: 1);
$asyncIdBase  = $benchmarker->getModeIdBase(modeNumber: 2);

$seedInsert = $pdo->prepare("INSERT INTO $table (id, payload) VALUES (?, ?)");

foreach ([$nativeIdBase, $syncIdBase, $asyncIdBase] as $modeIdBase) {
    $seedInsert->execute([$modeIdBase + 1, $payload]);
}

$connection = TestMysqlResolver::getConnection(maxOpenConns: 50);

$pdoSelect = $pdo->prepare("SELECT payload FROM $table WHERE id = ?");

$benchmarker->run(
    nativeCallback: static function () use ($pdoSelect, $nativeIdBase): array {
        $pdoSelect->execute([$nativeIdBase + 1]);

        return $pdoSelect->fetchAll(PDO::FETCH_ASSOC);
    },
    syncCallback: static function () use ($connection, $table, $syncIdBase): array {
        return $connection->fetchAll(
            sql: "SELECT payload FROM $table WHERE id = ?",
            bindings: [$syncIdBase + 1],
        );
    },
    asyncCallback: static function () use ($connection, $table, $asyncIdBase): array {
        return $connection->fetchAll(
            sql: "SELECT payload FROM $table WHERE id = ?",
            bindings: [$asyncIdBase + 1],
        );
    },
);
