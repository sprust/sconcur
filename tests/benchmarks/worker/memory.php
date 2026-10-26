<?php

/**
 * What a pool of workers costs in memory, by how its workers come to be:
 *
 *   exec     a php of its own per worker, which loads the application itself (no master);
 *   fork     a fork of a WorkerMaster per worker, which loads the application after the fork;
 *   preload  a fork of a WorkerMaster that loaded the application before run(), the way an
 *            application boots before it builds its master.
 *
 * The application is app.php, a stand-in for a framework's bootstrap. Each arm starts
 * WORKERS workers on a loopback port, waits for all of them to answer, reads
 * /proc/<pid>/smaps_rollup of the master and every worker, sends REQUESTS requests on
 * fresh connections (so the kernel spreads them over the workers and each has served),
 * and reads it again.
 *
 * Run in the php container:
 *   php -d extension=ext/build/sconcur.so tests/benchmarks/worker/memory.php
 *   WORKERS=16 REQUESTS=20000 php -d extension=ext/build/sconcur.so tests/benchmarks/worker/memory.php
 *
 * Pss divides every shared page among the processes that share it, so its sum is what
 * the pool takes from the machine; Private_Dirty is what a worker has written and
 * holds alone. Rss counts a shared page whole in every process that maps it, so its sum
 * overstates the pool, and a page a forked worker has copied costs it no more Rss than
 * one it still shares.
 *
 * All three count resident pages only. On a host short of memory the kernel moves cold
 * pages — the parts of the application a worker never runs — to swap, and they drop out
 * of them while the pool still holds them; SwapPss is that share of swap, divided like
 * Pss. The long run judges Pss + SwapPss, which a page leaving for swap does not move. A
 * clean page of a file (php, a .so) is dropped rather than swapped, though, and leaves both:
 * under pressure the sum falls, and the heap and Private_Dirty + Swap say more.
 *
 * The long run (SOAK_SECONDS above 0) keeps CONCURRENCY requests on /work in flight for
 * that long — a request's worth of the application each (app.php), on a fresh connection
 * each so the kernel spreads them over every worker — and reads every SAMPLE_SECONDS. A
 * worker that touches a page the master loaded copies it, so preload's Pss creeps up
 * towards exec's as the run goes on; the trend and its slope after the first two minutes
 * say whether it levels off:
 *   SOAK_SECONDS=900 php -d extension=ext/build/sconcur.so tests/benchmarks/worker/memory.php
 *
 * Every read also asks the workers what PHP's heap holds (memory_get_usage() on /memory,
 * until each has answered): the pages of a process stay its own once touched, so Pss can
 * creep while nothing leaks, but a leak in PHP code is in the heap. A heap that keeps
 * still while Pss grows on points outside PHP — at the extension, or at pages merely
 * touched.
 *
 * OPCACHE=1 starts every php of the pool with opcache.enable_cli=1. ARMS=exec,preload
 * runs only those arms.
 */

declare(strict_types=1);

$root          = dirname(__DIR__, 3);
$extension     = (string) (getenv('SCONCUR_EXT') ?: $root . '/ext/build/sconcur.so');
$workers       = (int) (getenv('WORKERS') ?: 12);
$requests      = (int) (getenv('REQUESTS') ?: 5000);
$soakSeconds   = (int) (getenv('SOAK_SECONDS') ?: 0);
$sampleSeconds = max(1, (int) (getenv('SAMPLE_SECONDS') ?: 60));
$concurrency   = max(1, (int) (getenv('CONCURRENCY') ?: 32));
$opcache       = getenv('OPCACHE') === '1' ? '1' : '0';

$arms = ['exec', 'fork', 'preload'];

if (getenv('ARMS') !== false && getenv('ARMS') !== '') {
    $arms = array_values(array_intersect($arms, explode(',', (string) getenv('ARMS'))));
}

printf(
    "%d workers, %d requests, opcache.enable_cli=%s%s\n\n",
    $workers,
    $requests,
    $opcache,
    $soakSeconds > 0
        ? sprintf(', then %d s of %d requests in flight on /work, read every %d s', $soakSeconds, $concurrency, $sampleSeconds)
        : '',
);

