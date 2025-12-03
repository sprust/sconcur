<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Sleep\SleepFeature;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'sleep',
    total: (int) ($_SERVER['argv'][1] ?? 5),
    timeout: (int) ($_SERVER['argv'][2] ?? 2),
    limitCount: (int) ($_SERVER['argv'][3] ?? 0),
);

$benchmarker->run(
    syncCallback: static function (Context $context) {
        $item = uniqid();

        echo "$item: sync: start\n";

        SleepFeature::usleep(context: $context, milliseconds: 1);

        echo "$item: sync: finished\n";
    },
    asyncCallback: static function (Context $context) {
        $item = uniqid();

        echo "$item: start\n";

        SleepFeature::sleep(context: $context, seconds: 1);

        echo "$item: woke first\n";

        SleepFeature::usleep(context: $context, milliseconds: 10);

        echo "$item: woke second\n";
    }
);
