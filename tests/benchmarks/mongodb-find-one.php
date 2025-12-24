<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Features;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Tests\Impl\TestMongodbUriResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-find-one',
);

$uri = TestMongodbUriResolver::get();

echo "Mongodb URI: $uri\n";

$databaseName   = 'benchmark';
$collectionName = 'benchmark';

$connection = new ConnectionParameters(
    uri: $uri,
    database: $databaseName,
    collection: $collectionName,
);

$collection = (new MongoDB\Client($uri))->selectDatabase($databaseName)->selectCollection($collectionName);

$nativeFilter = makeFilter(
    objectId: new \MongoDB\BSON\ObjectId('6919e3d1a3673d3f4d9137a3'),
);

$sconcurFilter = makeFilter(
    objectId: new ObjectId('6919e3d1a3673d3f4d9137a3'),
);

$feature = Features::mongodb(
    connection: $connection,
);

$benchmarker->run(
    nativeCallback: static function () use ($collection, $nativeFilter) {
        return $collection->findOne($nativeFilter);
    },
    syncCallback: static function (Context $context) use ($feature, $sconcurFilter) {
        return $feature->findOne(
            context: $context,
            filter: $sconcurFilter
        );
    },
    asyncCallback: static function (Context $context) use ($feature, $sconcurFilter) {
        return $feature->findOne(
            context: $context,
            filter: $sconcurFilter
        );
    }
);

function makeFilter(mixed $objectId): array
{
    return [
        'IIID' => $objectId,
    ];
}
