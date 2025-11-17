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
    total: (int) ($_SERVER['argv'][1] ?? 5),
    timeout: (int) ($_SERVER['argv'][2] ?? 2),
    limitCount: (int) ($_SERVER['argv'][3] ?? 0),
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

$callback = static function (Context $context) use ($connection) {
    return MongodbFeature::insertOne(
        context: $context,
        connection: $connection,
        document: [
            'IIID'      => new ObjectId('6919e3d1a3673d3f4d9137a3'),
            'uniq'      => uniqid(),
            'bool'      => true,
            'date'      => new UTCDateTime(),
            'dates'     => [
                new UTCDateTime(),
                new UTCDateTime(),
                'dates'     => [
                    new UTCDateTime(),
                    new UTCDateTime(),
                ],
                'dates_ass' => [
                    'one' => new UTCDateTime(),
                    'two' => new UTCDateTime(),
                ],
            ],
            'dates_ass' => [
                'one'       => new UTCDateTime(),
                'two'       => new UTCDateTime(),
                'dates'     => [
                    new UTCDateTime(),
                    new UTCDateTime(),
                ],
                'dates_ass' => [
                    'one' => new UTCDateTime(),
                    'two' => new UTCDateTime(),
                ],
            ],
        ]
    )->getInsertedId();
};

$benchmarker->run(
    syncCallback: $callback,
    asyncCallback: $callback
);
