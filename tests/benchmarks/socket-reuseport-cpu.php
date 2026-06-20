<?php

declare(strict_types=1);

// CPU-bound benchmark: 100 concurrent connections, each sending one cpu:{n} frame
// (a sha256 loop that does NOT yield). Run against 1 server vs 10 SO_REUSEPORT
// servers to show the kernel spreading compute across processes/cores — where one
// cooperative process is serialised, ten run in parallel.

require __DIR__ . '/_socket_bench.php';

$host        = '127.0.0.1';
$connections = 100;
$iterations  = 100_000; // ~per-request CPU cost; same for both runs

$run = static function (int $servers, int $port) use ($host, $connections, $iterations): void {
    $procs = socketBenchSpawnServers($host, $port, $servers, reusePort: $servers > 1);
    $alive = benchAliveCount($procs);

    [$elapsed, $ok] = socketBenchConcurrentOneShot($host, $port, $connections, "cpu:$iterations");

    benchStopServers($procs);

    printf("  %2d server(s) (%d alive): %8.3f s  (%d/%d ok)\n", $servers, $alive, $elapsed, $ok, $connections);
};

printf("CPU-bound: %d concurrent cpu:%d round-trips (sha256 loop)\n", $connections, $iterations);

$run(1, 18194);
$run(10, 18195);

echo str_repeat('-', 80) . "\n";
