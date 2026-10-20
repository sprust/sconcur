<?php

/**
 * Starts a pool of demo servers the way a worker master with `launch: fork` starts its
 * workers — each one a fork of a php that loaded the library and the extension, turned
 * into the worker by ForkedWorker::run() — and leaves them running. load-stats.sh runs
 * it with LAUNCH=fork, so the two launches are compared with everything else equal:
 * the same placement, the same ports, and the output going where the exec'd workers'
 * goes rather than through a master's journal.
 *
 * A worker pinned to its own CPUs gets them by inheritance: this process sets its own
 * affinity with `taskset -p` before each fork, which a single-threaded process can do
 * to itself, and the child starts with that mask before any of its threads exist.
 *
 * <specfile> holds a line per worker, "<cpulist>;<argument> <argument> …": the CPUs to
 * pin it to (empty for none) and its argv after the script. The pids are written to
 * <pidfile>, one per line, as load-stats.sh writes them for the workers it starts
 * itself. A listening socket named in SCONCUR_LISTEN_FD (SHARED_SOCKET=1) is kept open
 * in every worker.
 *
 * Usage: php -d extension=… fork-pool.php <pidfile> <script> <specfile>
 */

declare(strict_types=1);

use SConcur\Process\ProcessControl;
use SConcur\Worker\ForkedWorker;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if ($argc !== 4) {
    fwrite(STDERR, 'usage: fork-pool.php <pidfile> <script> <specfile>' . PHP_EOL);

    exit(1);
}

$pidFile = $argv[1];
$script  = $argv[2];
$specs   = file($argv[3], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if ($specs === false || $specs === []) {
    fwrite(STDERR, 'fork-pool.php: no workers in ' . $argv[3] . PHP_EOL);

    exit(1);
}

$listenFd        = getenv('SCONCUR_LISTEN_FD');
$keepDescriptors = is_string($listenFd) && ctype_digit($listenFd) ? [(int) $listenFd] : [];

$port = '';

foreach ($specs as $index => $spec) {
    [$cpuList, $arguments] = array_pad(explode(';', $spec, 2), 2, '');

    $workerArgv = [
        $script,
        ...array_values(array_filter(
            explode(' ', $arguments),
            static fn(string $argument): bool => $argument !== '',
        )),
    ];

    foreach ($workerArgv as $argument) {
        if (str_starts_with($argument, '--address=')) {
            $port = substr($argument, (int) strrpos($argument, ':') + 1);
        }
    }

    if ($cpuList !== '') {
        $output = [];

        exec(sprintf('taskset -pc %s %d 2>&1', escapeshellarg($cpuList), getmypid()), $output, $status);

        if ($status !== 0) {
            fwrite(STDERR, sprintf('could not pin to %s: %s%s', $cpuList, implode(' ', $output), PHP_EOL));

            exit(1);
        }
    }

    $fork = ProcessControl::fork(
        keepDescriptors: $keepDescriptors,
        captureOutput: false,
    );

    if ($fork->isChild()) {
        // The port in the title is what load-stats.sh finds a stray worker of an earlier
        // run by: the title replaces the command line it would otherwise match.
        ForkedWorker::run(
            fork: $fork,
            title: sprintf('sconcur-load :%s #%d', $port, $index),
            cwd: (string) getcwd(),
            environment: [],
            argv: $workerArgv,
        );
    }

    file_put_contents($pidFile, $fork->pid . PHP_EOL, FILE_APPEND);
}
