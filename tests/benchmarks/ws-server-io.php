<?php

declare(strict_types=1);

// I/O-bound benchmark: 100 concurrent connections, each sending one msleep:1000
// message (the handler sleeps 1s asynchronously, yielding to the scheduler) against
// the running `servers` ws pool. For I/O work a single cooperative process already
// overlaps all the sleeps, so the pool's total time stays ≈ one sleep regardless of
// worker count.

require __DIR__ . '/_ws_bench.php';

$host        = wsBenchHost();
$port        = wsBenchPort();
$connections = 100;

wsBenchRequireServers($host, $port);

[$elapsed, $ok] = wsBenchConcurrentOneShot($host, $port, $connections, 'msleep:1000');

printf("I/O-bound: %d concurrent msleep:1000 round-trips (1s async sleep each)\n", $connections);
printf("  servers pool: %8.3f s  (%d/%d ok)\n", $elapsed, $ok, $connections);

echo str_repeat('-', 80) . "\n";
