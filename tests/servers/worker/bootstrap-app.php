<?php

/**
 * The bootstrap of a fork group in the tests: what an application loads once per
 * generation, in its group's fork server — its state, a log it opens, its error and exception
 * handlers, a scheduler built while loading. The workers (bootstrap-worker.php) are copies
 * of the fork server and reach it through $GLOBALS.
 *
 * What the application writes goes to SCONCUR_TEST_APP_DIR:
 *   app.log       what the workers write to the log opened here
 *   errors.log    every call of the error handler, as "<pid> <message>"
 *   uncaught.log  every call of the exception handler, as "<pid> <message>"
 *   version       read at every load: the version the workers report, "broken" to make
 *                 the load fail
 *
 * SCONCUR_TEST_APP_ASYNC=1 makes the loading do an asynchronous call, which leaves the
 * extension's runtime running in the fork server.
 */

declare(strict_types=1);

use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

$appDirectory = (string) getenv('SCONCUR_TEST_APP_DIR');

$version = is_file($appDirectory . '/version') ? trim((string) file_get_contents($appDirectory . '/version')) : 'v1';

if ($version === 'broken') {
    throw new RuntimeException('the application does not load');
}

$GLOBALS['app'] = [
    'loadedBy' => getmypid(),
    'version'  => $version,
    'log'      => fopen($appDirectory . '/app.log', 'a'),
];

// Called for everything, @-silenced warnings too, the way a handler that turns warnings
// into exceptions without asking error_reporting() would be.
set_error_handler(static function (int $level, string $message) use ($appDirectory): bool {
    file_put_contents($appDirectory . '/errors.log', getmypid() . ' ' . $message . PHP_EOL, FILE_APPEND);

    return true;
});

set_exception_handler(static function (Throwable $exception) use ($appDirectory): void {
    file_put_contents(
        $appDirectory . '/uncaught.log',
        getmypid() . ' ' . $exception->getMessage() . PHP_EOL,
        FILE_APPEND,
    );
});

// Built while loading, as a library that touches it on boot builds it.
Scheduler::get();

if (getenv('SCONCUR_TEST_APP_ASYNC') === '1') {
    Sleeper::usleep(microseconds: 1);
}
