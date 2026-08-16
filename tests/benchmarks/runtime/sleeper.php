<?php

declare(strict_types=1);

use SConcur\Features\Sleeper\Sleeper;

require_once __DIR__ . '/../lib/benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'sleeper',
);

$isLogProcess = $benchmarker->isLogProcess();

$benchmarker->run(
    syncCallback: static function () use ($isLogProcess) {
        $item = uniqid();

        if ($isLogProcess) {
            echo "$item: sync: start\n";
        }

        Sleeper::usleep(microseconds: 1000);

        if ($isLogProcess) {
            echo "$item: sync: finished\n";
        }
    },
    asyncCallback: static function () use ($isLogProcess) {
        $item = uniqid();

        if ($isLogProcess) {
            echo "$item: start\n";
        }

        Sleeper::sleep(seconds: 1);

        if ($isLogProcess) {
            echo "$item: woke first\n";
        }

        Sleeper::usleep(microseconds: 10000);

        if ($isLogProcess) {
            echo "$item: woke second\n";
        }
    }
);
