<?php

declare(strict_types=1);

// Throughput benchmark: many concurrent connections each doing back-to-back ping
// round-trips (a 4-byte frame each way) against the running `servers` socket pool.
// Measures the raw round-trips/sec the pool sustains — i.e. the per-frame PHP<->Go
// overhead under concurrency.

require __DIR__ . '/_socket_bench.php';

$host        = socketBenchHost();
$port        = socketBenchPort();
$connections = 50;
$perConn     = 2000;

socketBenchRequireServers($host, $port);

[$elapsed, $ok] = socketBenchThroughput($host, $port, $connections, $perConn, 'ping');

$rps = $elapsed > 0 ? $ok / $elapsed : 0;

printf(
    "Throughput: %d connections x %d ping round-trips (%d total)\n",
    $connections,
    $perConn,
    $connections * $perConn,
);
printf("  servers pool: %8.3f s  %10.0f rt/s  (%d round-trips)\n", $elapsed, $rps, $ok);

echo str_repeat('-', 80) . "\n";
