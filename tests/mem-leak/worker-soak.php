<?php

/**
 * Soak test for the worker master under failures: whether anything that outlives a failure
 * keeps a piece of it — the master, a fork server, the workers that survive — while
 * requests and workers fail over and over for minutes.
 *
 * What dies takes its memory with it, so the question is about the survivors: the master
 * keeps a record of every worker, its pipes and its timers; a fork server forks and reaps;
 * a worker whose request failed keeps its runtime, its coroutines and its heap. Each
 * scenario starts a master with WORKERS workers (worker-soak-worker.php, 4 by default) on a
 * loopback port, keeps CONCURRENCY requests on /work in flight (8) as the load around the
 * failures, makes its failure for the given seconds, and reads every SAMPLE_SECONDS (20):
 *
 *   master, fork server  anonymous memory with what went to swap (Anonymous + Swap, which
 *                        neither sharing nor dropped file pages move), descriptors beyond
 *                        the three pipes held per worker, threads;
 *   workers              how many, their anonymous memory, descriptors and threads, and from
 *                        /memory their heap and the tasks of their runtime;
 *   the rest             zombies under the master and the fork servers, the files in the
 *                        runtime directory, worker exits in the journal, requests on /work
 *                        answered and failed, with why.
 *
 * The verdict looks at the reads after WARMUP_SECONDS (60): a master or fork server whose
 * descriptors or threads rose (the least value of the second half over the most of the
 * first, since a worker being spawned or reaped moves them for a moment), or whose memory
 * grew by more than a MiB; the same zombie in the last two reads; files piling up in the
 * runtime directory; the workers' heap or tasks climbing in a scenario where they survive.
 *
 * Scenarios:
 *
 *   oom               a request allocates until memory_limit ends its worker, twice a second
 *   kill              a worker is killed (SIGKILL, as the OOM killer would) twice a second
 *   exit              exit(1) in a request, twice a second
 *   fatal             a compile error in a request, twice a second
 *   spin              a CPU loop that never yields, every 2.5 s: the watchdog kills the worker
 *   block             sleep() in PHP, every 2.5 s: the watchdog kills the worker
 *   recycle           maxRequests 50: workers leave at their limit all the time
 *   crash-at-start    maxRequests 200, and half the workers exit before they serve
 *   fork-server-kill  the fork server is killed every 15 s (fork launches only)
 *   reload            a reload every 5 s under load
 *   reload-broken     every other reload names a bootstrap that does not load (bootstrap only)
 *   sigterm           a worker is told to stop every second with /slow requests in flight
 *   throw             the handler throws
 *   disconnect        the client hangs up 50 ms into a 200 ms request
 *   slowloris         clients send half a request head and hang up two seconds later; the
 *                     server applies no header deadline (docs/http-server.md, Connection
 *                     timeouts), so what counts is that it lets go of what they held
 *   bad-http          garbage instead of HTTP, and bodies over maxRequestBody
 *   orphan            50 times a second the handler starts coroutines (three 5 s waits on
 *                     the runtime, a request to /slow) and throws before waiting for them
 *   handler-timeout   the handler outlives handlerTimeoutMs while it waits on the runtime
 *   external          outgoing requests refused and out of their time
 *   preempt           300 ms of CPU with preemption on and handlerTimeoutMs 100
 *
 * LAUNCH is exec, fork or bootstrap (a fork group whose fork server loaded the application;
 * the default), a list runs each. The group accepts on the master's socket (listen);
 * LISTEN=0 gives every worker its own (reusePort). Run in the php container:
 *   php -d extension=ext/build/sconcur.so tests/mem-leak/worker-soak.php [scenarios|all] [seconds]
 *   LAUNCH=exec,bootstrap php -d extension=ext/build/sconcur.so tests/mem-leak/worker-soak.php oom,reload 120
 */

declare(strict_types=1);

/**
 * What a master of an exec group, or a fork server, holds for each worker it started: the
 * read ends of the worker's stdout, stderr and heartbeat. Descriptors beyond these are what
 * the verdict watches.
 */
const PIPES_PER_WORKER = 3;

$root          = dirname(__DIR__, 2);
$extension     = (string) (getenv('SCONCUR_EXT') ?: $root . '/ext/build/sconcur.so');
$workers       = (int) (getenv('WORKERS') ?: 4);
$seconds       = (int) ($_SERVER['argv'][2] ?? 240);
$sampleSeconds = max(1, (int) (getenv('SAMPLE_SECONDS') ?: 20));
$warmUpSeconds = (int) (getenv('WARMUP_SECONDS') ?: 60);
$concurrency   = max(1, (int) (getenv('CONCURRENCY') ?: 8));