printf(
    "%-10s %-7s %12s %12s %16s %12s %12s %12s %14s %14s %14s\n",
    'arm',
    'when',
    'Pss, sum',
    'Rss, sum',
    'Private_Dirty',
    'Pss/worker',
    'SwapPss, sum',
    'Swap, sum',
    'heap used, sum',
    'heap used, max',
    'heap real, sum',
);

foreach ($arms as $arm) {
    $runtimeDir = sys_get_temp_dir() . '/sc-memory-' . $arm . '-' . getmypid();

    @mkdir($runtimeDir, 0o775, true);

    $port = freePort();
    $php  = [PHP_BINARY, '-d', 'extension=' . $extension, '-d', 'opcache.enable_cli=' . $opcache];
    $out  = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $runtimeDir . '/out', 'a'], 2 => ['file', $runtimeDir . '/out', 'a']];

    /** @var list<resource> $processes */
    $processes = [];

    if ($arm === 'exec') {
        for ($worker = 0; $worker < $workers; ++$worker) {
            $processes[] = proc_open(
                [...$php, __DIR__ . '/app-worker.php', '--address=127.0.0.1:' . $port, '--reusePort=1'],
                $out,
                $pipes,
            );
        }

        $workerPids = array_map(static fn($process): int => (int) proc_get_status($process)['pid'], $processes);

        $pool = static fn(): array => [$workerPids, []];
    } else {
        $processes[] = proc_open(
            [...$php, __DIR__ . '/master.php', $runtimeDir, (string) $port, (string) $workers, ...($arm === 'preload' ? ['--preload'] : [])],
            $out,
            $pipes,
        );

        $masterPid = (int) proc_get_status($processes[0])['pid'];

        $pool = static fn(): array => [children($masterPid), [$masterPid]];
    }

    try {
        if (!waitForWorkers($pool, $workers, $port)) {
            fwrite(STDERR, "$arm: the workers did not come up: " . @file_get_contents($runtimeDir . '/out') . PHP_EOL);

            continue;
        }

        report($arm, 'idle', $pool, $workers, $port);

        for ($request = 0; $request < $requests; ++$request) {
            ask($port);
        }

        $served = report($arm, 'served', $pool, $workers, $port);

        if ($soakSeconds > 0) {
            soak(
                arm: $arm,
                port: $port,
                pool: $pool,
                workers: $workers,
                soakSeconds: $soakSeconds,
                sampleSeconds: $sampleSeconds,
                concurrency: $concurrency,
                served: $served,
            );
        }
    } finally {
        foreach ($processes as $process) {
            proc_terminate($process);
        }

        foreach ($processes as $process) {
            proc_close($process);
        }

        foreach (glob($runtimeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($runtimeDir);
    }
}

/**
 * Keeps $concurrency requests on /work in flight for $soakSeconds, reads the pool every
 * $sampleSeconds, and says how the Pss + SwapPss sum and the heap used sum moved: from the
 * read after the burst to the last, and their least-squares slopes after the first two
 * minutes (warm-up: the first requests of every worker copy the pages they touch at all).
 * The verdict, on Pss + SwapPss, uses the bound the HTTP soak uses, half a MiB a minute.
 *
 * @param Closure(): array{0: list<int>, 1: list<int>} $pool   the workers' pids and the master's
 * @param array{footprintKib: int, heapUsedKib: int}   $served
 */
function soak(
    string $arm,
    int $port,
    Closure $pool,
    int $workers,
    int $soakSeconds,
    int $sampleSeconds,
    int $concurrency,
    array $served,
): void {
    $multi = curl_multi_init();

    for ($handle = 0; $handle < $concurrency; ++$handle) {
        curl_multi_add_handle($multi, workHandle($port));
    }

    $startedAt    = microtime(true);
    $endsAt       = $startedAt + $soakSeconds;
    $nextSampleAt = $startedAt + $sampleSeconds;
    $answered     = 0;
    $failed       = 0;
    $trend        = [];
    $heapTrend    = [];

    while (true) {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.1);

        while (($done = curl_multi_info_read($multi)) !== false) {
            $handle = $done['handle'];

            $ok = $done['result'] === CURLE_OK && curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200;

            $ok ? ++$answered : ++$failed;

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);

            if (microtime(true) < $endsAt) {
                curl_multi_add_handle($multi, workHandle($port));
            }
        }

        $now = microtime(true);

        if ($now >= $nextSampleAt) {
            $elapsed = (int) round($now - $startedAt);

            $read = report($arm, 't=' . $elapsed . 's', $pool, $workers, $port);

            $trend[$elapsed]     = $read['footprintKib'];
            $heapTrend[$elapsed] = $read['heapUsedKib'];

            $nextSampleAt += $sampleSeconds;
        }

        if ($now >= $endsAt && $running === 0) {
            break;
        }
    }

    curl_multi_close($multi);

    $afterWarmUp = static fn(int $elapsed): bool => $elapsed >= 120;

    $last      = $trend === [] ? $served['footprintKib'] : (int) end($trend);
    $slope     = slopeMibPerMinute(array_filter($trend, $afterWarmUp, ARRAY_FILTER_USE_KEY));
    $heapLast  = $heapTrend === [] ? $served['heapUsedKib'] : (int) end($heapTrend);
    $heapSlope = slopeMibPerMinute(array_filter($heapTrend, $afterWarmUp, ARRAY_FILTER_USE_KEY));

    printf(
        "%-10s soak: %d answered, %d failed (%.0f rps); heap used sum %.2f -> %.2f MiB (%+.2f MiB), slope after 2 min %s;"
        . " Pss+SwapPss sum %.1f -> %.1f MiB (%+.1f MiB); slope after 2 min %s -> %s\n\n",
        $arm,
        $answered,
        $failed,
        $answered / max(1.0, $soakSeconds),
        $served['heapUsedKib'] / 1024,
        $heapLast / 1024,
        ($heapLast - $served['heapUsedKib']) / 1024,
        $heapSlope === null ? 'n/a' : sprintf('%+.3f MiB/min', $heapSlope),
        $served['footprintKib'] / 1024,
        $last / 1024,
        ($last - $served['footprintKib']) / 1024,
        $slope === null ? 'n/a' : sprintf('%+.2f MiB/min', $slope),
        match (true) {
            $slope === null => 'no verdict: run longer than two minutes and two samples',
            $slope > 0.5    => 'growing',
            $slope < -0.5   => 'falling',
            default         => 'stable',
        },
    );
}

