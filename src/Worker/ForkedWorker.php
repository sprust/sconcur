<?php

declare(strict_types=1);

namespace SConcur\Worker;

use SConcur\Process\Dto\ForkResult;
use SConcur\Process\ProcessControl;
use Throwable;

/**
 * The child half of a forked worker: turns the fresh copy of its parent — the fork server of
 * its group — into the worker the group describes, runs the worker script and leaves.
 *
 * It never returns. The parent's call stack is still below it, and an exception that
 * reached those frames would run the parent's finally blocks and its supervision loop in
 * the worker. So the script runs inside a catch-all, and the worker leaves through
 * ProcessControl::exit() however it ends. An exit() inside the script leaves through
 * PHP's own shutdown instead, which skips finally blocks and is safe.
 *
 * The worker runs under the error and exception handlers of the application the fork server
 * loaded (its bootstrap), as a php started on the script would run under its own: the
 * fork server keeps its error handler out of its loop (ForkServer::run), and it is given back here.
 */
class ForkedWorker
{
    /**
     * @param string                $title       what `ps` shows for the worker
     * @param string                $cwd         the directory the worker runs in
     * @param array<string, string> $environment what the worker's environment adds to the
     *                                           parent's
     * @param list<string>          $argv        the argv the script sees, its own path first
     */
    public static function run(
        ForkResult $fork,
        string $title,
        string $cwd,
        array $environment,
        array $argv,
    ): never {
        $exitCode = 255;

        try {
            static::prepare(
                fork: $fork,
                title: $title,
                cwd: $cwd,
                environment: $environment,
            );

            static::runScript($argv);

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
        ForkResult $fork,
        string $title,
        string $cwd,
        array $environment,
    ): void {
        // Without it every worker shows in `ps` as the master's own command line.
        @cli_set_process_title($title);

        chdir($cwd);

        // putenv for getenv() and whatever the worker starts; $_ENV and $_SERVER because a
        // php started on the script would have found the same values there.
        foreach ($environment as $name => $value) {
            putenv($name . '=' . $value);

            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }

        // What an exec worker gets as `-d display_errors=stderr`: a worker dying on
        // startup explains itself in the journal, at ERROR, whatever php.ini says. The
        // group's own -d flags are the fork server's already, which was started with them.
        ini_set('display_errors', 'stderr');

        // Also without one: what the master's process knew of a heartbeat — none, or one of
        // its own master's — is not this worker's.
        Heartbeat::adopt($fork->heartbeat);

        // Last, so what is silenced above stays out of it: the fork server's own handler, which
        // ForkServer::run() put over the application's, comes off.
        restore_error_handler();
    }

    /**
     * Runs the script with the argv a php started on it would have had. It is required
     * from here, so it runs in this function's scope rather than the global one: its
     * top-level variables are locals of this call, and $argv and $argc are set as both.
     *
     * @param list<string> $argv
     */
    protected static function runScript(array $argv): void
    {
        $argc = count($argv);

        $_SERVER['argv']            = $argv;
        $_SERVER['argc']            = $argc;
        $_SERVER['SCRIPT_FILENAME'] = $argv[0];
        $_SERVER['SCRIPT_NAME']     = $argv[0];
        $_SERVER['PHP_SELF']        = $argv[0];

        $GLOBALS['argv'] = $argv;
        $GLOBALS['argc'] = $argc;

        require $argv[0];
    }
}
