<?php

declare(strict_types=1);

/**
 * Does the core survive pcntl_fork?
 *
 * The Go build answers no, and the reason is structural: its runtime starts
 * inside dlopen, so threads exist before PHP's MINIT ever runs and a child gets
 * a runtime whose workers did not come across. That is what puts "no
 * pcntl_fork after the extension is loaded" — and with it FPM and mod_php — in
 * the README.
 *
 * Three scenarios, each a separate process because the first one's precondition
 * is destroyed by using the extension at all:
 *
 *   before   — fork before the extension is ever used (the FPM shape: the
 *              module is loaded in the master, the work happens in children)
 *   after    — the parent runs a fan-out first, THEN forks; the child inherits
 *              a runtime with no live threads behind it and must rebuild
 *   parallel — one parent, four children working at the same time
 *
 * Every scenario asserts real concurrency, not just "did not crash": twelve
 * coroutines of 100ms must finish in about one sleep.
 *
 * Run inside the `php` container:
 *   php -d extension=/sconcur/ext-rust/build/sconcur.so ext-rust/check/fork-check.php <scenario>
 */

use SConcur\Features\Sleeper\Sleeper;
use SConcur\WaitGroup;

require __DIR__ . '/../../vendor/autoload.php';

const COROUTINES   = 12;
const SLEEP_US     = 100_000;
const CONCURRENT_MS = 600;

/**
 * A fan-out that only passes if the coroutines really ran at the same time.
 * Returns the elapsed milliseconds, or throws.
 */
function fanOut(string $who): float
{
    $start     = microtime(true);
    $waitGroup = WaitGroup::create();

    for ($index = 0; $index < COROUTINES; ++$index) {
        $waitGroup->add(static function () use ($index): int {
            Sleeper::usleep(microseconds: SLEEP_US);

            return $index;
        });
    }

    $collected = [];

    foreach ($waitGroup->iterate() as $value) {
        $collected[] = $value;
    }

    $elapsedMs = (microtime(true) - $start) * 1000;

    sort($collected);

    if ($collected !== range(0, COROUTINES - 1)) {
        throw new RuntimeException(sprintf('%s: wrong results: %s', $who, implode(',', $collected)));
    }

    if ($elapsedMs > CONCURRENT_MS) {
        throw new RuntimeException(sprintf('%s: not concurrent: %.1f ms', $who, $elapsedMs));
    }

    return $elapsedMs;
}

/** Runs a fan-out in a forked child and returns its exit code. */
function forkChild(string $who): int
{
    $pid = pcntl_fork();

    if ($pid === -1) {
        fwrite(STDERR, 'fork failed' . PHP_EOL);

        exit(1);
    }

    if ($pid === 0) {
        try {
            $elapsedMs = fanOut($who);

            printf("  %-24s OK  (%.1f ms, pid %d)%s", $who, $elapsedMs, getmypid(), PHP_EOL);

            exit(0);
        } catch (Throwable $exception) {
            printf("  %-24s FAIL  (%s)%s", $who, $exception->getMessage(), PHP_EOL);

            exit(1);
        }
    }

    return $pid;
}

$scenario = $argv[1] ?? '';
$failures = 0;

switch ($scenario) {
    case 'before':
        // The extension is loaded but untouched: nothing has been started yet.
        $pid = forkChild('child (fork before use)');

        pcntl_waitpid($pid, $status);

        $failures += pcntl_wexitstatus($status) === 0 ? 0 : 1;

        try {
            $elapsedMs = fanOut('parent (after fork)');

            printf("  %-24s OK  (%.1f ms, pid %d)%s", 'parent (after fork)', $elapsedMs, getmypid(), PHP_EOL);
        } catch (Throwable $exception) {
            printf("  %-24s FAIL  (%s)%s", 'parent (after fork)', $exception->getMessage(), PHP_EOL);

            ++$failures;
        }

        break;

    case 'after':
        // The runtime is up and its worker threads are running before the fork;
        // none of them exists in the child.
        $elapsedMs = fanOut('parent (before fork)');

        printf("  %-24s OK  (%.1f ms, pid %d)%s", 'parent (before fork)', $elapsedMs, getmypid(), PHP_EOL);

        $pid = forkChild('child (fork after use)');

        pcntl_waitpid($pid, $status);

        $failures += pcntl_wexitstatus($status) === 0 ? 0 : 1;

        try {
            $elapsedMs = fanOut('parent (still alive)');

            printf("  %-24s OK  (%.1f ms, pid %d)%s", 'parent (still alive)', $elapsedMs, getmypid(), PHP_EOL);
        } catch (Throwable $exception) {
            printf("  %-24s FAIL  (%s)%s", 'parent (still alive)', $exception->getMessage(), PHP_EOL);

            ++$failures;
        }

        break;

    case 'parallel':
        $pids = [];

        for ($index = 0; $index < 4; ++$index) {
            $pids[] = forkChild(sprintf('child %d of 4', $index + 1));
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);

            $failures += pcntl_wexitstatus($status) === 0 ? 0 : 1;
        }

        break;

    default:
        fwrite(STDERR, "usage: fork-check.php <before|after|parallel>" . PHP_EOL);

        exit(2);
}

exit($failures === 0 ? 0 : 1);
