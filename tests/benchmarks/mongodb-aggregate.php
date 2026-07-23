<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-aggregate',
);

// The $match stage matches every seeded document (shared IIID, bool = true);
// $limit stops the scan after the first 30 hits on the seeded dataset.
TestMongodbResolver::seedBenchmarkCollection(documents: $benchmarker->getDatasetRows());

$driverCollection  = TestMongodbResolver::getDriverBenchmarkCollection();
$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

$driverPipeline = makePipeline(
    TestMongodbResolver::getDriverObjectId()
);

$sconcurPipeline = makePipeline(
    TestMongodbResolver::getSconcurObjectId()
);

$isLogProcess = $benchmarker->isLogProcess();

$nativeCallback = static function () use ($driverCollection, $driverPipeline, $isLogProcess) {
    $item = uniqid();

    $aggregate = $driverCollection->aggregate($driverPipeline);

    foreach ($aggregate as $document) {
        $id = $document['_id'];

        if (!$isLogProcess) {
            continue;
        }

        echo "aggregate-$item: document: $id\n";
    }
};

$sconcurCallback = static function () use ($sconcurCollection, $sconcurPipeline, $isLogProcess) {
    $item = uniqid();

    $aggregate = $sconcurCollection->aggregate(
        pipeline: $sconcurPipeline,
    );

    foreach ($aggregate as $document) {
        $id = (string) $document['_id'];

        if (!$isLogProcess) {
            continue;
        }

        echo "aggregate-$item: document: $id\n";
    }
};

$benchmarker->run(
    nativeCallback: $nativeCallback,
    syncCallback: $sconcurCallback,
    asyncCallback: $sconcurCallback,
);

function makePipeline(mixed $objectId): array
{
    return [
        [
            '$match' => [
                'IIID' => $objectId,
                'bool' => true,
            ],
        ],
        [
            '$limit' => 30,
        ],
        [
            '$project' => [
                '_id' => 1,
            ],
        ],
    ];
}
