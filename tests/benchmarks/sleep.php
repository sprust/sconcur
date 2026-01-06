<?php

declare(strict_types=1);

use SConcur\Features\Sleeper\Sleeper;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'sleep',
);

$isLogProcess = $benchmarker->isLogProcess();

$sleeper = new Sleeper();

$benchmarker->run(
    syncCallback: static function () use ($sleeper, $isLogProcess) {
        $item = uniqid();

        if ($isLogProcess) {
            echo "$item: sync: start\n";
        }

        $sleeper->usleep(milliseconds: 1);

        if ($isLogProcess) {
            echo "$item: sync: finished\n";
        }
    },
    asyncCallback: static function () use ($sleeper, $isLogProcess) {
        $item = uniqid();

        if ($isLogProcess) {
            echo "$item: start\n";
        }

        $sleeper->sleep(seconds: 1);

        if ($isLogProcess) {
            echo "$item: woke first\n";
        }

        $sleeper->usleep(milliseconds: 10);

        if ($isLogProcess) {
            echo "$item: woke second\n";
        }
    }
);