/**
 * The least-squares slope of the samples, in MiB a minute; null for fewer than two.
 *
 * @param array<int, int> $samples a sum in KiB by elapsed seconds
 */
function slopeMibPerMinute(array $samples): ?float
{
    $count = count($samples);

    if ($count < 2) {
        return null;
    }

    $sumX  = array_sum(array_keys($samples));
    $sumY  = array_sum($samples) / 1024;
    $sumXY = 0.0;
    $sumXX = 0.0;

    foreach ($samples as $elapsed => $pssKib) {
        $sumXY += $elapsed * ($pssKib / 1024);
        $sumXX += $elapsed * $elapsed;
    }

    $denominator = $count * $sumXX - $sumX * $sumX;

    return $denominator == 0 ? null : ($count * $sumXY - $sumX * $sumY) / $denominator * 60;
}

/** A request on /work on a fresh connection, so the kernel spreads them over the workers. */
function workHandle(int $port): CurlHandle
{
    $curl = curl_init('http://127.0.0.1:' . $port . '/work');

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FORBID_REUSE   => true,
        CURLOPT_FRESH_CONNECT  => true,
        CURLOPT_TIMEOUT        => 5,
    ]);

    return $curl;
}

/**
 * Prints one read of the pool, and answers the workers' Pss + SwapPss sum and heap used sum.
 *
 * @param Closure(): array{0: list<int>, 1: list<int>} $pool the workers' pids and the master's
 *
 * @return array{footprintKib: int, heapUsedKib: int} in KiB
 */
