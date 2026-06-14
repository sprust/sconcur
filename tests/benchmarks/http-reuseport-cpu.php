<?php

declare(strict_types=1);

// CPU-bound benchmark: 100 concurrent GET /cpu/{n} (a sha256 loop that does NOT
// yield). Run against 1 server vs 10 SO_REUSEPORT servers to show the kernel
// spreading compute across processes/cores — where one cooperative process is
// serialised, ten run in parallel.

require __DIR__ . '/_http_bench.php';

$host       = '127.0.0.1';
$requests   = 100;
$iterations = 100_000; // ~per-request CPU cost; same for both runs

$run = static function (int $workers, int $port) use ($host, $requests, $iterations): void {
    $procs = benchSpawnServers($host, $port, $workers, reusePort: $workers > 1);
    $alive = benchAliveCount($procs);

    [$elapsed, $ok] = benchFireConcurrent("http://$host:$port/cpu/$iterations", $requests);

    benchStopServers($procs);

    printf("  %2d server(s) (%d alive): %8.3f s  (%d/%d -> 200)\n", $workers, $alive, $elapsed, $ok, $requests);
};

printf("CPU-bound: %d concurrent GET /cpu/%d (sha256 loop)\n", $requests, $iterations);

$run(1, 18091);
$run(10, 18092);

echo str_repeat('-', 80) . "\n";
echo str_repeat('-', 80) . "\n";
