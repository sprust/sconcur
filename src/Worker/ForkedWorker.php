<?php

declare(strict_types=1);

namespace SConcur\Worker;

use SConcur\Process\Dto\ForkResult;
use SConcur\Process\ProcessControl;
use Throwable;

/**
 * The child half of a forked worker: turns the fresh copy of the master into the worker
 * its group describes, runs the worker script and leaves.
 *
 * It never returns. The master's call stack is still below it, and an exception that
 * reached those frames would run the master's finally blocks in the worker — the one in
 * WorkerMaster::run() removes the state file, which is the signal that stops the whole
 * pool. So the script runs inside a catch-all, and the worker leaves through
 * ProcessControl::exit() however it ends. An exit() inside the script leaves through
 * PHP's own shutdown instead, which skips finally blocks and is safe.
 */
class ForkedWorker
{
    /**
     * @param string                            $title       what `ps` shows for the worker
     * @param string                            $cwd         the directory the worker runs in
     * @param array<string, string>             $environment what the worker's environment adds to the
     *                                                       master's
     * @param list<array{0: string, 1: string}> $iniSettings the group's -d flags, applied
     *                                                       after display_errors=stderr so a
     *                                                       later one wins
     * @param list<string>                      $argv        the argv the script sees, its own path first
     */
    public static function run(
        ForkResult $fork,
        string $title,
        string $cwd,
        array $environment,
        array $iniSettings,
        array $argv,
    ): never {
        $exitCode = 255;

        try {
            static::prepare(
                fork: $fork,
                title: $title,
                cwd: $cwd,
                environment: $environment,
                iniSettings: $iniSettings,
            );

            static::runScript($argv);

            $exitCode = 0;
        } catch (Throwable $exception) {
            // The words PHP itself uses for an uncaught exception, on stderr, so the master
            // logs it at ERROR the way it logs an exec worker's.
            fwrite(STDERR, 'PHP Fatal error:  Uncaught ' . $exception . PHP_EOL);
        }

        ProcessControl::exit($exitCode);
    }

    /**
     * @param array<string, string>             $environment
     * @param list<array{0: string, 1: string}> $iniSettings
     */
    protected static function prepare(
        ForkResult $fork,
        string $title,
        string $cwd,
        array $environment,
        array $iniSettings,
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
        // startup explains itself in the journal, at ERROR, whatever php.ini says.
        ini_set('display_errors', 'stderr');

        foreach ($iniSettings as [$directive, $value]) {
            // Loaded in the master already — WorkerMaster::assertLaunchable refuses the
            // group otherwise — and not something ini_set can do.
            if ($directive === 'extension' || $directive === 'zend_extension') {
                continue;
            }

            ini_set($directive, $value);
        }

        if ($fork->heartbeat !== null) {
            Heartbeat::adopt($fork->heartbeat);
        }
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
