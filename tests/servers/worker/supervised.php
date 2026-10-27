<?php

/**
 * Runs PHP code the way a worker master runs a worker: in a fork of this process, with the
 * heartbeat pipe a fork made for it (unless told not to). Prints what the code printed and
 * the marks it put on the pipe, as JSON: {"output": ..., "marks": hex}.
 *
 * Usage: php -d extension=sconcur.so tests/servers/worker/supervised.php <code file> <0|1>
 *        [arguments...]
 *
 * The code runs as `php -r` would run it — no opening tag — with $argv the code file and
 * the arguments. Its stdout comes first in "output", then its stderr.
 */

declare(strict_types=1);

use SConcur\Process\ProcessControl;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$supervisedCodeFile  = (string) $argv[1];
$supervisedHeartbeat = ($argv[2] ?? '1') === '1';
$supervisedArguments = array_slice($argv, 3);

$fork = ProcessControl::fork(
    captureOutput: true,
    heartbeat: $supervisedHeartbeat,
);

if ($fork->isChild()) {
    $argv = [$supervisedCodeFile, ...$supervisedArguments];
    $argc = count($argv);

    $_SERVER['argv'] = $argv;
    $_SERVER['argc'] = $argc;

    try {
        eval((string) file_get_contents($supervisedCodeFile));
    } catch (Throwable $exception) {
        fwrite(STDERR, 'PHP Fatal error:  Uncaught ' . $exception . PHP_EOL);

        ProcessControl::exit(255);
    }

    ProcessControl::exit(0);
}

$streams = array_filter([
    'stdout'    => $fork->stdout,
    'stderr'    => $fork->stderr,
    'heartbeat' => $fork->heartbeat,
]);

$read = array_fill_keys(array_keys($streams), '');

// Read as it comes: a pipe left unread fills up and stops the child.
while ($streams !== []) {
    $ready  = array_values($streams);
    $write  = null;
    $except = null;

    if (@stream_select($ready, $write, $except, 0, 50_000) === false) {
        break;
    }

    foreach ($streams as $name => $stream) {
        $chunk = (string) fread($stream, 65536);

        $read[$name] .= $chunk;

        if ($chunk === '' && feof($stream)) {
            fclose($stream);

            unset($streams[$name]);
        }
    }
}

while (ProcessControl::reap([$fork->pid]) === []) {
    usleep(10_000);
}

echo json_encode([
    'output' => $read['stdout'] . $read['stderr'],
    'marks'  => bin2hex($read['heartbeat'] ?? ''),
]);
