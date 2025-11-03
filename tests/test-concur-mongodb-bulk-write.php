<?php

declare(strict_types=1);

use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use SConcur\Entities\Context;
use SConcur\Features\Mongodb\MongodbFeature;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\SConcur;
use SConcur\Tests\Impl\TestContainer;
use SConcur\Tests\Impl\TestMongodbUriResolver;

require_once __DIR__ . '/../vendor/autoload.php';

TestContainer::resolve();

$total      = (int) ($_SERVER['argv'][1] ?? 5);
$limitCount = (int) ($_SERVER['argv'][2] ?? 0);

$counter = $total;

/** @var array<Closure> $callbacks */
$callbacks = [];

$start = microtime(true);

$uri = TestMongodbUriResolver::get();

echo "Mongodb URI: $uri\n\n";

$databaseName   = 'test';
$collectionName = 'test';

$driverCollection = (new Client($uri))->selectCollection(
    databaseName: $databaseName,
    collectionName: $collectionName
);

$connection = new ConnectionParameters(
    uri: $uri,
    database: $databaseName,
    collection: $collectionName,
);

while ($counter--) {
    $date = new UTCDateTime();

    $callbacks["bw-$counter"] = static fn(Context $context) => MongodbFeature::bulkWrite(
        context: $context,
        collection: $driverCollection,
        connection: $connection,
        operations: [
            [
                'updateOne' => [
                    [
                        'uniquid'  => uniqid((string) $counter),
                        'upserted' => false,
                    ],
                    [
                        '$set'         => [
                            'dtStart' => $date,
                            'dtEnd'   => $date,
                        ],
                        '$setOnInsert' => [
                            'createdAt' => $date,
                        ],
                    ],
                ],
            ],
            [
                'updateOne' => [
                    [
                        'uniquid'  => uniqid((string) $counter),
                        'upserted' => true,
                    ],
                    [
                        '$set'         => [
                            'dtStart' => $date,
                            'dtEnd'   => $date,
                        ],
                        '$setOnInsert' => [
                            'createdAt' => $date,
                        ],
                    ],
                    [
                        'upsert' => true,
                    ],
                ],
            ],
            [
                'updateMany' => [
                    [
                        'uniquid'       => uniqid((string) $counter),
                        'upserted_many' => false,
                    ],
                    [
                        '$set'         => [
                            'dtStart' => $date,
                            'dtEnd'   => $date,
                        ],
                        '$setOnInsert' => [
                            'createdAt' => $date,
                        ],
                    ],
                ],
            ],
            [
                'updateMany' => [
                    [
                        'uniquid'       => uniqid((string) $counter),
                        'upserted_many' => true,
                    ],
                    [
                        '$set'         => [
                            'dtStart' => $date,
                            'dtEnd'   => $date,
                        ],
                        '$setOnInsert' => [
                            'createdAt' => $date,
                        ],
                    ],
                    [
                        'upsert' => true,
                    ],
                ],
            ],
            [
                'deleteOne' => [
                    [
                        'uniquid' => uniqid((string) $counter),
                    ],
                ],
            ],
            [
                'deleteMany' => [
                    [
                        'uniquid' => uniqid((string) $counter),
                    ],
                ],
            ],
            [
                'replaceOne' => [
                    [
                        'uniquid'  => uniqid((string) $counter),
                        'upserted' => false,
                    ],
                    [
                        'uniquid'  => uniqid((string) $counter) . '-upd',
                        'upserted' => true,
                    ],
                ],
            ],
            [
                'replaceOne' => [
                    [
                        'uniquid'  => uniqid((string) $counter),
                        'upserted' => true,
                    ],
                    [
                        'uniquid'  => uniqid((string) $counter) . '-upd',
                        'upserted' => true,
                    ],
                    [
                        'upsert' => true,
                    ],
                ],
            ],
        ]
    );
}

foreach (SConcur::run($callbacks, $limitCount) as $key => $result) {
    echo "success:\n";
    print_r($result->result);
}

$totalTime = microtime(true) - $start;
$memPeak   = round(memory_get_peak_usage(true) / 1024 / 1024, 4);

echo "\n\nTotal call:\t$total\n";
echo "Thr limit:\t$limitCount\n";
echo "Mem peak:\t$memPeak\n";
echo "Total time:\t$totalTime\n";