$scenarios = [
    'oom'              => ['paced' => ['/oom' => 500]],
    'kill'             => ['signal' => ['target' => 'worker', 'signal' => SIGKILL, 'everyMs' => 500]],
    'exit'             => ['paced' => ['/exit' => 500]],
    'fatal'            => ['paced' => ['/fatal' => 500]],
    'spin'             => ['paced' => ['/spin' => 2_500], 'master' => ['watchdogTimeoutMs' => 5_000]],
    'block'            => ['paced' => ['/block' => 2_500], 'master' => ['watchdogTimeoutMs' => 5_000]],
    'recycle'          => ['server' => ['maxRequests' => 50]],
    'crash-at-start'   => ['server' => ['maxRequests' => 200], 'env' => ['FAILURES_CRASH_AT_START_PERCENT' => '50']],
    'fork-server-kill' => [
        'signal'   => ['target' => 'forkServer', 'signal' => SIGKILL, 'everyMs' => 15_000],
        'launches' => ['fork', 'bootstrap'],
    ],
    'reload'           => ['reload' => ['everyMs' => 5_000, 'broken' => false]],
    'reload-broken'    => ['reload' => ['everyMs' => 5_000, 'broken' => true], 'launches' => ['bootstrap']],
    'sigterm'          => [
        'signal'   => ['target' => 'worker', 'signal' => SIGTERM, 'everyMs' => 1_000],
        'requests' => ['/slow' => 8],
    ],
    'throw'            => ['requests' => ['/throw' => 4]],
    'disconnect'       => ['raw' => ['modes' => ['disconnect'], 'connections' => 4]],
    'slowloris'        => ['raw' => ['modes' => ['slowloris'], 'connections' => 16]],
    'bad-http'         => ['raw' => ['modes' => ['malformed', 'oversize'], 'connections' => 4], 'server' => ['maxRequestBody' => 65_536]],
    'orphan'           => ['paced' => ['/orphan' => 20]],
    'handler-timeout'  => ['requests' => ['/handler-timeout' => 4], 'server' => ['handlerTimeoutMs' => 300]],
    'external'         => ['requests' => ['/external' => 4]],
    'preempt'          => ['requests' => ['/cpu' => 2], 'server' => ['preemptionQuantumMs' => 5, 'handlerTimeoutMs' => 100]],
];

if (($_SERVER['argv'][1] ?? 'all') !== 'all') {
    $scenarios = array_intersect_key($scenarios, array_flip(explode(',', (string) $_SERVER['argv'][1])));
}

$launches = explode(',', (string) (getenv('LAUNCH') ?: 'bootstrap'));

printf(
    "%d workers, %d requests on /work in flight, %d s a scenario, read every %d s, judged after %d s\n",
    $workers,
    $concurrency,
    $seconds,
    $sampleSeconds,
    $warmUpSeconds,
);

$verdicts = [];

foreach ($launches as $launch) {
    foreach ($scenarios as $name => $scenario) {
        if (isset($scenario['launches']) && !in_array($launch, $scenario['launches'], true)) {
            continue;
        }

        $verdicts[$launch . ' ' . $name] = runScenario(
            name: $name,
            scenario: $scenario,
            launch: $launch,
            root: $root,
            extension: $extension,
            workers: $workers,
            seconds: $seconds,
            sampleSeconds: $sampleSeconds,
            warmUpSeconds: $warmUpSeconds,
            concurrency: $concurrency,
        );
    }
}

echo PHP_EOL;

foreach ($verdicts as $name => $verdict) {
    printf("%-28s %s\n", $name, $verdict);
}

/**
 * One scenario on a master of its own; answers the verdict.
 *
 * @param array<string, mixed> $scenario
 */
