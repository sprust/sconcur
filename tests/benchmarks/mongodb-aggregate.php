<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\MongodbFeature;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Tests\Impl\TestMongodbUriResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-aggregate',
    total: (int) ($_SERVER['argv'][1] ?? 5),
    timeout: (int) ($_SERVER['argv'][2] ?? 2),
    limitCount: (int) ($_SERVER['argv'][3] ?? 0),
);

$uri = TestMongodbUriResolver::get();

echo "Mongodb URI: $uri\n\n";

$databaseName = 'test';
$collectionName = 'test';

$connection = new ConnectionParameters(
    uri: $uri,
    database: $databaseName,
    collection: $collectionName,
);

$callback = static function (Context $context) use ($connection) {
    $item = uniqid();

    $aggregate = MongodbFeature::aggregate(
        context: $context,
        connection: $connection,
        pipeline: [
            [
                '$match' => [
                    'bool' => true,
                ],
            ],
            [
                '$limit' => 30,
            ],
        ]
    );

    foreach ($aggregate as $doc) {
        $id = $doc['_id']->id;

        echo "aggregate-$item: document: $id\n";
    }
};

$benchmarker->run(
    syncCallback: $callback,
    asyncCallback: $callback
);
