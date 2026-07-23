<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-delete-one',
);

TestMongodbResolver::seedBenchmarkCollection(documents: $benchmarker->getDatasetRows());

$driverCollection  = TestMongodbResolver::getDriverBenchmarkCollection();
$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

// Every call deletes its own document by `_id` out of the seeded dataset
// (per-mode id ranges), so each call is a real delete — no no-op calls.
$nativeIdBase = $benchmarker->getModeIdBase(modeNumber: 0);
$syncIdBase   = $benchmarker->getModeIdBase(modeNumber: 1);
$asyncIdBase  = $benchmarker->getModeIdBase(modeNumber: 2);

$benchmarker->run(
    nativeCallback: static function (int $callIndex) use ($driverCollection, $nativeIdBase) {
        return $driverCollection
            ->deleteOne(
                filter: ['_id' => $nativeIdBase + $callIndex + 1],
            )
            ->getDeletedCount();
    },
    syncCallback: static function (int $callIndex) use ($sconcurCollection, $syncIdBase) {
        return $sconcurCollection->deleteOne(
            filter: ['_id' => $syncIdBase + $callIndex + 1],
        )->deletedCount;
    },
    asyncCallback: static function (int $callIndex) use ($sconcurCollection, $asyncIdBase) {
        return $sconcurCollection->deleteOne(
            filter: ['_id' => $asyncIdBase + $callIndex + 1],
        )->deletedCount;
    },
);
