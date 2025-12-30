<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-aggregate',
);

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

    foreach ($aggregate as $doc) {
        $id = $doc['_id'];

        if (!$isLogProcess) {
            continue;
        }

        echo "aggregate-$item: document: $id\n";
    }
};

$sconcurCallback = static function (Context $context) use ($sconcurCollection, $sconcurPipeline, $isLogProcess) {
    $item = uniqid();

    $aggregate = $sconcurCollection->aggregate(
        context: $context,
        pipeline: $sconcurPipeline
    );

    foreach ($aggregate as $doc) {
        $id = $doc['_id']->id;

        if (!$isLogProcess) {
            continue;
        }

        echo "aggregate-$item: document: $id\n";
    }
};

$benchmarker->run(
    nativeCallback: $nativeCallback,
    syncCallback: $sconcurCallback,
    asyncCallback: $sconcurCallback
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
    ];
}
