<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/../lib/benchmarker.php';
require_once __DIR__ . '/../lib/payload.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-payload-read',
);

$payload = benchmarkPayload();

$driverCollection  = TestMongodbResolver::getDriverBenchmarkCollection();
$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

$driverCollection->drop();

// One hot document per mode: every call re-reads it, so the WiredTiger cache
// serves the data and what is measured is the transfer + decode path, not the disk.
$nativeIdBase = $benchmarker->getModeIdBase(modeNumber: 0);
$syncIdBase   = $benchmarker->getModeIdBase(modeNumber: 1);
$asyncIdBase  = $benchmarker->getModeIdBase(modeNumber: 2);

foreach ([$nativeIdBase, $syncIdBase, $asyncIdBase] as $modeIdBase) {
    $driverCollection->insertOne(['_id' => $modeIdBase + 1, 'p' => $payload]);
}

$benchmarker->run(
    nativeCallback: static function () use ($driverCollection, $nativeIdBase) {
        return $driverCollection->findOne(['_id' => $nativeIdBase + 1]);
    },
    syncCallback: static function () use ($sconcurCollection, $syncIdBase) {
        return $sconcurCollection->findOne(
            filter: ['_id' => $syncIdBase + 1],
        );
    },
    asyncCallback: static function () use ($sconcurCollection, $asyncIdBase) {
        return $sconcurCollection->findOne(
            filter: ['_id' => $asyncIdBase + 1],
        );
    },
);
