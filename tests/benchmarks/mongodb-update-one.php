<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use SConcur\Tests\Impl\TestMongodbUriResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-update-one',
);

$uri = TestMongodbUriResolver::get();

echo "Mongodb URI: $uri\n";

$databaseName   = 'benchmark';
$collectionName = 'benchmark';

$driverCollection  = new MongoDB\Client($uri)->selectDatabase($databaseName)->selectCollection($collectionName);
$sconcurCollection = new Client($uri)->selectDatabase($databaseName)->selectCollection($collectionName);

$driverData = makeDocument(
    objectId: new \MongoDB\BSON\ObjectId('6919e3d1a3673d3f4d9137a3'),
    dateTime: new \MongoDB\BSON\UTCDateTime()
);

$sconcurDate = makeDocument(
    objectId: new ObjectId('6919e3d1a3673d3f4d9137a3'),
    dateTime: new UTCDateTime()
);

$benchmarker->run(
    nativeCallback: static function () use ($driverCollection, $driverData) {
        return $driverCollection
            ->updateOne(
                filter: $driverData['filter'],
                update: $driverData['update'],
                options: $driverData['options'],
            )
            ->getModifiedCount();
    },
    syncCallback: static function (Context $context) use ($sconcurCollection, $sconcurDate) {
        return $sconcurCollection->updateOne(
            context: $context,
            filter: $sconcurDate['filter'],
            update: $sconcurDate['update'],
            options: $sconcurDate['options'],
        )->modifiedCount;
    },
    asyncCallback: static function (Context $context) use ($sconcurCollection, $sconcurDate) {
        return $sconcurCollection->updateOne(
            context: $context,
            filter: $sconcurDate['filter'],
            update: $sconcurDate['update'],
            options: $sconcurDate['options'],
        )->modifiedCount;
    }
);

/**
 * @return array{filter: array, update: array, options: array}
 */
function makeDocument(mixed $objectId, mixed $dateTime): array
{
    return [
        'filter' => [
            'IIID' => $objectId,
        ],
        'update' => [
            '$set' => [
                'date' => $dateTime,
            ],
        ],
        'options' => [
            'upsert' => true,
        ],
    ];
}
