<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-count',
);

$driverCollection  = TestMongodbResolver::getDriverBenchmarkCollection();
$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

$driverFilter = makeFilter(
    objectId: TestMongodbResolver::getDriverObjectId(),
    dateTime: TestMongodbResolver::getDriverDateTime(),
);

$sconcurFilter = makeFilter(
    objectId: TestMongodbResolver::getSconcurObjectId(),
    dateTime: TestMongodbResolver::getSconcurDateTime(),
);

$benchmarker->run(
    nativeCallback: static function () use ($driverCollection, $driverFilter) {
        return $driverCollection->countDocuments($driverFilter);
    },
    syncCallback: static function () use ($sconcurCollection, $sconcurFilter) {
        return $sconcurCollection->countDocuments(
        filter: $sconcurFilter
        );
    },
    asyncCallback: static function () use ($sconcurCollection, $sconcurFilter) {
        return $sconcurCollection->countDocuments(
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
