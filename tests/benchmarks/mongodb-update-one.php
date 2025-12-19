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
    name: 'mongodb-update-one',
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

$nativeData = makeDocument(
    objectId: new \MongoDB\BSON\ObjectId('6919e3d1a3673d3f4d9137a3'),
    dateTime: new \MongoDB\BSON\UTCDateTime()
);

$sconcurDate = makeDocument(
    objectId: new ObjectId('6919e3d1a3673d3f4d9137a3'),
    dateTime: new UTCDateTime()
);

$feature = Features::mongodb(
    connection: $connection,
);

$benchmarker->run(
    nativeCallback: static function () use ($collection, $nativeData) {
        return $collection
            ->updateOne(
                filter: $nativeData['filter'],
                update: $nativeData['update'],
                options: $nativeData['options'],
            )
            ->getModifiedCount();
    },
    syncCallback: static function (Context $context) use ($feature, $sconcurDate) {
        return $feature->updateOne(
            context: $context,
            filter: $sconcurDate['filter'],
            update: $sconcurDate['update'],
            options: $sconcurDate['options'],
        )->modifiedCount;
    },
    asyncCallback: static function (Context $context) use ($feature, $sconcurDate) {
        return $feature->updateOne(
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
