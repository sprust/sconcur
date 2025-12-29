<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Tests\Impl\TestMongodbUriResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-delete-one',
);

$uri = TestMongodbUriResolver::get();

echo "Mongodb URI: $uri\n";

$databaseName   = 'benchmark';
$collectionName = 'benchmark';

$driverCollection  = new MongoDB\Client($uri)->selectDatabase($databaseName)->selectCollection($collectionName);
$sconcurCollection = new Client($uri)->selectDatabase($databaseName)->selectCollection($collectionName);

$driverData = makeDocument(
    objectId: new \MongoDB\BSON\ObjectId('6919e3d1a3673d3f4d9137a3'),
);

$sconcurDate = makeDocument(
    objectId: new ObjectId('6919e3d1a3673d3f4d9137a3'),
);

$benchmarker->run(
    nativeCallback: static function () use ($driverCollection, $driverData) {
        return $driverCollection
            ->deleteOne(
                filter: $driverData['filter'],
            )
            ->getDeletedCount();
    },
    syncCallback: static function (Context $context) use ($sconcurCollection, $sconcurDate) {
        return $sconcurCollection->deleteOne(
            context: $context,
            filter: $sconcurDate['filter'],
        )->deletedCount;
    },
    asyncCallback: static function (Context $context) use ($sconcurCollection, $sconcurDate) {
        return $sconcurCollection->deleteOne(
            context: $context,
            filter: $sconcurDate['filter'],
        )->deletedCount;
    }
);

/**
 * @return array{filter: array, update: array, options: array}
 */
function makeDocument(mixed $objectId): array
{
    return [
        'filter' => [
            'IIID' => $objectId,
        ],
    ];
}
