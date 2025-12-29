<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Connection\Client;
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

$driverCollection  = new MongoDB\Client($uri)->selectDatabase($databaseName)->selectCollection($collectionName);
$sconcurCollection = new Client($uri)->selectDatabase($databaseName)->selectCollection($collectionName);

if (iterator_count($driverCollection->listIndexes()) > 0) {
    $driverCollection->dropIndexes();
}

$sconcurFilter = makeFilter(
    objectId: new ObjectId('6919e3d1a3673d3f4d9137a3'),
    dateTime: new UTCDateTime()
);

$index = 0;

$benchmarker->run(
    nativeCallback: static function () use ($driverCollection, &$index) {
        ++$index;

        return $driverCollection->createIndex([
            uniqid("$index-native_") => 1,
            uniqid()                 => -1,
        ]);
    },
    syncCallback: static function (Context $context) use ($sconcurCollection, &$index) {
        ++$index;

        $indexName = "$index-sync";

        return $sconcurCollection->createIndex(
            context: $context,
            keys: [
                uniqid("{$indexName}_") => 1,
                uniqid()                => -1,
            ],
            name: $indexName
        );
    },
    asyncCallback: static function (Context $context) use ($sconcurCollection, &$index) {
        ++$index;

        $indexName = "$index-async";

        return $sconcurCollection->createIndex(
            context: $context,
            keys: [
                uniqid("{$indexName}_") => 1,
                uniqid()                => -1,
            ],
            name: $indexName
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
