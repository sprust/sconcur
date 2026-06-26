<?php

declare(strict_types=1);

// Throughput benchmark: many concurrent connections each doing back-to-back ping
// round-trips (one WebSocket message each way) against the running `servers` ws pool.
// Measures the raw round-trips/sec the pool sustains — i.e. the per-message PHP<->Go
// plus WebSocket-framing overhead under concurrency.

require __DIR__ . '/_ws_bench.php';

$host        = wsBenchHost();
$port        = wsBenchPort();
$connections = 50;
$perConn     = 2000;

wsBenchRequireServers($host, $port);

[$elapsed, $ok] = wsBenchThroughput($host, $port, $connections, $perConn, 'ping');

$rps = $elapsed > 0 ? $ok / $elapsed : 0;

printf(
    "Throughput: %d connections x %d ping round-trips (%d total)\n",
    $connections,
    $perConn,
    $connections * $perConn,
);
printf("  servers pool: %8.3f s  %10.0f rt/s  (%d round-trips)\n", $elapsed, $rps, $ok);

echo str_repeat('-', 80) . "\n";
