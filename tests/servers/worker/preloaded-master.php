<?php

/**
 * A master started by an application rather than by bin/sconcur-server: the application
 * loads first — keeps its state, opens its log, installs its error and exception handlers,
 * builds a scheduler — and only then hands its argv to the master CLI. The workers of its
 * fork groups start from that copy of it (preloaded-worker.php).
 *
 * What the application writes goes to SCONCUR_TEST_APP_DIR:
 *   app.log       what the workers write to the log opened here
 *   errors.log    every call of the error handler, as "<pid> <message>"
 *   uncaught.log  every call of the exception handler, as "<pid> <message>"
 *
 * SCONCUR_TEST_APP_ASYNC=1 makes the loading do an asynchronous call, which leaves the
 * extension's runtime running in the master.
 */

declare(strict_types=1);

use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\Worker\MasterCli;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$appDirectory = (string) getenv('SCONCUR_TEST_APP_DIR');

$GLOBALS['app'] = [
    'loadedBy' => getmypid(),
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

exit(new MasterCli()->run($argv));
