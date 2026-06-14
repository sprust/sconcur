<?php

declare(strict_types=1);

// I/O-bound benchmark: 100 concurrent GET /msleep/1000 (each handler sleeps 1s
// asynchronously, yielding to the scheduler). Run against 1 server vs 10
// SO_REUSEPORT servers. For I/O work the count makes no difference — a single
// cooperative process already overlaps all the sleeps — but the comparison
// mirrors the CPU-bound benchmark.

require __DIR__ . '/_http_bench.php';

$host     = '127.0.0.1';
$requests = 100;

$run = static function (int $workers, int $port) use ($host, $requests): void {
    $procs = benchSpawnServers($host, $port, $workers, reusePort: $workers > 1);
    $alive = benchAliveCount($procs);

    [$elapsed, $ok] = benchFireConcurrent("http://$host:$port/msleep/1000", $requests);

    benchStopServers($procs);

    printf("  %2d server(s) (%d alive): %8.3f s  (%d/%d -> 200)\n", $workers, $alive, $elapsed, $ok, $requests);
};

printf("I/O-bound: %d concurrent GET /msleep/1000 (1s async sleep each)\n", $requests);

$run(1, 18090);
$run(10, 18093);

echo str_repeat('-', 80) . "\n";
echo str_repeat('-', 80) . "\n";
