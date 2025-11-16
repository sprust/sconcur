<?php

declare(strict_types=1);

ini_set('memory_limit', '1024M');

use SConcur\Entities\Context;
use SConcur\Entities\Timer;
use SConcur\Features\Mongodb\MongodbFeature;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\SConcur;
use SConcur\Tests\Impl\TestContainer;
use SConcur\Tests\Impl\TestMongodbUriResolver;

require_once __DIR__ . '/../vendor/autoload.php';

TestContainer::resolve();

$total      = (int) ($_SERVER['argv'][1] ?? 3);
$timeout    = (int) ($_SERVER['argv'][2] ?? 2);
$limitCount = (int) ($_SERVER['argv'][3] ?? 0);

$counter = $total;

/** @var array<Closure> $callbacks */
$callbacks = [];

$uri = TestMongodbUriResolver::get();

echo "Mongodb URI: $uri\n\n";

$databaseName   = 'test';
$collectionName = 'test';

$connection = new ConnectionParameters(
    uri: $uri,
    database: $databaseName,
    collection: $collectionName,
);

while ($counter--) {
    $callbacks["agg-$counter"] = static function (Context $context) use ($counter, $connection) {
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
                    '$limit' => 40,
                ]
            ]
        );

        foreach ($aggregate as $doc) {
            $id = $doc['_id']->id;
            echo "agg-$counter: document: $id\n";
        }
    };
}

$asyncCallbacks = $callbacks;
$syncCallbacks  = $callbacks;

memory_reset_peak_usage();

$start = microtime(true);

$generator = SConcur::run(
    callbacks: $asyncCallbacks,
    timeoutSeconds: $timeout,
    limitCount: $limitCount,
);

echo "\n\n---- Async call ----\n";

foreach ($generator as $result) {
    echo "success: $result->key\n";
}

$asyncTotalTime = microtime(true) - $start;
$asyncMemPeak   = round(memory_get_peak_usage(true) / 1024 / 1024, 4);

memory_reset_peak_usage();

$start = microtime(true);

$context = (new Context())->setChecker(
    new Timer(timeoutSeconds: $timeout)
);

$keys = array_keys($syncCallbacks);

echo "\n\n---- Sync call ----\n";

foreach ($keys as $key) {
    $callback = $syncCallbacks[$key];

    unset($syncCallbacks[$key]);

    $callback($context);

    echo "success: $key\n";
}

$syncTotalTime = microtime(true) - $start;
$syncMemPeak   = round(memory_get_peak_usage(true) / 1024 / 1024, 4);

echo "\n\nTotal call:\t$total\n";
echo "Thr limit:\t$limitCount\n";
echo "Mem peak sync/async:\t$syncMemPeak/$asyncMemPeak\n";
echo "Total time sync/async:\t$syncTotalTime/$asyncTotalTime\n";
