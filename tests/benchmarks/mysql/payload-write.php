<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMysqlResolver;

require_once __DIR__ . '/../lib/benchmarker.php';
require_once __DIR__ . '/../lib/payload.php';

$benchmarker = new Benchmarker(
    name: 'mysql-payload-write',
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

$connection = TestMysqlResolver::getConnection(maxOpenConns: 50);

$pdoInsert = $pdo->prepare("INSERT INTO $table (id, payload) VALUES (?, ?)");

// Every call writes its own row (per-mode id ranges), so modes never collide.
$nativeIdBase = $benchmarker->getModeIdBase(modeNumber: 0);
$syncIdBase   = $benchmarker->getModeIdBase(modeNumber: 1);
$asyncIdBase  = $benchmarker->getModeIdBase(modeNumber: 2);

$benchmarker->run(
    nativeCallback: static function (int $callIndex) use ($pdoInsert, $payload, $nativeIdBase): void {
        $pdoInsert->execute([$nativeIdBase + $callIndex + 1, $payload]);
    },
    syncCallback: static function (int $callIndex) use ($connection, $table, $payload, $syncIdBase): int {
        return $connection->exec(
            sql: "INSERT INTO $table (id, payload) VALUES (?, ?)",
            bindings: [$syncIdBase + $callIndex + 1, $payload],
        )->affectedRows;
    },
    asyncCallback: static function (int $callIndex) use ($connection, $table, $payload, $asyncIdBase): int {
        return $connection->exec(
            sql: "INSERT INTO $table (id, payload) VALUES (?, ?)",
            bindings: [$asyncIdBase + $callIndex + 1, $payload],
        )->affectedRows;
    },
);
