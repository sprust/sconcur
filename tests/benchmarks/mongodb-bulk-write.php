<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\MongodbFeature;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Tests\Impl\TestMongodbUriResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-bulk-write',
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
    $date = new DateTime();

    $item = uniqid();

    return MongodbFeature::bulkWrite(
        context: $context,
        connection: $connection,
        operations: [
            [
                'updateOne' => [
                    [
                        'uniquid'  => uniqid($item),
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
                        'uniquid'  => uniqid($item),
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
                        'uniquid'       => uniqid($item),
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
                        'uniquid'       => uniqid($item),
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
                        'uniquid' => uniqid($item),
                    ],
                ],
            ],
            [
                'deleteMany' => [
                    [
                        'uniquid' => uniqid($item),
                    ],
                ],
            ],
            [
                'replaceOne' => [
                    [
                        'uniquid'  => uniqid($item),
                        'upserted' => false,
                    ],
                    [
                        'uniquid'  => uniqid($item) . '-upd',
                        'upserted' => true,
                    ],
                ],
            ],
            [
                'replaceOne' => [
                    [
                        'uniquid'  => uniqid($item),
                        'upserted' => true,
                    ],
                    [
                        'uniquid'  => uniqid($item) . '-upd',
                        'upserted' => true,
                    ],
                    [
                        'upsert' => true,
                    ],
                ],
            ],
        ]
    );
};

$benchmarker->run(
    syncCallback: $callback,
    asyncCallback: $callback
);
