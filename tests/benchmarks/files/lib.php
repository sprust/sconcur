<?php

declare(strict_types=1);

// Shared fixture for the file benchmarks: a scratch directory per run, and the seeded
// files the callbacks work against.
//
// Each mode gets files of its own. Sharing one would measure the page cache warmed by
// whichever mode ran first, which is the opposite of what these benchmarks are for: the
// interesting case is the file the kernel does not already have.

/**
 * The directory this run works in. Named after the process so parallel runs do not meet.
 */
function bench_files_directory(string $name): string
{
    $directory = sys_get_temp_dir() . '/sconcur-bench-files-' . $name . '-' . getmypid();

    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    register_shutdown_function(static function () use ($directory): void {
        bench_files_remove($directory);
    });

    return $directory;
}

/**
 * Writes $count files of $sizeBytes each and answers with their paths.
 */
function bench_files_seed(string $directory, string $prefix, int $count, int $sizeBytes): array
{
    $contents = str_repeat('x', $sizeBytes);
    $paths    = [];

    for ($index = 0; $index < $count; ++$index) {
        $path = $directory . '/' . $prefix . '-' . $index . '.bin';

        file_put_contents($path, $contents);

        $paths[] = $path;
    }

    return $paths;
}

function bench_files_remove(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path)) {
            @unlink($path);
        }

        return;
    }

    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        bench_files_remove($path . '/' . $name);
    }

    @rmdir($path);
}

/**
 * The size the SCONCUR_BENCH_FILE_BYTES environment variable asks for, or the default.
 * The whole verdict of these benchmarks turns on this number, so it is a knob rather
 * than a constant: a kilobyte says the boundary costs more than the work, a hundred
 * megabytes says the opposite.
 */
function bench_files_size_bytes(int $default): int
{
    $configured = (int) (getenv('SCONCUR_BENCH_FILE_BYTES') ?: 0);

    return $configured > 0 ? $configured : $default;
}
