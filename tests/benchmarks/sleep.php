<?php

declare(strict_types=1);

use SConcur\Entities\Context;
use SConcur\Features\Features;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'sleep',
);

$isLogProcess = $benchmarker->isLogProcess();

$feature = Features::sleep();

$benchmarker->run(
    syncCallback: static function (Context $context) use ($feature, $isLogProcess) {
        $item = uniqid();

        if ($isLogProcess) {
            echo "$item: sync: start\n";
        }

        $feature->usleep(context: $context, milliseconds: 1);

        if ($isLogProcess) {
            echo "$item: sync: finished\n";
        }
    },
    asyncCallback: static function (Context $context) use ($feature, $isLogProcess) {
        $item = uniqid();

        if ($isLogProcess) {
            echo "$item: start\n";
        }

        $feature->sleep(context: $context, seconds: 1);

        if ($isLogProcess) {
            echo "$item: woke first\n";
        }

        $feature->usleep(context: $context, milliseconds: 10);

        if ($isLogProcess) {
            echo "$item: woke second\n";
        }
    }
);
