<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-insert-many',
);

$driverCollection  = TestMongodbResolver::getDriverBenchmarkCollection();
$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

$driverDocuments = array_map(
    static fn() => makeDocument(
        objectId: TestMongodbResolver::getDriverObjectId(),
        dateTime: TestMongodbResolver::getDriverDateTime(),
    ),
    range(1, 30)
);

$sconcurDocuments = array_map(
    static fn() => makeDocument(
        objectId: TestMongodbResolver::getSconcurObjectId(),
        dateTime: TestMongodbResolver::getSconcurDateTime(),
    ),
    range(1, 30)
);

$benchmarker->run(
    nativeCallback: static function () use ($driverCollection, $driverDocuments) {
        return $driverCollection->insertMany($driverDocuments)->getInsertedCount();
    },
    syncCallback: static function () use ($sconcurCollection, $sconcurDocuments) {
        return $sconcurCollection->insertMany(
        documents: $sconcurDocuments
        )->insertedCount;
    },
    asyncCallback: static function () use ($sconcurCollection, $sconcurDocuments) {
        return $sconcurCollection->insertMany(
        documents: $sconcurDocuments
        )->insertedCount;
    }
);

function makeDocument(mixed $objectId, mixed $dateTime): array
{
    return [
        'IIID'  => $objectId,
        'uniq'  => uniqid(),
        'bool'  => true,
        'date'  => $dateTime,
        'dates' => [
            $dateTime,
            $dateTime,
            'dates' => [
                $dateTime,
                $dateTime,
            ],
            'dates_ass' => [
                'one' => $dateTime,
                'two' => $dateTime,
            ],
        ],
        'dates_ass' => [
            'one'   => $dateTime,
            'two'   => $dateTime,
            'dates' => [
                $dateTime,
                $dateTime,
            ],
            'dates_ass' => [
                'one' => $dateTime,
                'two' => $dateTime,
            ],
        ],
    ];
}
