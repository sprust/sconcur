<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Features;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use SConcur\Tests\Impl\TestMongodbUriResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-create-indexes',
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

$collection->dropIndexes();

$sconcurFilter = makeFilter(
    objectId: new ObjectId('6919e3d1a3673d3f4d9137a3'),
    dateTime: new UTCDateTime()
);

$feature = Features::mongodb(
    connection: $connection,
);

$benchmarker->run(
    nativeCallback: static function () use ($collection) {
        return $collection->createIndexes([['key' => [uniqid('native_') => 1]]]);
    },
    syncCallback: static function (Context $context) use ($feature) {
        return $feature->createIndexes(
            context: $context,
            indexes: [[uniqid('sync_') => 1]]
        );
    },
    asyncCallback: static function (Context $context) use ($feature) {
        return $feature->createIndexes(
            context: $context,
            indexes: [[uniqid('async_') => 1]]
        );
    }
);

function makeFilter(mixed $objectId, mixed $dateTime): array
{
    return [
        'IIID' => $objectId,
        'date' => ['$lt' => $dateTime],
    ];
}
