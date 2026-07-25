<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';
require_once __DIR__ . '/_payload.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-payload-write',
);

$payload = benchmarkPayload();

$driverCollection  = TestMongodbResolver::getDriverBenchmarkCollection();
$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

$driverCollection->drop();

// Every call writes its own document (per-mode id ranges), so modes never collide.
$nativeIdBase = $benchmarker->getModeIdBase(modeNumber: 0);
$syncIdBase   = $benchmarker->getModeIdBase(modeNumber: 1);
$asyncIdBase  = $benchmarker->getModeIdBase(modeNumber: 2);

$benchmarker->run(
    nativeCallback: static function (int $callIndex) use ($driverCollection, $payload, $nativeIdBase): void {
        $driverCollection->insertOne(['_id' => $nativeIdBase + $callIndex + 1, 'p' => $payload]);
    },
    syncCallback: static function (int $callIndex) use ($sconcurCollection, $payload, $syncIdBase): void {
        $sconcurCollection->insertOne(['_id' => $syncIdBase + $callIndex + 1, 'p' => $payload]);
    },
    asyncCallback: static function (int $callIndex) use ($sconcurCollection, $payload, $asyncIdBase): void {
        $sconcurCollection->insertOne(['_id' => $asyncIdBase + $callIndex + 1, 'p' => $payload]);
    },
);
