<?php

declare(strict_types=1);

// CPU-bound benchmark: 100 concurrent GET /cpu/{n} (a sha256 loop that does NOT
// yield) against the running `servers` HTTP pool (3 SO_REUSEPORT workers). The
// kernel spreads the compute across the pool's processes/cores — where one
// cooperative process is serialised, the pool runs requests in parallel.

require __DIR__ . '/_http_bench.php';

$host       = benchHttpHost();
$port       = benchHttpPort();
$requests   = 100;
$iterations = 100_000; // ~per-request CPU cost

benchRequireHttpServers($host, $port);

[$elapsed, $ok] = benchFireConcurrent("http://$host:$port/cpu/$iterations", $requests);

printf("CPU-bound: %d concurrent GET /cpu/%d (sha256 loop)\n", $requests, $iterations);
printf("  servers pool: %8.3f s  (%d/%d -> 200)\n", $elapsed, $ok, $requests);

echo str_repeat('-', 80) . "\n";