function runScenario(
    string $name,
    array $scenario,
    string $launch,
    string $root,
    string $extension,
    int $workers,
    int $seconds,
    int $sampleSeconds,
    int $warmUpSeconds,
    int $concurrency,
): string {
    $runtimeDir = sys_get_temp_dir() . '/sc-failures-' . $name . '-' . getmypid();
    $logDir     = $runtimeDir . '/log';

    @mkdir($logDir, 0o775, true);

    $port   = freePort();
    $config = $runtimeDir . '/master.json';

    $writeConfig = static function (bool $brokenBootstrap) use (
        $scenario,
        $launch,
        $extension,
        $workers,
        $port,
        $runtimeDir,
        $logDir,
        $config,
    ): void {
        $bootstrap = match (true) {
            $launch !== 'bootstrap' => '',
            $brokenBootstrap        => __DIR__ . '/worker-soak-broken-bootstrap.php',
            default                 => dirname(__DIR__) . '/benchmarks/worker/bootstrap.php',
        };

        file_put_contents($config, json_encode([
            'runtimeDir'          => $runtimeDir,
            'logDir'              => $logDir,
            'name'                => 'failures',
            // Workers die here on purpose and often: a backoff that grows to its default
            // 30 s would leave the pool empty rather than show what a restart keeps.
            'restartBackoffMs'    => 50,
            'maxRestartBackoffMs' => 1_000,
            ...($scenario['master'] ?? []),
            'groups'              => [[
                'name'         => 'app',
                'workerScript' => __DIR__ . '/worker-soak-worker.php',
                'workerCount'  => $workers,
                'launch'       => $launch === 'exec' ? 'exec' : 'fork',
                'bootstrap'    => $bootstrap,
                'phpArgs'      => ['-d', 'extension=' . $extension, '-d', 'memory_limit=128M'],
                // LISTEN=0 gives every worker a socket of its own on the port (reusePort)
                // instead of the master's shared one.
                ...(getenv('LISTEN') === '0' ? [] : ['listen' => '127.0.0.1:' . $port]),
                'env'          => [
                    'FAILURES_PORT'       => (string) $port,
                    'FAILURES_EXIT_TRACE' => (string) getenv('FAILURES_EXIT_TRACE'),
                    ...($scenario['env'] ?? []),
                ],
                'server'       => getenv('LISTEN') === '0'
                    ? ['address' => '127.0.0.1:' . $port, 'reusePort' => true, ...($scenario['server'] ?? [])]
                    : ($scenario['server'] ?? (object) []),
                'accessLog'    => 'off',
            ]],
        ], JSON_PRETTY_PRINT));
    };

    $writeConfig(false);

    $master = proc_open(
        [PHP_BINARY, '-d', 'extension=' . $extension, $root . '/bin/sconcur-server', 'start', '--configPath=' . $config],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $runtimeDir . '/out', 'w'], 2 => ['file', $runtimeDir . '/out', 'a']],
        $pipes,
    );

    $masterPid = (int) proc_get_status($master)['pid'];
    $label     = $launch . ' ' . $name;

    try {
        if (!waitForWorkers(masterPid: $masterPid, launch: $launch, workers: $workers, port: $port)) {
            return 'did not come up: ' . trim((string) @file_get_contents($runtimeDir . '/out'));
        }

        printf("\n%s\n", $label);

        $samples = drive(
            label: $label,
            scenario: $scenario,
            launch: $launch,
            root: $root,
            extension: $extension,
            masterPid: $masterPid,
            port: $port,
            workers: $workers,
            seconds: $seconds,
            sampleSeconds: $sampleSeconds,
            concurrency: $concurrency,
            config: $config,
            runtimeDir: $runtimeDir,
            logDir: $logDir,
            writeConfig: $writeConfig,
        );

        return judge(
            samples: $samples,
            warmUpSeconds: $warmUpSeconds,
            workersSurvive: !isset($scenario['signal'])
                && !isset($scenario['reload'])
                && !isset($scenario['paced'])
                && !isset($scenario['server']['maxRequests']),
        );
    } finally {
        stopMaster(master: $master, masterPid: $masterPid, launch: $launch);

        removeTree($runtimeDir);
    }
}

/**
 * Keeps the load and the scenario's failures going for $seconds, reading every
 * $sampleSeconds; answers the reads by elapsed seconds.
 *
 * @param array<string, mixed> $scenario
 * @param Closure(bool): void  $writeConfig
 *
 * @return array<int, array<string, mixed>>
 */
