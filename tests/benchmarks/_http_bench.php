<?php

declare(strict_types=1);

/**
 * Helpers for the HTTP-server benchmarks: resolve the running `servers` container
 * HTTP pool (3 reusePort workers), check it is reachable, fire concurrent requests.
 *
 * The pool is the master-supervised server from docker-compose's `servers`
 * container. Benchmarks run inside the `php` container, so the pool is reachable
 * by its compose service hostname (`servers`) over the internal docker network,
 * bypassing the published-port NAT.
 */

/**
 * Host of the HTTP server pool (compose service hostname by default; override with
 * BENCH_HTTP_HOST, e.g. 127.0.0.1 to hit the published port from the host).
 */
function benchHttpHost(): string
{
    return getenv('BENCH_HTTP_HOST') ?: 'servers';
}

/**
 * Port of the HTTP server pool (the in-container listen port by default; override
 * with BENCH_HTTP_PORT, e.g. 28080 for the published host port).
 */
function benchHttpPort(): int
{
    return (int) (getenv('BENCH_HTTP_PORT') ?: 8080);
}

/**
 * Aborts the benchmark with a clear hint if the HTTP server pool is unreachable.
 */
function benchRequireHttpServers(string $host, int $port): void
{
    $connection = @fsockopen($host, $port, $errno, $errstr, 2.0);

    if (!is_resource($connection)) {
        fwrite(STDERR, "HTTP server pool not reachable at $host:$port ($errstr).\n");
        fwrite(STDERR, "Start the `servers` container with `make up` (or `make servers-restart` to rebuild it).\n");

        exit(1);
    }

    fclose($connection);
}

/**
 * Fires $count concurrent GET $url and returns [elapsed seconds, 200-count].
 *
 * @return array{float, int}
 */
function benchFireConcurrent(string $url, int $count): array
{
    $multi   = curl_multi_init();
    $handles = [];

    for ($i = 0; $i < $count; $i++) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120]);
        curl_multi_add_handle($multi, $curl);
        $handles[] = $curl;
    }

    $start = microtime(true);

    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 1.0);
    } while ($running > 0);

    $elapsed = microtime(true) - $start;

    $ok = 0;

    foreach ($handles as $curl) {
        if ((int) curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200) {
            $ok++;
        }

        curl_multi_remove_handle($multi, $curl);
        curl_close($curl);
    }

    curl_multi_close($multi);

    return [$elapsed, $ok];
}
