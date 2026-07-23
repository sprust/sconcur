<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-update-one',
);

TestMongodbResolver::seedBenchmarkCollection(documents: $benchmarker->getDatasetRows());

$driverCollection  = TestMongodbResolver::getDriverBenchmarkCollection();
$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

$driverUpdate = [
    '$set' => [
        'date' => TestMongodbResolver::getDriverDateTime(),
    ],
];

$sconcurUpdate = [
    '$set' => [
        'date' => TestMongodbResolver::getSconcurDateTime(),
    ],
];

// Every call updates its own document by `_id` out of the seeded dataset
// (per-mode id ranges), so a shared-document lock never serializes the fan-out.
$nativeIdBase = $benchmarker->getModeIdBase(modeNumber: 0);
$syncIdBase   = $benchmarker->getModeIdBase(modeNumber: 1);
$asyncIdBase  = $benchmarker->getModeIdBase(modeNumber: 2);

$benchmarker->run(
    nativeCallback: static function (int $callIndex) use ($driverCollection, $driverUpdate, $nativeIdBase) {
        return $driverCollection
            ->updateOne(
                filter: ['_id' => $nativeIdBase + $callIndex + 1],
                update: $driverUpdate,
            )
            ->getModifiedCount();
    },
    syncCallback: static function (int $callIndex) use ($sconcurCollection, $sconcurUpdate, $syncIdBase) {
        return $sconcurCollection->updateOne(
            filter: ['_id' => $syncIdBase + $callIndex + 1],
            update: $sconcurUpdate,
        )->modifiedCount;
    },
    asyncCallback: static function (int $callIndex) use ($sconcurCollection, $sconcurUpdate, $asyncIdBase) {
        return $sconcurCollection->updateOne(
            filter: ['_id' => $asyncIdBase + $callIndex + 1],
            update: $sconcurUpdate,
        )->modifiedCount;
    },
);