function drive(
    string $label,
    array $scenario,
    string $launch,
    string $root,
    string $extension,
    int $masterPid,
    int $port,
    int $workers,
    int $seconds,
    int $sampleSeconds,
    int $concurrency,
    string $config,
    string $runtimeDir,
    string $logDir,
    Closure $writeConfig,
): array {
    $multi = curl_multi_init();

    for ($handle = 0; $handle < $concurrency; ++$handle) {
        curl_multi_add_handle($multi, requestHandle(port: $port, path: '/work', timeoutSeconds: 5));
    }

    foreach ($scenario['requests'] ?? [] as $path => $inFlight) {
        for ($handle = 0; $handle < $inFlight; ++$handle) {
            curl_multi_add_handle($multi, requestHandle(port: $port, path: $path, timeoutSeconds: 30));
        }
    }

    $startedAt    = microtime(true);
    $nextPacedAt  = array_fill_keys(array_keys($scenario['paced'] ?? []), $startedAt);
    $endsAt       = $startedAt + $seconds;
    $nextSampleAt = $startedAt + $sampleSeconds;
    $nextSignalAt = $startedAt + ($scenario['signal']['everyMs'] ?? 0) / 1000;
    $nextReloadAt = $startedAt + ($scenario['reload']['everyMs'] ?? 0) / 1000;
    $counts       = ['answered' => 0, 'failed' => 0, 'faults' => 0, 'signals' => 0, 'reloads' => 0, 'reloadsFailed' => 0];
    $raw          = ['opened' => 0, 'closedByServer' => 0, 'outlived' => 0];
    $servedBy     = [];
    $failedBy     = [];
    $connections  = [];
    $reload       = null;
    $samples      = [];

    while (microtime(true) < $endsAt) {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.01);

        while (($done = curl_multi_info_read($multi)) !== false) {
            $handle = $done['handle'];
            $path   = (string) curl_getinfo($handle, CURLINFO_PRIVATE);
            $body   = (string) curl_multi_getcontent($handle);

            if ($path === '/work') {
                $ok = $done['result'] === CURLE_OK && curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200;

                if ($ok) {
                    ++$counts['answered'];

                    $servedBy[(int) $body] = true;
                } else {
                    ++$counts['failed'];

                    $reason = $done['result'] === CURLE_OK
                        ? 'http ' . curl_getinfo($handle, CURLINFO_RESPONSE_CODE)
                        : curl_strerror($done['result']);

                    $failedBy[$reason] = ($failedBy[$reason] ?? 0) + 1;
                }
            } else {
                ++$counts['faults'];
            }

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);

            // A paced request is sent on its own clock, not as the one before it ends.
            if (!isset($nextPacedAt[$path])) {
                curl_multi_add_handle($multi, requestHandle(port: $port, path: $path, timeoutSeconds: $path === '/work' ? 5 : 30));
            }
        }

        $now = microtime(true);

        foreach ($nextPacedAt as $path => $at) {
            if ($now >= $at) {
                curl_multi_add_handle($multi, requestHandle(port: $port, path: $path, timeoutSeconds: 30));

                $nextPacedAt[$path] = $at + $scenario['paced'][$path] / 1000;
            }
        }

        if (isset($scenario['signal']) && $now >= $nextSignalAt) {
            [$workerPids, $forkServerPids] = processesOf(masterPid: $masterPid, launch: $launch);

            $targets = $scenario['signal']['target'] === 'worker' ? $workerPids : $forkServerPids;

            if ($targets !== []) {
                posix_kill($targets[array_rand($targets)], $scenario['signal']['signal']);

                ++$counts['signals'];
            }

            $nextSignalAt += $scenario['signal']['everyMs'] / 1000;
        }

        if (isset($scenario['reload'])) {
            if ($reload !== null && !proc_get_status($reload)['running']) {
                $exitCode = proc_get_status($reload)['exitcode'];

                proc_close($reload);

                ++$counts['reloads'];

                if ($exitCode !== 0) {
                    ++$counts['reloadsFailed'];
                }

                $reload = null;
            }

            if ($reload === null && $now >= $nextReloadAt) {
                // Every other reload of reload-broken names the bootstrap that does not load;
                // the one after it puts the good one back.
                $writeConfig($scenario['reload']['broken'] && $counts['reloads'] % 2 === 0);

                $reload = proc_open(
                    [PHP_BINARY, '-d', 'extension=' . $extension, $root . '/bin/sconcur-server', 'reload', '--configPath=' . $config],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                    $pipes,
                );

                $nextReloadAt = $now + $scenario['reload']['everyMs'] / 1000;
            }
        }

        if (isset($scenario['raw'])) {
            tickConnections(
                connections: $connections,
                counts: $raw,
                modes: $scenario['raw']['modes'],
                wanted: $scenario['raw']['connections'],
                port: $port,
            );
        }

        if ($now >= $nextSampleAt) {
            $elapsed = (int) round($now - $startedAt);

            $samples[$elapsed] = sample(
                masterPid: $masterPid,
                launch: $launch,
                port: $port,
                workers: $workers,
                runtimeDir: $runtimeDir,
                logDir: $logDir,
            ) + $counts + ['raw' => $raw, 'servedBy' => count($servedBy), 'failedBy' => $failedBy];

            printSample(label: $label, elapsed: $elapsed, sample: $samples[$elapsed]);

            $nextSampleAt += $sampleSeconds;
        }
    }

    // What is still in flight is dropped: a request on /spin would hold the scenario for its
    // whole timeout.
    foreach ($connections as $connection) {
        fclose($connection['socket']);
    }

    curl_multi_close($multi);

    if ($reload !== null) {
        proc_close($reload);
    }

    return $samples;
}

