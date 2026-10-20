<?php

/**
 * A worker that serves as tests/servers/http/http-server.php does, unless its slot is held:
 * when the file hold-<slot> is in SCONCUR_TEST_HOLD_DIR as it starts, it runs without ever
 * saying it is up, until it is stopped or the fork server it was forked from is gone. A test
 * holds the slots a reload must not be able to bring up, and so decides where the reload
 * stops.
 *
 * The slot is read from SCONCUR_SERVER_NAME, which the master sets to "<group>:<slot>".
 */

declare(strict_types=1);

$serverName = (string) getenv('SCONCUR_SERVER_NAME');
$slot       = (int) substr($serverName, (int) strrpos($serverName, ':') + 1);

if (!is_file(getenv('SCONCUR_TEST_HOLD_DIR') . '/hold-' . $slot)) {
    require dirname(__DIR__) . '/http/http-server.php';

    return;
}

// Bounded, so a worker nobody stops does not outlive the test by more than that.
$parentPid  = posix_getppid();
$deadlineNs = hrtime(true) + 30 * 1_000_000_000;

while (posix_getppid() === $parentPid && hrtime(true) < $deadlineNs) {
    usleep(50_000);
}
