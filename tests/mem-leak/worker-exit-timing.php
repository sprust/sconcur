<?php

/**
 * How a worker dies of exit() in a request, step by step: runs worker-soak.php's exit
 * scenario with its workers noting, in a trace file, when /exit calls exit() and when their
 * shutdown functions start and end (FAILURES_EXIT_TRACE), and notes itself when each of those
 * workers turns into a zombie or is gone, polling /proc every 0.2 ms.
 *
 * Reports the soak's verdict — the requests the load lost per death — and the median, 90th
 * percentile and worst of each step:
 *
 *   exit -> shutdown       exit() to the worker's first shutdown function
 *   exit -> shutdown-end   exit() to the end of its shutdown functions
 *   shutdown-end -> dead   what PHP's shutdown does after them: destructors, the extension's
 *                          teardown, the modules
 *   exit -> dead           the whole death: the window in which the worker's runtime may
 *                          still accept connections it will never answer
 *
 * Two launches side by side tell a step one of them skips from one it is slow at: a death
 * as quick as the other's that loses more requests skipped something (on the fork server
 * design, a forked worker that skipped the extension's teardown died in 5 ms and lost 6.6
 * requests a death, an exec one in 3 ms and 0.3). LAUNCH as in worker-soak.php. Run in the
 * php container:
 *   LAUNCH=preload php -d extension=ext/build/sconcur.so tests/mem-leak/worker-exit-timing.php [seconds]
 */

declare(strict_types=1);

$seconds = (int) ($_SERVER['argv'][1] ?? 30);
$trace   = sys_get_temp_dir() . '/sconcur-exit-trace-' . getmypid() . '.log';

$soak = proc_open(
    [PHP_BINARY, ...extensionArgs(), __DIR__ . '/worker-soak.php', 'exit', (string) $seconds],
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
    $pipes,
    null,
    [
        ...getenv(),
        'FAILURES_EXIT_TRACE' => $trace,
        'WARMUP_SECONDS'      => getenv('WARMUP_SECONDS') ?: '10',
        'SAMPLE_SECONDS'      => getenv('SAMPLE_SECONDS') ?: '10',
    ],
);

stream_set_blocking($pipes[1], false);

$output = '';
$offset = 0;
$alive  = [];
$deaths = [];

while (proc_get_status($soak)['running']) {
    $output .= (string) stream_get_contents($pipes[1]);

    clearstatcache(false, $trace);

    $size = is_file($trace) ? (int) filesize($trace) : 0;

    if ($size > $offset) {
        $handle = fopen($trace, 'r');

        fseek($handle, $offset);

        while (($line = fgets($handle)) !== false) {
            [$pid, $event] = explode(' ', trim($line));

            if ($event === 'exit') {
                $alive[(int) $pid] = true;
            }
        }

        $offset = (int) ftell($handle);

        fclose($handle);
    }

    foreach (array_keys($alive) as $pid) {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');

        if ($stat === false || explode(' ', substr($stat, (int) strrpos($stat, ')') + 2))[0] === 'Z') {
            $deaths[$pid] = hrtime(true) / 1e6;

            unset($alive[$pid]);
        }
    }

    usleep(200);
}

$output .= (string) stream_get_contents($pipes[1]);

proc_close($soak);

$events = [];

foreach (is_file($trace) ? (array) file($trace, FILE_IGNORE_NEW_LINES) : [] as $line) {
    [$pid, $event, $atMs] = explode(' ', (string) $line);

    $events[(int) $pid][$event] = (float) $atMs;
}

@unlink($trace);

foreach ($deaths as $pid => $atMs) {
    $events[$pid]['dead'] = $atMs;
}

$steps = [
    'exit -> shutdown'     => ['exit', 'shutdown'],
    'exit -> shutdown-end' => ['exit', 'shutdown-end'],
    'shutdown-end -> dead' => ['shutdown-end', 'dead'],
    'exit -> dead'         => ['exit', 'dead'],
];

foreach (explode("\n", $output) as $line) {
    if (preg_match('~^\S+ exit +(ok|SUSPECT|no verdict|did not)~', $line) === 1) {
        echo $line, PHP_EOL;
    }
}

printf("%d workers exited, %d seen dead\n", count(array_filter($events, static fn(array $worker): bool => isset($worker['exit']))), count($deaths));

foreach ($steps as $label => [$from, $to]) {
    $durationsMs = [];

    foreach ($events as $worker) {
        if (isset($worker[$from], $worker[$to])) {
            $durationsMs[] = $worker[$to] - $worker[$from];
        }
    }

    if ($durationsMs === []) {
        printf("%-22s no reads\n", $label);

        continue;
    }

    sort($durationsMs);

    printf(
        "%-22s median %6.2f ms  p90 %6.2f ms  max %6.2f ms  (%d)\n",
        $label,
        $durationsMs[intdiv(count($durationsMs), 2)],
        $durationsMs[(int) floor(count($durationsMs) * 0.9)],
        end($durationsMs),
        count($durationsMs),
    );
}

/**
 * The -d extension=... this php was started with, for the soak it starts.
 *
 * @return list<string>
 */
function extensionArgs(): array
{
    $extension = (string) (getenv('SCONCUR_EXT') ?: dirname(__DIR__, 2) . '/ext/build/sconcur.so');

    return extension_loaded('sconcur') ? ['-d', 'extension=' . $extension] : [];
}