/**
 * The scenario's raw connections: each one plays its mode, and is replaced once it is over.
 *
 * @param list<array{socket: resource, mode: string, out: string, closeAt: float, deadline: float}> $connections
 * @param array{opened: int, closedByServer: int, outlived: int}                                    $counts
 * @param list<string>                                                                              $modes
 */
function tickConnections(array &$connections, array &$counts, array $modes, int $wanted, int $port): void
{
    $now = microtime(true);

    foreach ($connections as $index => &$connection) {
        if ($connection['out'] !== '') {
            $written = @fwrite($connection['socket'], $connection['out']);

            $connection['out'] = $written === false ? '' : substr($connection['out'], $written);
        }

        // Whatever the server answers is read and dropped; what counts is whether it closes.
        @fread($connection['socket'], 65_536);

        $closed = feof($connection['socket']);

        if ($closed || $now >= $connection['closeAt'] || $now >= $connection['deadline']) {
            if ($closed) {
                ++$counts['closedByServer'];
            } elseif ($now >= $connection['deadline']) {
                ++$counts['outlived'];
            }

            fclose($connection['socket']);

            unset($connections[$index]);
        }
    }

    unset($connection);

    while (count($connections) < $wanted) {
        $mode   = $modes[$counts['opened'] % count($modes)];
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 1);

        ++$counts['opened'];

        if ($socket === false) {
            continue;
        }

        stream_set_blocking($socket, false);

        $connections[] = [
            'socket'   => $socket,
            'mode'     => $mode,
            'out'      => match ($mode) {
                'disconnect' => "GET /slow HTTP/1.1\r\nHost: bench\r\n\r\n",
                'slowloris'  => "GET /work HTTP/1.1\r\nHost: bench\r\nX-Slow: ",
                'malformed'  => "NONSENSE \x00\x01\x02 / HTTP/9.9\r\n\r\n",
                'oversize'   => "POST /work HTTP/1.1\r\nHost: bench\r\nContent-Length: 1048576\r\n\r\n" . str_repeat('x', 131_072),
            },
            // A disconnect hangs up on purpose, a slowloris once it has held the connection
            // for two seconds (the server applies no header deadline, see the scenario); the
            // others wait for the server to close.
            'closeAt'  => match ($mode) {
                'disconnect' => $now + 0.05,
                'slowloris'  => $now + 2,
                default      => INF,
            },
            'deadline' => $now + 10,
        ];
    }

    $connections = array_values($connections);
}

/**
 * One read of the master, its fork servers and its workers.
 *
 * @return array<string, mixed>
 */
