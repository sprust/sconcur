<?php

declare(strict_types=1);

namespace SConcur\Worker;

use Closure;
use SConcur\Connection\Extension;
use SConcur\Process\ProcessControl;
use SConcur\Scheduler\Scheduler;
use Throwable;

/**
 * The child half of a forked worker: turns the fresh copy of its parent — the master — into
 * the worker the group describes, runs the group's role and leaves.
 *
 * It never returns. The parent's call stack is still below it, and an exception that
 * reached those frames would run the parent's finally blocks and its supervision loop in
 * the worker. So the role runs inside a catch-all, and the worker leaves through
 * ProcessControl::exit() however it ends. An exit() inside the role leaves through
 * PHP's own shutdown instead, which skips finally blocks and is safe.
 *
 * The worker runs under the error and exception handlers of the application the process
 * loaded before it started the master: the master keeps its error handler out of its loop
 * (WorkerMaster::run), and it is given back here.
 */
class ForkedWorker
{
    /**
     * @param string                       $title       what `ps` shows for the worker
     * @param string                       $cwd         the directory the worker runs in
     * @param array<string, string>        $environment what the worker's environment adds to
     *                                                  the parent's
     * @param Closure(WorkerContext): void $role        what the worker runs; it leaves when
     *                                                  this returns
     */
    public static function run(
        string $title,
        string $cwd,
        array $environment,
        Closure $role,
        WorkerContext $context,
    ): never {
        $exitCode = 255;

        try {
            static::prepare(
                title: $title,
                cwd: $cwd,
                environment: $environment,
            );

            WorkerContext::enter($context);

            $role($context);

            $exitCode = 0;
        } catch (Throwable $exception) {
            static::reportUncaught($exception);
        }

        ProcessControl::exit($exitCode);
    }

    /**
     * What PHP does with an exception nothing caught: hands it to the exception handler if
     * one is set — an application's reporter, installed while it loaded — and otherwise
     * prints it, in the words PHP itself uses, on stderr, so the master logs it at ERROR
     * the way it logs an exec worker's.
     */
    protected static function reportUncaught(Throwable $exception): void
    {
        $handler = set_exception_handler(null);

        if ($handler !== null) {
            try {
                $handler($exception);

                return;
            } catch (Throwable $thrown) {
                $exception = $thrown;
            }
        }

        fwrite(STDERR, 'PHP Fatal error:  Uncaught ' . $exception . PHP_EOL);
    }

    /**
     * @param array<string, string> $environment
     */
    protected static function prepare(
        string $title,
        string $cwd,
        array $environment,
    ): void {
        // Without it every worker shows in `ps` as the master's own command line.
        @cli_set_process_title($title);

        chdir($cwd);

        // putenv for getenv() and whatever the worker starts; $_ENV and $_SERVER because a
        // php started with them would have found the same values there.
        foreach ($environment as $name => $value) {
            putenv($name . '=' . $value);

            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }

        // A worker dying on startup explains itself in the journal, at ERROR, whatever
        // php.ini says.
        ini_set('display_errors', 'stderr');

        // What the master's process knew of supervision is not this worker's: the fork
        // handed the worker a heartbeat of its own, and its scheduler asks for it afresh.
        Scheduler::forgetSupervision();

        // The master's handle on the extension is marked destroyed here, and an exit()
        // in the role would leave without the teardown its destructor does: the worker
        // gets one of its own.
        Extension::forgetInherited();

        // Last, so what is silenced above stays out of it: the master's own handler, which
        // WorkerMaster::run() put over the application's, comes off.
        restore_error_handler();
    }
}
