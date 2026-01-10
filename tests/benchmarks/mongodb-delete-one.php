<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-delete-one',
);

$driverCollection  = TestMongodbResolver::getDriverBenchmarkCollection();
$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

$driverFilter = makeFilter(
    objectId: TestMongodbResolver::getDriverObjectId(),
);

$sconcurFilter = makeFilter(
    objectId: TestMongodbResolver::getSconcurObjectId(),
);

$benchmarker->run(
    nativeCallback: static function () use ($driverCollection, $driverFilter) {
        return $driverCollection
            ->deleteOne(
                filter: $driverFilter,
            )
            ->getDeletedCount();
    },
    syncCallback: static function () use ($sconcurCollection, $sconcurFilter) {
        return $sconcurCollection->deleteOne(
        filter: $sconcurFilter,
        )->deletedCount;
    },
    asyncCallback: static function () use ($sconcurCollection, $sconcurFilter) {
        return $sconcurCollection->deleteOne(
        filter: $sconcurFilter,
        )->deletedCount;
    }
);

function makeFilter(mixed $objectId): array
{
    return [
        'IIID' => $objectId,
    ];
}