function sample(int $masterPid, string $launch, int $port, int $workers, string $runtimeDir, string $logDir): array
{
    $table = processTable();

    [$workerPids, $forkServerPids] = processesOf(masterPid: $masterPid, launch: $launch, table: $table);

    $workerReads = array_map(processRead(...), $workerPids);
    $heaps       = heapsOf(port: $port, workers: $workers);

    $zombies = [];

    foreach ($table as $pid => $process) {
        if ($process['state'] === 'Z' && ($process['parent'] === $masterPid || in_array($process['parent'], $forkServerPids, true))) {
            $zombies[] = $pid;
        }
    }

    $runtimeFiles = array_filter(glob($runtimeDir . '/*') ?: [], static fn(string $path): bool => $path !== $logDir);

    $exits = 0;

    foreach (glob($logDir . '/failures-*.log') ?: [] as $journal) {
        $exits += substr_count((string) @file_get_contents($journal), ' exited ');
    }

    $children = static function (int $parent) use ($table): int {
        return count(array_filter($table, static fn(array $process): bool => $process['parent'] === $parent));
    };

    $master = processRead($masterPid);

    $master['ownFds'] = $master['fds'] - ($launch === 'exec' ? PIPES_PER_WORKER * $children($masterPid) : 0);

    $forkServers = [];

    foreach ($forkServerPids as $pid) {
        $forkServers[$pid] = processRead($pid);

        $forkServers[$pid]['ownFds'] = $forkServers[$pid]['fds'] - PIPES_PER_WORKER * $children($pid);
    }

    return [
        'master'        => $master,
        'forkServers'   => $forkServers,
        'workers'       => count($workerPids),
        'workerAnon'    => $workerReads === [] ? 0 : array_sum(array_column($workerReads, 'anonKib')) / count($workerReads),
        'workerFds'     => $workerReads === [] ? 0 : max(array_column($workerReads, 'fds')),
        'workerThreads' => $workerReads === [] ? 0 : max(array_column($workerReads, 'threads')),
        'heapUsed'      => $heaps === [] ? 0 : array_sum(array_column($heaps, 'used')) / count($heaps),
        'tasks'         => $heaps === [] ? 0 : max(array_column($heaps, 'tasks')),
        'heapsRead'     => count($heaps),
        'zombies'       => $zombies,
        'runtimeFiles'  => count($runtimeFiles),
        'exits'         => $exits,
    ];
}

/** @param array<string, mixed> $sample */
function printSample(string $label, int $elapsed, array $sample): void
{
    $forkServers = [];

    foreach ($sample['forkServers'] as $pid => $read) {
        $forkServers[] = sprintf('%d: %.1f MiB fd %d (%d own) thr %d', $pid, $read['anonKib'] / 1024, $read['fds'], $read['ownFds'], $read['threads']);
    }

    printf(
        "%-22s t=%-5s master %.1f MiB fd %d (%d own) thr %d | fork server %s | %d workers: %.1f MiB, fd %d, thr %d,"
        . " heap %.2f MiB, tasks %d (%d read) | zombies %s | runtime files %d | exits %d | /work %d ok %d failed"
        . " by %d workers | faults %d signals %d reloads %d (%d failed) | raw %d opened %d closed by server %d outlived\n",
        $label,
        $elapsed . 's',
        $sample['master']['anonKib'] / 1024,
        $sample['master']['fds'],
        $sample['master']['ownFds'],
        $sample['master']['threads'],
        $forkServers === [] ? '-' : implode(', ', $forkServers),
        $sample['workers'],
        $sample['workerAnon'] / 1024,
        $sample['workerFds'],
        $sample['workerThreads'],
        $sample['heapUsed'] / 1024 / 1024,
        $sample['tasks'],
        $sample['heapsRead'],
        $sample['zombies'] === [] ? '-' : implode(',', $sample['zombies']),
        $sample['runtimeFiles'],
        $sample['exits'],
        $sample['answered'],
        $sample['failed'],
        $sample['servedBy'],
        $sample['faults'],
        $sample['signals'],
        $sample['reloads'],
        $sample['reloadsFailed'],
        $sample['raw']['opened'],
        $sample['raw']['closedByServer'],
        $sample['raw']['outlived'],
    );
}

/**
 * The first read after the warm-up against the last.
 *
 * @param array<int, array<string, mixed>> $samples
 */
