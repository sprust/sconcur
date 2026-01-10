<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-bulk-write',
);

$driverCollection  = TestMongodbResolver::getDriverBenchmarkCollection();
$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

$driverOperations = makeOperations(
    objectId: TestMongodbResolver::getDriverObjectId(),
    dateTime: TestMongodbResolver::getDriverDateTime(),
);

$sconcurOperations = makeOperations(
    objectId: TestMongodbResolver::getSconcurObjectId(),
    dateTime: TestMongodbResolver::getSconcurDateTime(),
);

$benchmarker->run(
    nativeCallback: static function () use ($driverCollection, $driverOperations) {
        return $driverCollection->bulkWrite($driverOperations);
    },
    syncCallback: static function () use ($sconcurCollection, $sconcurOperations) {
        return $sconcurCollection->bulkWrite(
            operations: $sconcurOperations
        );
    },
    asyncCallback: static function () use ($sconcurCollection, $sconcurOperations) {
        return $sconcurCollection->bulkWrite(
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
