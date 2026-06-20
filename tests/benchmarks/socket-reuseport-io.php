<?php

declare(strict_types=1);

// I/O-bound benchmark: 100 concurrent connections, each sending one msleep:1000
// frame (the handler sleeps 1s asynchronously, yielding to the scheduler). Run
// against 1 server vs 10 SO_REUSEPORT servers. For I/O work the count makes no
// difference — a single cooperative process already overlaps all the sleeps — but
// the comparison mirrors the CPU-bound benchmark.

require __DIR__ . '/_socket_bench.php';

$host        = '127.0.0.1';
$connections = 100;

$run = static function (int $servers, int $port) use ($host, $connections): void {
    $procs = socketBenchSpawnServers($host, $port, $servers, reusePort: $servers > 1);
    $alive = benchAliveCount($procs);

    [$elapsed, $ok] = socketBenchConcurrentOneShot($host, $port, $connections, 'msleep:1000');

    benchStopServers($procs);

    printf("  %2d server(s) (%d alive): %8.3f s  (%d/%d ok)\n", $servers, $alive, $elapsed, $ok, $connections);
};

printf("I/O-bound: %d concurrent msleep:1000 round-trips (1s async sleep each)\n", $connections);

$run(1, 18192);
$run(10, 18193);

echo str_repeat('-', 80) . "\n";
