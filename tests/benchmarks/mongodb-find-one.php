<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-find-one',
);

TestMongodbResolver::seedBenchmarkCollection(documents: $benchmarker->getDatasetRows());

$driverCollection  = TestMongodbResolver::getDriverBenchmarkCollection();
$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

// Every call reads its own document by `_id` out of the seeded dataset
// (per-mode id ranges), so modes never share a hot document.
$nativeIdBase = $benchmarker->getModeIdBase(modeNumber: 0);
$syncIdBase   = $benchmarker->getModeIdBase(modeNumber: 1);
$asyncIdBase  = $benchmarker->getModeIdBase(modeNumber: 2);

$benchmarker->run(
    nativeCallback: static function (int $callIndex) use ($driverCollection, $nativeIdBase) {
        return $driverCollection->findOne(['_id' => $nativeIdBase + $callIndex + 1]);
    },
    syncCallback: static function (int $callIndex) use ($sconcurCollection, $syncIdBase) {
        return $sconcurCollection->findOne(
            filter: ['_id' => $syncIdBase + $callIndex + 1],
        );
    },
    asyncCallback: static function (int $callIndex) use ($sconcurCollection, $asyncIdBase) {
        return $sconcurCollection->findOne(
            filter: ['_id' => $asyncIdBase + $callIndex + 1],
        );
    },
);
