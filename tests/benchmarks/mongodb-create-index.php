<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-create-index',
);

$driverCollection  = TestMongodbResolver::getDriverBenchmarkCollection();
$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

if (iterator_count($driverCollection->listIndexes()) > 0) {
    $driverCollection->dropIndexes();
}

$index = 0;

$benchmarker->run(
    nativeCallback: static function () use ($driverCollection, &$index) {
        ++$index;

        return $driverCollection->createIndex([
            uniqid("$index-native_") => 1,
            uniqid()                 => -1,
        ]);
    },
    syncCallback: static function (Context $context) use ($sconcurCollection, &$index) {
        ++$index;

        $indexName = "$index-sync";

        return $sconcurCollection->createIndex(
            context: $context,
            keys: [
                uniqid("{$indexName}_") => 1,
                uniqid()                => -1,
            ],
            name: $indexName
        );
    },
    asyncCallback: static function (Context $context) use ($sconcurCollection, &$index) {
        ++$index;

        $indexName = "$index-async";

        return $sconcurCollection->createIndex(
            context: $context,
            keys: [
                uniqid("{$indexName}_") => 1,
                uniqid()                => -1,
            ],
            name: $indexName
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