function judge(array $samples, int $warmUpSeconds, bool $workersSurvive): string
{
    $after = array_filter($samples, static fn(int $elapsed): bool => $elapsed >= $warmUpSeconds, ARRAY_FILTER_USE_KEY);

    if (count($after) < 2) {
        return 'no verdict: fewer than two reads after the warm-up';
    }

    $first = reset($after);
    $last  = end($after);
    $prior = array_values($after)[count($after) - 2];

    $events = max(1, $last['exits'] - $first['exits'], $last['faults'] - $first['faults'], $last['raw']['opened'] - $first['raw']['opened']);

    $suspects = [];

    // Descriptors and threads are compared by the most the first half of the reads saw
    // against the least the second half saw: a worker being spawned or reaped as a read is
    // taken moves them either way for a moment (a fork server's pipes are counted off by
    // the workers a read finds, which crashing workers make miss both ways), a leak lifts
    // the second half's floor over the first half's ceiling.
    $grown = static function (string $who, array $reads) use (&$suspects): string {
        $halves = array_chunk($reads, (int) ceil(count($reads) / 2));
        $range  = static fn(string $field): array => [
            max(array_column($halves[0], $field)),
            min(array_column($halves[1] ?? $halves[0], $field)),
        ];

        $fds       = $range('ownFds');
        $threads   = $range('threads');
        $memoryMib = (end($reads)['anonKib'] - $reads[0]['anonKib']) / 1024;

        if ($fds[1] > $fds[0]) {
            $suspects[] = sprintf('%s descriptors beyond its workers\' pipes %d -> %d', $who, $fds[0], $fds[1]);
        }

        if ($threads[1] > $threads[0]) {
            $suspects[] = sprintf('%s threads %d -> %d', $who, $threads[0], $threads[1]);
        }

        if ($memoryMib > 1.0) {
            $suspects[] = sprintf('%s memory %+.1f MiB', $who, $memoryMib);
        }

        return sprintf('%s %+.2f MiB fd %+d thr %+d', $who, $memoryMib, max(0, $fds[1] - $fds[0]), max(0, $threads[1] - $threads[0]));
    };

    $parts = [$grown('master', array_column($after, 'master'))];

    foreach ($last['forkServers'] as $pid => $read) {
        $reads = array_values(array_filter(array_map(
            static fn(array $sample): ?array => $sample['forkServers'][$pid] ?? null,
            $after,
        )));

        $parts[] = count($reads) >= 2 ? $grown('fork server', $reads) : 'fork server replaced';
    }

    $lastingZombies = array_intersect($prior['zombies'], $last['zombies']);

    if ($lastingZombies !== []) {
        $suspects[] = 'zombies left unreaped across two reads: ' . implode(',', $lastingZombies);
    }

    if ($last['runtimeFiles'] > $first['runtimeFiles']) {
        $suspects[] = sprintf('runtime files %d -> %d', $first['runtimeFiles'], $last['runtimeFiles']);
    }

    $heapMib = ($last['heapUsed'] - $first['heapUsed']) / 1024 / 1024;

    if ($workersSurvive) {
        if ($heapMib > 1.0) {
            $suspects[] = sprintf('worker heap %+.2f MiB', $heapMib);
        }

        if ($last['tasks'] > $first['tasks'] + 20) {
            $suspects[] = sprintf('worker tasks %d -> %d', $first['tasks'], $last['tasks']);
        }
    }

    if ($last['answered'] === $first['answered']) {
        $suspects[] = 'nothing answered on /work after the warm-up';
    }

    $failedBy = [];

    foreach ($last['failedBy'] as $reason => $count) {
        $failedBy[] = $reason . ' ' . ($count - ($first['failedBy'][$reason] ?? 0));
    }

    return sprintf(
        '%s: %d events after the warm-up (%d exits); %s; worker heap %+.2f MiB, tasks %d -> %d; /work %d ok %d failed%s',
        $suspects === [] ? 'ok' : 'SUSPECT ' . implode('; ', $suspects),
        $events,
        $last['exits'] - $first['exits'],
        implode(', ', $parts),
        $heapMib,
        $first['tasks'],
        $last['tasks'],
        $last['answered'] - $first['answered'],
        $last['failed'] - $first['failed'],
        $failedBy === [] ? '' : ' (' . implode(', ', $failedBy) . ')',
    );
}

/**
 * Anonymous memory with what went to swap, descriptors and threads of one process.
 *
 * @return array{anonKib: int, fds: int, threads: int}
 */
function processRead(int $pid): array
{
    $anonKib = 0;

    foreach (explode("\n", (string) @file_get_contents('/proc/' . $pid . '/smaps_rollup')) as $line) {
        if (preg_match('/^(Anonymous|Swap):\s+(\d+) kB/', $line, $match) === 1) {
            $anonKib += (int) $match[2];
        }
    }

    preg_match('/^Threads:\s+(\d+)/m', (string) @file_get_contents('/proc/' . $pid . '/status'), $threads);

    return [
        'anonKib' => $anonKib,
        'fds'     => count(@scandir('/proc/' . $pid . '/fd') ?: ['.', '..']) - 2,
        'threads' => (int) ($threads[1] ?? 0),
    ];
}

