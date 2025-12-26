<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use SConcur\Tests\Impl\TestMongodbUriResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-bulk-write',
);

$uri = TestMongodbUriResolver::get();

echo "Mongodb URI: $uri\n";

$databaseName   = 'benchmark';
$collectionName = 'benchmark';

$driverCollection  = new MongoDB\Client($uri)->selectDatabase($databaseName)->selectCollection($collectionName);
$sconcurCollection = new Client($uri)->selectDatabase($databaseName)->selectCollection($collectionName);

$driverOperations = makeOperations(
    objectId: new \MongoDB\BSON\ObjectId('6919e3d1a3673d3f4d9137a3'),
    dateTime: new \MongoDB\BSON\UTCDateTime()
);

$sconcurOperations = makeOperations(
    objectId: new ObjectId('6919e3d1a3673d3f4d9137a3'),
    dateTime: new UTCDateTime()
);

$benchmarker->run(
    nativeCallback: static function () use ($driverCollection, $driverOperations) {
        return $driverCollection->bulkWrite($driverOperations);
    },
    syncCallback: static function (Context $context) use ($sconcurCollection, $sconcurOperations) {
        return $sconcurCollection->bulkWrite(
            context: $context,
            operations: $sconcurOperations
        );
    },
    asyncCallback: static function (Context $context) use ($sconcurCollection, $sconcurOperations) {
        return $sconcurCollection->bulkWrite(
            context: $context,
            operations: $sconcurOperations
        );
    }
);

function makeOperations(mixed $objectId, mixed $dateTime): array
{
    $uniqid = uniqid();

    return [
        [
            'insertOne' => [
                [
                    'uniquid'   => $uniqid,
                    'upserted'  => false,
                    'object_id' => $objectId,
                ],
            ],
        ],
        [
            'updateOne' => [
                [
                    'uniquid'   => $uniqid,
                    'upserted'  => true,
                    'object_id' => $objectId,
                ],
                [
                    '$set' => [
                        'dtStart'     => $dateTime,
                        'dtEnd'       => $dateTime,
                        'object_id_u' => $objectId,
                    ],
                    '$setOnInsert' => [
                        'createdAt' => $dateTime,
                    ],
                ],
                [
                    'upsert' => true,
                ],
            ],
        ],
        [
            'updateMany' => [
                [
                    'uniquid'       => $uniqid,
                    'upserted_many' => false,
                    'object_id'     => $objectId,
                ],
                [
                    '$set' => [
                        'dtStart' => $dateTime,
                        'dtEnd'   => $dateTime,
                    ],
                    '$setOnInsert' => [
                        'createdAt' => $dateTime,
                    ],
                ],
            ],
        ],
        [
            'updateMany' => [
                [
                    'uniquid'       => $uniqid,
                    'upserted_many' => true,
                    'object_id'     => $objectId,
                ],
                [
                    '$set' => [
                        'dtStart' => $dateTime,
                        'dtEnd'   => $dateTime,
                    ],
                    '$setOnInsert' => [
                        'createdAt' => $dateTime,
                    ],
                ],
                [
                    'upsert' => true,
                ],
            ],
        ],
        [
            'replaceOne' => [
                [
                    'uniquid'   => $uniqid,
                    'upserted'  => false,
                    'object_id' => $objectId,
                ],
                [
                    'uniquid'  => $uniqid . '-upd',
                    'upserted' => true,
                ],
            ],
        ],
        [
            'replaceOne' => [
                [
                    'uniquid'   => $uniqid,
                    'upserted'  => true,
                    'object_id' => $objectId,
                ],
                [
                    'uniquid'  => $uniqid . '-upd',
                    'upserted' => true,
                ],
                [
                    'upsert' => true,
                ],
            ],
        ],
        [
            'deleteOne' => [
                [
                    'uniquid'   => $uniqid,
                    'object_id' => $objectId,
                ],
            ],
        ],
        [
            'deleteMany' => [
                [
                    'uniquid'   => $uniqid,
                    'object_id' => $objectId,
                ],
            ],
        ],
    ];
}