function report(string $arm, string $when, Closure $pool, int $workers, int $port): array
{
    $sum = ['Pss' => 0, 'Rss' => 0, 'Private_Dirty' => 0, 'Swap' => 0, 'SwapPss' => 0];

    [$workerPids, $masterPids] = $pool();

    foreach ($workerPids as $pid) {
        foreach (rollup($pid) as $field => $kib) {
            $sum[$field] += $kib;
        }
    }

    $masterKib = 0;

    foreach ($masterPids as $pid) {
        $master = rollup($pid);

        $masterKib += $master['Pss'] + $master['SwapPss'];
    }

    $heaps = heapsOf($port, $workers);

    $heapUsedBytes = array_sum(array_column($heaps, 'used'));

    printf(
        "%-10s %-7s %9.1f MiB %9.1f MiB %13.1f MiB %9.1f MiB %9.1f MiB %9.1f MiB %10.2f MiB %10.2f MiB %10.1f MiB"
        . "   (%d of %d workers answered; master Pss+SwapPss %.1f MiB)\n",
        $arm,
        $when,
        $sum['Pss'] / 1024,
        $sum['Rss'] / 1024,
        $sum['Private_Dirty'] / 1024,
        $sum['Pss'] / 1024 / $workers,
        $sum['SwapPss'] / 1024,
        $sum['Swap'] / 1024,
        $heapUsedBytes / 1024 / 1024,
        ($heaps === [] ? 0 : max(array_column($heaps, 'used'))) / 1024 / 1024,
        array_sum(array_column($heaps, 'real')) / 1024 / 1024,
        count($heaps),
        $workers,
        $masterKib / 1024,
    );

    return ['footprintKib' => $sum['Pss'] + $sum['SwapPss'], 'heapUsedKib' => intdiv($heapUsedBytes, 1024)];
}

/**
 * What PHP's heap holds in every worker that answered /memory: asks on fresh connections,
 * which the kernel spreads over the workers, until each has answered or the attempts run
 * out. A worker that has not answered is left out, and the read says how many did.
 *
 * @return array<int, array{used: int, real: int}> in bytes, by worker pid
 */
function heapsOf(int $port, int $workers): array
{
    $heaps = [];

    for ($attempt = 0; $attempt < $workers * 50 && count($heaps) < $workers; ++$attempt) {
        $answer = explode(' ', ask($port, '/memory'));

        if (count($answer) === 3) {
            $heaps[(int) $answer[0]] = ['used' => (int) $answer[1], 'real' => (int) $answer[2]];
        }
    }

    return $heaps;
}

/**
 * @return array{Pss: int, Rss: int, Private_Dirty: int, Swap: int, SwapPss: int} in KiB
 */
function rollup(int $pid): array
{
    $values = ['Pss' => 0, 'Rss' => 0, 'Private_Dirty' => 0, 'Swap' => 0, 'SwapPss' => 0];

    foreach (explode("\n", (string) @file_get_contents('/proc/' . $pid . '/smaps_rollup')) as $line) {
        if (preg_match('/^(Pss|Rss|Private_Dirty|Swap|SwapPss):\s+(\d+) kB/', $line, $match) === 1) {
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

/**
 * @param Closure(): array{0: list<int>, 1: list<int>} $pool the workers' pids and the master's
 */
function waitForWorkers(Closure $pool, int $workers, int $port): bool
{
    $deadline = microtime(true) + 30.0;

    while (microtime(true) < $deadline) {
        if (count($pool()[0]) >= $workers && ask($port) !== '') {
            // Every worker has to be past its start, not only the first to answer.
            usleep(500_000);

            return true;
        }

        usleep(50_000);
    }

    return false;
}

function ask(int $port, string $path = '/'): string
{
    $curl = curl_init('http://127.0.0.1:' . $port . $path);

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
