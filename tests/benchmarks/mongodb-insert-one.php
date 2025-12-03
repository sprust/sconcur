<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\MongodbFeature;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use SConcur\Tests\Impl\TestMongodbUriResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-insert-one',
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

$nativeDocument = makeDocument(
    objectId: new \MongoDB\BSON\ObjectId('6919e3d1a3673d3f4d9137a3'),
    dateTime: new \MongoDB\BSON\UTCDateTime()
);

$sconcurDocument = makeDocument(
    objectId: new ObjectId('6919e3d1a3673d3f4d9137a3'),
    dateTime: new UTCDateTime()
);

$feature = new MongodbFeature(
    connection: $connection,
);

$benchmarker->run(
    nativeCallback: static function () use ($collection, $nativeDocument) {
        return $collection->insertOne($nativeDocument)->getInsertedId();
    },
    syncCallback: static function (Context $context) use ($feature, $sconcurDocument) {
        return $feature->insertOne(
            context: $context,
            document: $sconcurDocument
        )->getInsertedId();
    },
    asyncCallback: static function (Context $context) use ($feature, $sconcurDocument) {
        return $feature->insertOne(
            context: $context,
            document: $sconcurDocument
        )->getInsertedId();
    }
);

function makeDocument(mixed $objectId, mixed $dateTime): array
{
    return [
        'IIID'      => $objectId,
        'uniq'      => uniqid(),
        'bool'      => true,
        'date'      => $dateTime,
        'dates'     => [
            $dateTime,
            $dateTime,
            'dates'     => [
                $dateTime,
                $dateTime,
            ],
            'dates_ass' => [
                'one' => $dateTime,
                'two' => $dateTime,
            ],
        ],
        'dates_ass' => [
            'one'       => $dateTime,
            'two'       => $dateTime,
            'dates'     => [
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
