<?php

declare(strict_types=1);

// CPU-bound benchmark: 100 concurrent connections, each sending one cpu:{n} frame
// (a sha256 loop that does NOT yield) against the running `servers` socket pool (3
// SO_REUSEPORT workers). The kernel spreads the compute across the pool's
// processes/cores — where one cooperative process is serialised, the pool runs
// connections in parallel.

require __DIR__ . '/_socket_bench.php';

$host        = socketBenchHost();
$port        = socketBenchPort();
$connections = 100;
$iterations  = 100_000; // ~per-request CPU cost

socketBenchRequireServers($host, $port);

[$elapsed, $ok] = socketBenchConcurrentOneShot($host, $port, $connections, "cpu:$iterations");

printf("CPU-bound: %d concurrent cpu:%d round-trips (sha256 loop)\n", $connections, $iterations);
printf("  servers pool: %8.3f s  (%d/%d ok)\n", $elapsed, $ok, $connections);

echo str_repeat('-', 80) . "\n";
