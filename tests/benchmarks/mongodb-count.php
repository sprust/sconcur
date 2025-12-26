<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use SConcur\Tests\Impl\TestMongodbUriResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-count',
);

$uri = TestMongodbUriResolver::get();

echo "Mongodb URI: $uri\n";

$databaseName   = 'benchmark';
$collectionName = 'benchmark';

$driverCollection  = new MongoDB\Client($uri)->selectDatabase($databaseName)->selectCollection($collectionName);
$sconcurCollection = new Client($uri)->selectDatabase($databaseName)->selectCollection($collectionName);

$driverFilter = makeFilter(
    objectId: new \MongoDB\BSON\ObjectId('6919e3d1a3673d3f4d9137a3'),
    dateTime: new \MongoDB\BSON\UTCDateTime()
);

$sconcurFilter = makeFilter(
    objectId: new ObjectId('6919e3d1a3673d3f4d9137a3'),
    dateTime: new UTCDateTime()
);

$benchmarker->run(
    nativeCallback: static function () use ($driverCollection, $driverFilter) {
        return $driverCollection->countDocuments($driverFilter);
    },
    syncCallback: static function (Context $context) use ($sconcurCollection, $sconcurFilter) {
        return $sconcurCollection->countDocuments(
            context: $context,
            filter: $sconcurFilter
        );
    },
    asyncCallback: static function (Context $context) use ($sconcurCollection, $sconcurFilter) {
        return $sconcurCollection->countDocuments(
            context: $context,
            filter: $sconcurFilter
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
