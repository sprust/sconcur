<?php

declare(strict_types=1);

// Listing a directory with a size and a time per entry.
//
// The native side is what an application actually writes: scandir(), then filesize() and
// filemtime() on every entry — one syscall plus one per entry, each of them a pause of
// the PHP thread. The feature does the same walk inside the extension and crosses the
// boundary once.
//
// Entry count via SCONCUR_BENCH_DIR_ENTRIES.

use SConcur\Features\Files\Files;

require_once __DIR__ . '/../lib/benchmarker.php';
require_once __DIR__ . '/lib.php';

$benchmarker = new Benchmarker(
    name: 'files-list-dir',
);

$entries   = (int) (getenv('SCONCUR_BENCH_DIR_ENTRIES') ?: 10_000);
$directory = bench_files_directory(name: 'list');

echo "Entries:\t$entries\n";

bench_files_seed($directory, 'entry', $entries, 64);

$benchmarker->run(
    nativeCallback: static function () use ($directory): int {
        $collected = [];

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory . '/' . $name;

            $collected[] = [$name, filesize($path), filemtime($path)];
        }

        return count($collected);
    },
    syncCallback: static function () use ($directory): int {
        return count(Files::list(path: $directory, withMetadata: true, timeoutMs: 0));
    },
    asyncCallback: static function () use ($directory): int {
        return count(Files::list(path: $directory, withMetadata: true, timeoutMs: 0));
    },
);
