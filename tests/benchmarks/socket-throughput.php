<?php

declare(strict_types=1);

// Throughput benchmark: many concurrent connections each doing back-to-back ping
// round-trips (a 4-byte frame each way). Measures the raw round-trips/sec the
// socket server sustains — i.e. the per-frame PHP<->Go overhead — for one server
// vs an SO_REUSEPORT pool (one process per core).

require __DIR__ . '/_socket_bench.php';

$host        = '127.0.0.1';
$connections = 50;
$perConn     = 2000;
$workers     = max(1, (int) trim((string) shell_exec('nproc')));

$run = static function (int $servers, int $port) use ($host, $connections, $perConn): void {
    $procs = socketBenchSpawnServers($host, $port, $servers, reusePort: $servers > 1);
    $alive = benchAliveCount($procs);

    [$elapsed, $ok] = socketBenchThroughput($host, $port, $connections, $perConn, 'ping');

    benchStopServers($procs);

    $rps = $elapsed > 0 ? $ok / $elapsed : 0;

    printf("  %2d server(s) (%d alive): %8.3f s  %10.0f rt/s  (%d round-trips)\n", $servers, $alive, $elapsed, $rps, $ok);
};

printf(
    "Throughput: %d connections x %d ping round-trips (%d total)\n",
    $connections,
    $perConn,
    $connections * $perConn,
);

$run(1, 18190);
$run($workers, 18191);

echo str_repeat('-', 80) . "\n";
