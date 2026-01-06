<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-update-one',
);

$driverCollection  = TestMongodbResolver::getDriverBenchmarkCollection();
$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

$driverData = makeDocument(
    objectId: TestMongodbResolver::getDriverObjectId(),
    dateTime: TestMongodbResolver::getDriverDateTime(),
);

$sconcurDate = makeDocument(
    objectId: TestMongodbResolver::getSconcurObjectId(),
    dateTime: TestMongodbResolver::getSconcurDateTime(),
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
            upsert: $sconcurDate['upsert'] ?? false,
        )->modifiedCount;
    },
    asyncCallback: static function (Context $context) use ($sconcurCollection, $sconcurDate) {
        return $sconcurCollection->updateOne(
            context: $context,
            filter: $sconcurDate['filter'],
            update: $sconcurDate['update'],
            upsert: $sconcurDate['upsert'] ?? false,
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
