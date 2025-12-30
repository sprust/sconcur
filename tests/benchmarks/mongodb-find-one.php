<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-find-one',
);

$driverCollection  = TestMongodbResolver::getDriverBenchmarkCollection();
$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

$driverFilter = makeFilter(
    objectId: new \MongoDB\BSON\ObjectId('6919e3d1a3673d3f4d9137a3'),
);

$sconcurFilter = makeFilter(
    objectId: new ObjectId('6919e3d1a3673d3f4d9137a3'),
);

$benchmarker->run(
    nativeCallback: static function () use ($driverCollection, $driverFilter) {
        return $driverCollection->findOne($driverFilter);
    },
    syncCallback: static function (Context $context) use ($sconcurCollection, $sconcurFilter) {
        return $sconcurCollection->findOne(
            context: $context,
            filter: $sconcurFilter
        );
    },
    asyncCallback: static function (Context $context) use ($sconcurCollection, $sconcurFilter) {
        return $sconcurCollection->findOne(
            context: $context,
            filter: $sconcurFilter
        );
    }
);

function makeFilter(mixed $objectId): array
{
    return [
        'IIID' => $objectId,
    ];
}
