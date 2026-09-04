<?php

declare(strict_types=1);

/**
 * Runs another script under excimer's sampling profiler and prints where its PHP
 * time went.
 *
 * The companion of `perf`, not a replacement: perf names the C function the CPU
 * was inside, this names the PHP function that was on the stack while it was.
 * For the question both profiling plans stalled on — where the ~30 us of PSR-7
 * construction and the ~6.3 us residue of the coordination cycle actually go —
 * the PHP-level answer is usually the readable one, and the C-level answer says
 * whether the cost is in the engine or in the extension.
 *
 * Only available in the profiling image, which is also the only image excimer is
 * installed in. See `make profile-php`.
 *
 * Usage: php -d extension=excimer.so lib/excimer-profile.php <script> [args...]
 */

use ExcimerProfiler;

const SAMPLE_PERIOD_SECONDS = 0.0001; // 10 kHz, fine enough for microsecond stages
const ROWS = 40;

if (!extension_loaded('excimer')) {
    fwrite(STDERR, "excimer is not loaded; run this through `make profile-php`\n");

    exit(1);
}

$arguments = array_slice($argv, 1);
$script    = array_shift($arguments);

if ($script === null || !is_file($script)) {
    fwrite(STDERR, "usage: excimer-profile.php <script> [args...]\n");

    exit(1);
}

$profiler = new ExcimerProfiler();
$profiler->setPeriod(SAMPLE_PERIOD_SECONDS);
$profiler->setEventType(EXCIMER_CPU);
$profiler->start();

// The profiled script sees the argv it would have seen on its own.
$argv = array_merge([$script], $arguments);
$argc = count($argv);

require $script;

$profiler->stop();

$log = $profiler->getLog();

fwrite(STDERR, PHP_EOL . str_repeat('-', 72) . PHP_EOL);
fwrite(STDERR, 'excimer: ' . $log->getEventCount() . ' samples at '
    . (int) (1 / SAMPLE_PERIOD_SECONDS) . ' Hz' . PHP_EOL . PHP_EOL);

// Self time per function: which frame the sample was actually in, not which
// frames were merely on the stack below it.
$counts = [];

foreach ($log as $entry) {
    $trace = $entry->getTrace();

    if ($trace === []) {
        continue;
    }

    $top      = $trace[0];
    $function = $top['function'] ?? '<main>';
    $class    = $top['class'] ?? null;
    $name     = $class === null ? $function : $class . '::' . $function;

    $counts[$name] = ($counts[$name] ?? 0) + 1;
}

arsort($counts);

$total = array_sum($counts);

fwrite(STDERR, sprintf("  %-52s %8s %8s\n", 'self time', 'samples', 'share'));

foreach (array_slice($counts, 0, ROWS, true) as $name => $samples) {
    fwrite(STDERR, sprintf(
        "  %-52s %8d %7.1f%%\n",
        strlen($name) > 52 ? '…' . substr($name, -51) : $name,
        $samples,
        $total === 0 ? 0.0 : $samples / $total * 100,
    ));
}

// The folded form feeds a flame graph, and is the only form that keeps the
// caller chain a self-time table throws away.
$foldedPath = sys_get_temp_dir() . '/sconcur-excimer.folded';

file_put_contents($foldedPath, $log->formatCollapsed());

fwrite(STDERR, PHP_EOL . '  collapsed stacks: ' . $foldedPath . PHP_EOL);
