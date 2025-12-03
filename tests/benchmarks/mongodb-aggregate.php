<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\MongodbFeature;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Tests\Impl\TestMongodbUriResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-aggregate',
);

$uri = TestMongodbUriResolver::get();

echo "Mongodb URI: $uri\n\n";

$databaseName   = 'test';
$collectionName = 'test';

$connection = new ConnectionParameters(
    uri: $uri,
    database: $databaseName,
    collection: $collectionName,
);

$collection = (new MongoDB\Client($uri))->selectDatabase($databaseName)->selectCollection($collectionName);

$nativePipeline = makePipeline(
    new \MongoDB\BSON\ObjectId('6919e3d1a3673d3f4d9137a3')
);

$sconcurPipeline = makePipeline(
    new ObjectId('6919e3d1a3673d3f4d9137a3')
);

$nativeCallback = static function () use ($collection, $nativePipeline) {
    $item = uniqid();

    $aggregate = $collection->aggregate($nativePipeline);

    foreach ($aggregate as $doc) {
        $id = $doc['_id'];

        echo "aggregate-$item: document: $id\n";
    }
};

$feature = new MongodbFeature(
    connection: $connection,
);

$sconcurCallback = static function (Context $context) use ($feature, $sconcurPipeline) {
    $item = uniqid();

    $aggregate = $feature->aggregate(
        context: $context,
        pipeline: $sconcurPipeline
    );

    foreach ($aggregate as $doc) {
        $id = $doc['_id']->id;

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
            '$limit' => 100,
        ],
    ];
}
