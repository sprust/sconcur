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

$entries = (int) (getenv('SCONCUR_BENCH_DIR_ENTRIES') ?: 10_000);
$root    = benchFilesDirectory(name: 'list');

echo "Entries:\t$entries\n";

// A directory per mode, not one shared. Listing the same directory three times would
// hand the kernel's dentry and inode caches to whichever mode ran second and third,
// and the native column runs first — so the feature would win by a margin the cache
// paid for. Same reason the copy benchmark seeds its sources per mode.
$directories = [];

foreach (['native', 'sync', 'async'] as $mode) {
    $directory = $root . '/' . $mode;

    mkdir($directory, 0777, true);

    benchFilesSeed(
        directory: $directory,
        prefix: 'entry',
        count: $entries,
        sizeBytes: 64,
    );

    $directories[$mode] = $directory;
}

$benchmarker->run(
    nativeCallback: static function () use ($directories): int {
        $directory = $directories['native'];
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
    syncCallback: static function () use ($directories): int {
        return count(Files::list(path: $directories['sync'], withMetadata: true, timeoutMs: 0));
    },
    asyncCallback: static function () use ($directories): int {
        return count(Files::list(path: $directories['async'], withMetadata: true, timeoutMs: 0));
    },
);
