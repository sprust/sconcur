<?php

/**
 * What a pool of workers costs in memory, by how its workers come to be:
 *
 *   exec       a php of its own per worker, which loads the application itself;
 *   fork       a fork of the group's fork server per worker, which loads the application after
 *              the fork;
 *   bootstrap  a fork of a fork server that loaded the application (the group's bootstrap).
 *
 * The application is app.php, a stand-in for a framework's bootstrap. Each arm starts a
 * master with WORKERS workers on a loopback port, waits for all of them to answer, reads
 * /proc/<pid>/smaps_rollup of the master, the fork server and every worker, sends REQUESTS requests on
 * fresh connections (so the kernel spreads them over the workers and each has served),
 * and reads it again.
 *
 * Run in the php container:
 *   php -d extension=ext/build/sconcur.so tests/benchmarks/worker/memory.php
 *   WORKERS=16 REQUESTS=20000 php -d extension=ext/build/sconcur.so tests/benchmarks/worker/memory.php
 *
 * Pss divides every shared page among the processes that share it, so its sum is what
 * the pool takes from the machine; Private_Dirty is what a worker has written and
 * holds alone.
 */

declare(strict_types=1);

$root      = dirname(__DIR__, 3);
$extension = (string) (getenv('SCONCUR_EXT') ?: $root . '/ext/build/sconcur.so');
$workers   = (int) (getenv('WORKERS') ?: 12);
$requests  = (int) (getenv('REQUESTS') ?: 5000);

$arms = [
    'exec'      => ['launch' => 'exec', 'bootstrap' => ''],
    'fork'      => ['launch' => 'fork', 'bootstrap' => ''],
    'bootstrap' => ['launch' => 'fork', 'bootstrap' => __DIR__ . '/bootstrap.php'],
];

printf(
    "%d workers, %d requests, opcache.enable_cli=%s\n\n",
    $workers,
    $requests,
    ini_get('opcache.enable_cli') ?: '0',
);

printf("%-10s %-6s %12s %12s %16s %12s\n", 'arm', 'when', 'Pss, sum', 'Rss, sum', 'Private_Dirty', 'Pss/worker');

foreach ($arms as $arm => $setup) {
    $runtimeDir = sys_get_temp_dir() . '/sc-memory-' . $arm . '-' . getmypid();

    @mkdir($runtimeDir, 0o775, true);

    $port   = freePort();
    $config = $runtimeDir . '/master.json';

    file_put_contents($config, json_encode([
        'runtimeDir' => $runtimeDir,
        'logDir'     => $runtimeDir,
        'name'       => 'memory',
        'groups'     => [[
            'name'         => 'app',
            'workerScript' => __DIR__ . '/app-worker.php',
            'workerCount'  => $workers,
            'launch'       => $setup['launch'],
            'bootstrap'    => $setup['bootstrap'],
            'phpArgs'      => ['-d', 'extension=' . $extension],
            'server'       => ['address' => '127.0.0.1:' . $port, 'reusePort' => true],
        ]],
    ]));

    $master = proc_open(
        [
            PHP_BINARY,
            '-d',
            'extension=' . $extension,
            $root . '/bin/sconcur-server',
            'start',
            '--configPath=' . $config,
        ],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $runtimeDir . '/out', 'w'], 2 => ['file', $runtimeDir . '/out', 'a']],
        $pipes,
    );

    $masterPid = (int) proc_get_status($master)['pid'];

    try {
        if (!waitForWorkers($masterPid, $workers, $port)) {
            fwrite(STDERR, "$arm: the workers did not come up: " . @file_get_contents($runtimeDir . '/out') . PHP_EOL);

            continue;
        }

        report($arm, 'idle', $masterPid, $workers);

        for ($request = 0; $request < $requests; ++$request) {
            ask($port);
        }

        report($arm, 'served', $masterPid, $workers);
    } finally {
        proc_terminate($master, SIGTERM);
        proc_close($master);

        foreach (glob($runtimeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($runtimeDir);
    }
}

function report(string $arm, string $when, int $masterPid, int $workers): void
{
    $sum = ['Pss' => 0, 'Rss' => 0, 'Private_Dirty' => 0];

    [$workerPids, $forkServerPids] = workersOf($masterPid);

    foreach ($workerPids as $pid) {
        foreach (rollup($pid) as $field => $kib) {
            $sum[$field] += $kib;
        }
    }

    $supervisorPss = rollup($masterPid)['Pss'];

    foreach ($forkServerPids as $pid) {
        $supervisorPss += rollup($pid)['Pss'];
    }

    printf(
        "%-10s %-6s %9.1f MiB %9.1f MiB %13.1f MiB %9.1f MiB   (master and fork server Pss %.1f MiB)\n",
        $arm,
        $when,
        $sum['Pss'] / 1024,
        $sum['Rss'] / 1024,
        $sum['Private_Dirty'] / 1024,
        $sum['Pss'] / 1024 / $workers,
        $supervisorPss / 1024,
    );
}

/**
 * The workers of a master, and its fork servers: an exec group's workers are the master's
 * children, a fork group's are the children of the fork server that is.
 *
 * @return array{0: list<int>, 1: list<int>}
 */
function workersOf(int $masterPid): array
{
    $workers = [];
    $forkServers = [];

    foreach (children($masterPid) as $child) {
        $grandchildren = children($child);

        if ($grandchildren === []) {
            $workers[] = $child;

            continue;
        }

        $forkServers[] = $child;
        $workers   = [...$workers, ...$grandchildren];
    }

    return [$workers, $forkServers];
}

/**
 * @return array{Pss: int, Rss: int, Private_Dirty: int} in KiB
 */
function rollup(int $pid): array
{
    $values = ['Pss' => 0, 'Rss' => 0, 'Private_Dirty' => 0];

    foreach (explode("\n", (string) @file_get_contents('/proc/' . $pid . '/smaps_rollup')) as $line) {
        if (preg_match('/^(Pss|Rss|Private_Dirty):\s+(\d+) kB/', $line, $match) === 1) {
            $values[$match[1]] = (int) $match[2];
        }
    }

    return $values;
}

/**
 * @return list<int>
 */
function children(int $parent): array
{
    $children = [];

    foreach (glob('/proc/[0-9]*/stat') ?: [] as $stat) {
        $fields = explode(' ', (string) preg_replace('/^.*\) /', '', (string) @file_get_contents($stat)));

        if ((int) ($fields[1] ?? 0) === $parent) {
            $children[] = (int) basename(dirname($stat));
        }
    }

    return $children;
}

function waitForWorkers(int $masterPid, int $workers, int $port): bool
{
    $deadline = microtime(true) + 30.0;

    while (microtime(true) < $deadline) {
        if (count(workersOf($masterPid)[0]) >= $workers && ask($port) !== '') {
            // Every worker has to be past its start, not only the first to answer.
            usleep(500_000);

            return true;
        }

        usleep(50_000);
    }

    return false;
}

function ask(int $port): string
{
    $curl = curl_init('http://127.0.0.1:' . $port . '/');

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FORBID_REUSE   => true,
        CURLOPT_FRESH_CONNECT  => true,
        CURLOPT_TIMEOUT        => 3,
    ]);

    $body = curl_exec($curl);

    curl_close($curl);

    return is_string($body) ? $body : '';
}

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name   = (string) stream_socket_get_name($socket, false);

    fclose($socket);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}
