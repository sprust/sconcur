<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\MongodbFeature;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use SConcur\SConcur;
use SConcur\Tests\Impl\TestMongodbUriResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-bulk-write',
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

$nativeOperations = makeOperations(
    objectId: new \MongoDB\BSON\ObjectId('6919e3d1a3673d3f4d9137a3'),
    dateTime: new \MongoDB\BSON\UTCDateTime()
);

$sconcurOperations = makeOperations(
    objectId: new ObjectId('6919e3d1a3673d3f4d9137a3'),
    dateTime: new UTCDateTime()
);

$feature = SConcur::features()->mongodb(
    connection: $connection,
);

$benchmarker->run(
    nativeCallback: static function () use ($collection, $nativeOperations) {
        return $collection->bulkWrite($nativeOperations);
    },
    syncCallback: static function (Context $context) use ($feature, $sconcurOperations) {
        return $feature->bulkWrite(
            context: $context,
            operations: $sconcurOperations
        );
    },
    asyncCallback: static function (Context $context) use ($feature, $sconcurOperations) {
        return $feature->bulkWrite(
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
                    'uniquid'  => $uniqid,
                    'upserted' => false,
                ],
                [
                    '$set'         => [
                        'objectId' => $objectId,
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
            'updateOne' => [
                [
                    'uniquid'  => $uniqid,
                    'upserted' => true,
                ],
                [
                    '$set'         => [
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
            'updateMany' => [
                [
                    'uniquid'       => $uniqid,
                    'upserted_many' => false,
                ],
                [
                    '$set'         => [
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
                ],
                [
                    '$set'         => [
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
            'deleteOne' => [
                [
                    'uniquid' => $uniqid,
                ],
            ],
        ],
        [
            'deleteMany' => [
                [
                    'uniquid' => $uniqid,
                ],
            ],
        ],
        [
            'replaceOne' => [
                [
                    'uniquid'  => $uniqid,
                    'upserted' => false,
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
                    'uniquid'  => $uniqid,
                    'upserted' => true,
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
    ];
}
