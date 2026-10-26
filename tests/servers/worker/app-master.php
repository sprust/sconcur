<?php

/**
 * Stands in for an application loaded before its master: it records that it loaded, keeps
 * a token in memory and opens a log file, then runs the test master (master.php) in the
 * same process. Its workers are forks of it, so they find all three without loading
 * anything again (app-worker.php).
 *
 * Usage: php -d extension=sconcur.so tests/servers/worker/app-master.php <config.json>, with
 * SCONCUR_TEST_APP_LOADS and SCONCUR_TEST_APP_LOG naming the files it writes.
 */

declare(strict_types=1);

file_put_contents((string) getenv('SCONCUR_TEST_APP_LOADS'), getmypid() . "\n", FILE_APPEND);

$GLOBALS['testApp'] = [
    'token' => bin2hex(random_bytes(8)),
    'log'   => fopen((string) getenv('SCONCUR_TEST_APP_LOG'), 'a'),
];

require __DIR__ . '/master.php';
