<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Features;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'sleep',
);

$feature = Features::sleep();

$benchmarker->run(
    syncCallback: static function (Context $context) use ($feature) {
        $item = uniqid();

        echo "$item: sync: start\n";

        $feature->usleep(context: $context, milliseconds: 1);

        echo "$item: sync: finished\n";
    },
    asyncCallback: static function (Context $context) use ($feature) {
        $item = uniqid();

        echo "$item: start\n";

        $feature->sleep(context: $context, seconds: 1);

        echo "$item: woke first\n";

        $feature->usleep(context: $context, milliseconds: 10);

        echo "$item: woke second\n";
    }
);
