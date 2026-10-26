<?php

/**
 * A worker of app-master.php: reports what it found of the application its master loaded —
 * the token, and whether it and a program it starts hold the application's log file —
 * then serves as the demo HTTP server does.
 *
 * The report is one JSON line appended to SCONCUR_TEST_APP_REPORT.
 */

declare(strict_types=1);

$testAppLog = (string) realpath((string) getenv('SCONCUR_TEST_APP_LOG'));

$testAppListing = <<<'LISTING'
$targets = [];

foreach (scandir('/proc/self/fd') as $entry) {
    if (ctype_digit($entry)) {
        $targets[] = (string) @readlink('/proc/self/fd/' . $entry);
    }
}

echo json_encode($targets);
LISTING;

$testAppOwn = [];

foreach (scandir('/proc/self/fd') as $entry) {
    if (ctype_digit($entry)) {
        $testAppOwn[] = (string) @readlink('/proc/self/fd/' . $entry);
    }
}

$testAppStarted = (array) json_decode(
    (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($testAppListing)),
    true,
);

file_put_contents(
    (string) getenv('SCONCUR_TEST_APP_REPORT'),
    json_encode([
        'pid'             => getmypid(),
        'token'           => $GLOBALS['testApp']['token'] ?? null,
        'holdsLog'        => in_array($testAppLog, $testAppOwn, true),
        'startedHoldsLog' => in_array($testAppLog, $testAppStarted, true),
    ]) . "\n",
    FILE_APPEND,
);

require dirname(__DIR__) . '/http/http-server.php';
