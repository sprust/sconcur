<?php

/**
 * Binds one listening socket and runs a command that inherits it, the way a worker
 * master with `listen` hands its socket to the workers it execs. load-stats.sh runs its
 * spawn loop through this with SHARED_SOCKET=1, so the workers accept from one queue
 * instead of a SO_REUSEPORT group each, and everything else about the run stays the same.
 *
 * The command gets the descriptor's number in SCONCUR_LISTEN_FD; it exits once the
 * command has, and the socket lives on in the workers the command started.
 *
 * Usage: php -d extension=… shared-socket.php <address> <command> [<argument> …]
 */

declare(strict_types=1);

use SConcur\Process\ProcessControl;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if ($argc < 3) {
    fwrite(STDERR, 'usage: shared-socket.php <address> <command> [<argument> ...]' . PHP_EOL);

    exit(1);
}

$descriptor = ProcessControl::bind($argv[1]);

// The socket is close-on-exec; a descriptor in the spec is placed under its own number
// without the flag, which is how an exec'd worker of the master gets it too.
$process = proc_open(
    command: array_slice($argv, 2),
    descriptor_spec: [
        0           => STDIN,
        1           => STDOUT,
        2           => STDERR,
        $descriptor => fopen('php://fd/' . $descriptor, 'r'),
    ],
    pipes: $pipes,
    env_vars: [...getenv(), 'SCONCUR_LISTEN_FD' => (string) $descriptor],
);

if ($process === false) {
    fwrite(STDERR, 'could not run ' . $argv[2] . PHP_EOL);

    exit(1);
}

exit(proc_close($process));
