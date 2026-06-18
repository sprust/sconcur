<?php

declare(strict_types=1);

/**
 * Helpers for the HTTP-server SO_REUSEPORT benchmarks: spawn N demo-server
 * worker processes on one port, fire concurrent requests, measure, tear down.
 */

function benchRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Spawns $workers demo servers on $host:$port (with SO_REUSEPORT when >1) and
 * waits until the port answers.
 *
 * @return array<int, resource>
 */
function benchSpawnServers(string $host, int $port, int $workers, bool $reusePort): array
{
    $root      = benchRoot();
    $extension = $root . '/ext/build/sconcur.so';
    $script    = $root . '/tests/servers/http/http-server.php';

    $command = ['php', '-d', 'extension=' . $extension, $script, "--address=$host:$port"];

    if ($reusePort) {
        $command[] = '--reusePort=1';
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];

    $procs = [];

    for ($i = 0; $i < $workers; $i++) {
        $proc = proc_open($command, $descriptors, $pipes, $root);

        if (!is_resource($proc)) {
            fwrite(STDERR, "failed to spawn worker $i\n");
            exit(1);
        }

        fclose($pipes[0]);

        $procs[] = $proc;
    }

    $deadline = microtime(true) + 5.0;

    while (microtime(true) < $deadline) {
        $connection = @fsockopen($host, $port, $errno, $errstr, 0.2);

        if (is_resource($connection)) {
            fclose($connection);

            return $procs;
        }

        usleep(50_000);
    }

    benchStopServers($procs);

    fwrite(STDERR, "servers not reachable on $host:$port\n");
    exit(1);
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

/**
 * @param array<int, resource> $procs
 */
function benchAliveCount(array $procs): int
{
    $alive = 0;

    foreach ($procs as $proc) {
        if (proc_get_status($proc)['running']) {
            $alive++;
        }
    }

    return $alive;
}

/**
 * @param array<int, resource> $procs
 */
function benchStopServers(array $procs): void
{
    foreach ($procs as $proc) {
        if (proc_get_status($proc)['running']) {
            proc_terminate($proc, SIGKILL);
        }

        proc_close($proc);
    }
}
