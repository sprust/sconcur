<?php

declare(strict_types=1);

// I/O-bound benchmark: 100 concurrent GET /msleep/1000 (each handler sleeps 1s
// asynchronously, yielding to the scheduler) against the running `servers` HTTP
// pool. For I/O work a single cooperative process already overlaps all the sleeps,
// so the pool's total time stays ≈ one sleep regardless of worker count.

require __DIR__ . '/_http_bench.php';

$host     = benchHttpHost();
$port     = benchHttpPort();
$requests = 100;

benchRequireHttpServers($host, $port);

[$elapsed, $ok] = benchFireConcurrent("http://$host:$port/msleep/1000", $requests);

printf("I/O-bound: %d concurrent GET /msleep/1000 (1s async sleep each)\n", $requests);
printf("  servers pool: %8.3f s  (%d/%d -> 200)\n", $elapsed, $ok, $requests);

echo str_repeat('-', 80) . "\n";
