<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-insert-one',
);

$driverCollection  = TestMongodbResolver::getDriverBenchmarkCollection();
$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

$nativeDocument = makeDocument(
    objectId: TestMongodbResolver::getDriverObjectId(),
    dateTime: TestMongodbResolver::getDriverDateTime(),
);

$sconcurDocument = makeDocument(
    objectId: TestMongodbResolver::getSconcurObjectId(),
    dateTime: TestMongodbResolver::getSconcurDateTime(),
);

$benchmarker->run(
    nativeCallback: static function () use ($driverCollection, $nativeDocument) {
        return $driverCollection->insertOne($nativeDocument)->getInsertedId();
    },
    syncCallback: static function () use ($sconcurCollection, $sconcurDocument) {
        return $sconcurCollection->insertOne(
        document: $sconcurDocument
        )->insertedId;
    },
    asyncCallback: static function () use ($sconcurCollection, $sconcurDocument) {
        return $sconcurCollection->insertOne(
        document: $sconcurDocument
        )->insertedId;
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
