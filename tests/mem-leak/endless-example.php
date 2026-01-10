<?php

declare(strict_types=1);

ini_set('memory_limit', '8M');

require_once __DIR__ . '/../../vendor/autoload.php';

$counter = 10000;

while ($counter--) {
    $fibers = new Fibers();

    $fiber1 = new Fiber(static function () {
        $x = Fiber::getCurrent();

        // dump('1.1');

        Fiber::suspend();

        // dump('1.2');

    });

    $fiber2 = new Fiber(static function () {
        $x = Fiber::getCurrent();

        // dump('2.1');

        Fiber::suspend();

        // dump('2.2');

    });

    $fibers->fibers[] = $fiber1;
    $fibers->fibers[] = $fiber2;

    while (count($fibers->fibers) > 0) {
        $fiber = array_shift($fibers->fibers);

        if (!$fiber->isStarted()) {
            $fiber->start();
            $fibers->fibers[] = $fiber;
            continue;
        }

        $fiber->resume();
        break;
    }

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
}

class Fibers {
    public array $fibers = [];

    public function __destruct()
    {
        // dump($this->fibers);
    }
}