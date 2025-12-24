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
    name: 'mongodb-create-index',
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

$index = 0;

$benchmarker->run(
    nativeCallback: static function () use ($collection, &$index) {
        ++$index;

        return $collection->createIndex([
            uniqid("$index-native_") => 1,
            uniqid()          => -1,
        ]);
    },
    syncCallback: static function (Context $context) use ($feature, &$index) {
        ++$index;

        return $feature->createIndex(
            context: $context,
            keys: [
                uniqid("$index-sync_") => 1,
                uniqid()        => -1,
            ]
        );
    },
    asyncCallback: static function (Context $context) use ($feature, &$index) {
        ++$index;

        return $feature->createIndex(
            context: $context,
            keys: [
                uniqid("$index-async_") => 1,
                uniqid()         => -1,
            ]
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
