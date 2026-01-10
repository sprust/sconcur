<?php

declare(strict_types=1);

use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Impl\TestApplication;
use SConcur\WaitGroup;

ini_set('memory_limit', '8M');

require_once __DIR__ . '/../../vendor/autoload.php';
TestApplication::init();

$sconcurCallback = static function () {
    $sleeper = new Sleeper();

    $sleeper->usleep(1);
    $sleeper->usleep(1);

    $mem     = str_pad((string) round(memory_get_usage() / 1024 / 1024, 6), 10);
    $memReal = str_pad((string) round(memory_get_usage(true) / 1024 / 1024, 6), 10);
    $memPeak = str_pad((string) round(memory_get_peak_usage() / 1024 / 1024, 6), 10);

    $time = new DateTime()->format('Y-m-d H:i:s.u');

    echo sprintf(
        "$time \t mem: \t%s\t\tmem(real): \t%s\tmem(peak): \t%s\n",
        $mem,
        $memReal,
        $memPeak,
    );
};

while (true) {
    $waitGroup = WaitGroup::create();

    foreach (range(1, 10) as $item) {
        $waitGroup->add(callback: $sconcurCallback);
    }

    $generator = $waitGroup->iterate();

    foreach ($generator as $ignored) {
        break;
    }
}
