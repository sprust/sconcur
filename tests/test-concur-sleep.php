<?php

declare(strict_types=1);

ini_set('memory_limit', '1024M');

use SConcur\Entities\Context;
use SConcur\Features\Sleep\SleepFeature;
use SConcur\SConcur;
use SConcur\Tests\Impl\TestContainer;

require_once __DIR__ . '/../vendor/autoload.php';

TestContainer::resolve();

$start = microtime(true);

$callbacks = [];

$total      = (int) ($_SERVER['argv'][1] ?? 5);
$seconds    = (int) ($_SERVER['argv'][2] ?? 1);
$timeout    = (int) ($_SERVER['argv'][3] ?? 2);
$limitCount = (int) ($_SERVER['argv'][4] ?? 0);

echo "Total call:\t$total\n";
echo "Seconds:\t$seconds\n";
echo "Timeout:\t$timeout\n";
echo "Limit:\t$limitCount\n";
echo "\n";

foreach (range(1, $total) as $item) {
    echo "item: $item\n";

    $callbacks[$item] = static function (Context $context) use ($item, $seconds) {
        echo "$item: start\n";

        SleepFeature::sleep(context: $context, seconds: $seconds);

        echo "$item: woke first\n";

        SleepFeature::usleep(context: $context, microseconds: 100);

        echo "$item: woke second\n";
    };
}

$generator = SConcur::run(
    callbacks: $callbacks,
    timeoutSeconds: $timeout,
    limitCount: $limitCount,
);

foreach ($generator as $result) {
    echo "success: $result->key\n";
}

$totalTime = microtime(true) - $start;
$memPeak   = round(memory_get_peak_usage(true) / 1024 / 1024, 4);

echo "Mem peak:\t$memPeak\n";
echo "Total time:\t$totalTime\n";
