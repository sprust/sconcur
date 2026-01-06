<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestApplication;
use SConcur\Tests\Impl\TestMongodbResolver;
use SConcur\WaitGroup;

// TODO: memory leak

ini_set('memory_limit', '8M');

require_once __DIR__ . '/../../vendor/autoload.php';
TestApplication::init();

$sconcurCollection = TestMongodbResolver::getSconcurBenchmarkCollection();

$sconcurCollection->insertMany(
    array_map(
        static fn() => ['uniq' => uniqid()],
        range(1, 100)
    )
);

$sconcurCallback = static function () use ($sconcurCollection) {
    $aggregate = $sconcurCollection->aggregate(
        pipeline: [
            [
                '$limit' => 100,
            ]
        ],
        batchSize: 33
    );

    $mem     = str_pad((string) round(memory_get_usage() / 1024 / 1024, 6), 10);
    $memReal = str_pad((string) round(memory_get_usage(true) / 1024 / 1024, 6), 10);
    $memPeak = str_pad((string) round(memory_get_peak_usage() / 1024 / 1024, 6), 10);

    echo sprintf(
        "mem: \t%s\t\tmem(real): \t%s\tmem(peak): \t%s\n",
        $mem,
        $memReal,
        $memPeak,
    );

    return iterator_count($aggregate);

};

$waitGroup = WaitGroup::create();

foreach (range(1, 10) as $item) {
    $waitGroup->add(callback: $sconcurCallback);
}

$generator = $waitGroup->iterate();

$count = 0;

foreach ($generator as $ignored) {
    $waitGroup->add(callback: $sconcurCallback);
}