/**
 * What PHP's heap holds and the tasks of the runtime in every worker that answered /memory:
 * asked all at once on fresh connections, which spread over the workers, with a short
 * timeout, since a worker the scenario hangs answers nothing.
 *
 * @return array<int, array{used: int, real: int, tasks: int}> by worker pid
 */
function heapsOf(int $port, int $workers): array
{
    $multi = curl_multi_init();

    for ($handle = 0; $handle < $workers * 8; ++$handle) {
        $curl = requestHandle(port: $port, path: '/memory', timeoutSeconds: 1);

        curl_multi_add_handle($multi, $curl);
    }

    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.05);
    } while ($running > 0);

    $heaps = [];

    while (($done = curl_multi_info_read($multi)) !== false) {
        $answer = explode(' ', (string) curl_multi_getcontent($done['handle']));

        if (count($answer) === 4) {
            $heaps[(int) $answer[0]] = ['used' => (int) $answer[1], 'real' => (int) $answer[2], 'tasks' => (int) $answer[3]];
        }

        curl_multi_remove_handle($multi, $done['handle']);
        curl_close($done['handle']);
    }

    curl_multi_close($multi);

    return $heaps;
}

/** A request on a fresh connection, so the workers share them; tagged with its path. */
function requestHandle(int $port, string $path, int $timeoutSeconds): CurlHandle
{
    $curl = curl_init('http://127.0.0.1:' . $port . $path);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FORBID_REUSE   => true,
        CURLOPT_FRESH_CONNECT  => true,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_PRIVATE        => $path,
    ]);

    return $curl;
}

/**
 * Every process: its parent and its state.
 *
 * @return array<int, array{parent: int, state: string}>
 */
function processTable(): array
{
    $table = [];

    foreach (glob('/proc/[0-9]*/stat') ?: [] as $stat) {
        $fields = explode(' ', (string) preg_replace('/^.*\) /', '', (string) @file_get_contents($stat)));

        if (count($fields) > 1) {
            $table[(int) basename(dirname($stat))] = ['parent' => (int) $fields[1], 'state' => $fields[0]];
        }
    }

    return $table;
}

/**
 * The live workers of a master and its fork servers: an exec group's workers are the
 * master's children, a fork group's the children of its fork servers. Zombies are left out.
 *
 * @param null|array<int, array{parent: int, state: string}> $table
 *
 * @return array{0: list<int>, 1: list<int>}
 */
function processesOf(int $masterPid, string $launch, ?array $table = null): array
{
    $table ??= processTable();

    $childrenOf = static function (int $parent) use ($table): array {
        $children = [];

        foreach ($table as $pid => $process) {
            if ($process['parent'] === $parent && $process['state'] !== 'Z') {
                $children[] = $pid;
            }
        }

        return $children;
    };

    if ($launch === 'exec') {
        return [$childrenOf($masterPid), []];
    }

    $forkServers = $childrenOf($masterPid);
    $workers     = [];

    foreach ($forkServers as $forkServer) {
        $workers = [...$workers, ...$childrenOf($forkServer)];
    }

    return [$workers, $forkServers];
}

function waitForWorkers(int $masterPid, string $launch, int $workers, int $port): bool
{
    $deadline = microtime(true) + 30.0;

    while (microtime(true) < $deadline) {
        if (count(processesOf(masterPid: $masterPid, launch: $launch)[0]) >= $workers && count(heapsOf(port: $port, workers: $workers)) > 0) {
            // Every worker has to be past its start, not only the first to answer.
            usleep(500_000);

            return true;
        }

        usleep(50_000);
    }

    return false;
}

/**
 * Asks the master to stop, and kills whatever is left of the tree after 20 s: a worker
 * the scenario hung holds a graceful stop for its whole shutdown timeout.
 *
 * @param resource $master
 */
function stopMaster($master, int $masterPid, string $launch): void
{
    [$workerPids, $forkServerPids] = processesOf(masterPid: $masterPid, launch: $launch);

    proc_terminate($master);

    $deadline = microtime(true) + 20.0;

    while (proc_get_status($master)['running'] && microtime(true) < $deadline) {
        usleep(100_000);
    }

    foreach ([$masterPid, ...$forkServerPids, ...$workerPids] as $pid) {
        @posix_kill($pid, SIGKILL);
    }

    proc_close($master);
}

function removeTree(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $path) {
        is_dir($path) ? removeTree($path) : @unlink($path);
    }

    @rmdir($directory);
}

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name   = (string) stream_socket_get_name($socket, false);

    fclose($socket);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}
