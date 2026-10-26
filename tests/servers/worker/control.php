<?php

/**
 * Asks a test master how it is, or stops it, through MasterControl.
 *
 * Usage: php tests/servers/worker/control.php <status|stop> <config.json>
 *
 * status prints "stopped" and exits 3 when no master runs; otherwise "running", the pid and
 * the groups from the state file, and exits 0. stop prints "stopped" (or "not running")
 * and exits 0 once the master is gone, or exits 1 at the deadline.
 */

declare(strict_types=1);

use SConcur\Worker\MasterControl;
use SConcur\Worker\WorkerMaster;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$command = (string) ($argv[1] ?? '');
$config  = json_decode((string) file_get_contents((string) ($argv[2] ?? '')), true, flags: JSON_THROW_ON_ERROR);

$control = new MasterControl(
    runtimeDir: (string) $config['runtimeDir'],
    name: (string) ($config['name'] ?? WorkerMaster::DEFAULT_NAME),
);

if ($command === 'status') {
    if (!$control->isRunning()) {
        echo 'stopped' . PHP_EOL;

        exit(3);
    }

    $state = $control->state();

    if ($state === null) {
        echo 'running' . PHP_EOL;

        exit(0);
    }

    printf('running: pid=%d workers=%d groups=%d' . PHP_EOL, $state->pid, $state->workerCount, count($state->groups));

    foreach ($state->groups as $group) {
        printf('  %s: workers=%d' . PHP_EOL, $group->name, $group->workerCount);
    }

    exit(0);
}

if ($command === 'stop') {
    $wasRunning = $control->isRunning();

    if (!$control->stop()) {
        fwrite(STDERR, 'stop timeout; master still running' . PHP_EOL);

        exit(1);
    }

    echo ($wasRunning ? 'stopped' : 'not running') . PHP_EOL;

    exit(0);
}

fwrite(STDERR, 'usage: control.php <status|stop> <config.json>' . PHP_EOL);

exit(2);
