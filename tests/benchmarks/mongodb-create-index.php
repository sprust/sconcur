<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-create-index',
);

// Every index is built over the seeded dataset (a real full-collection scan).
TestMongodbResolver::seedBenchmarkCollection(documents: $benchmarker->getDatasetRows());

$driverCollection  = TestMongodbResolver::getDriverBenchmarkCollection();
$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

if (iterator_count($driverCollection->listIndexes()) > 0) {
    $driverCollection->dropIndexes();
}

$index = 0;

$benchmarker->run(
    // No warm-up: every call creates a real index and MongoDB caps a collection
    // at 64 indexes — 3 modes x 20 fits, warm-up extras would not.
    nativeCallback: static function () use ($driverCollection, &$index) {
        ++$index;

        return $driverCollection->createIndex([
            uniqid("$index-native_") => 1,
            uniqid()                 => -1,
        ]);
    },
    syncCallback: static function () use ($sconcurCollection, &$index) {
        ++$index;

        $indexName = "$index-sync";

        return $sconcurCollection->createIndex(
            keys: [
                uniqid("{$indexName}_") => 1,
                uniqid()                => -1,
            ],
            name: $indexName,
        );
    },
    asyncCallback: static function () use ($sconcurCollection, &$index) {
        ++$index;

        $indexName = "$index-async";

        return $sconcurCollection->createIndex(
            keys: [
                uniqid("{$indexName}_") => 1,
                uniqid()                => -1,
            ],
            name: $indexName,
        );
    },
    warmup: false,
);

function makeFilter(mixed $objectId, mixed $dateTime): array
{
    return [
        'IIID' => $objectId,
        'date' => ['$lt' => $dateTime],
    ];
}
