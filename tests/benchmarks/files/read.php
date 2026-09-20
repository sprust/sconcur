<?php

declare(strict_types=1);

// Reading a whole file: native file_get_contents against the feature, synchronously and
// concurrently.
//
// Expect the synchronous path to lose. It does the same read and adds a boundary
// crossing, and on a page-cached file that crossing is the whole of the measurement.
// What the async column is for is the other thing: those reads happen while the PHP
// thread is free, which this benchmark can only show as wall-clock time and a real
// server shows as a latency tail that stops moving.
//
// Size via SCONCUR_BENCH_FILE_BYTES, e.g. 1024, 1048576, 104857600.

use SConcur\Features\Files\Files;

require_once __DIR__ . '/../lib/benchmarker.php';
require_once __DIR__ . '/lib.php';

$benchmarker = new Benchmarker(
    name: 'files-read',
);

$sizeBytes = bench_files_size_bytes(default: 1_048_576);
$directory = bench_files_directory(name: 'read');
$total     = $benchmarker->getTotal();

echo "File size:\t$sizeBytes bytes\n";

$nativePaths = bench_files_seed($directory, 'native', $total, $sizeBytes);
$syncPaths   = bench_files_seed($directory, 'sync', $total, $sizeBytes);
$asyncPaths  = bench_files_seed($directory, 'async', $total, $sizeBytes);

$nativeIndex = 0;
$syncIndex   = 0;
$asyncIndex  = 0;

$benchmarker->run(
    nativeCallback: static function () use (&$nativeIndex, $nativePaths): int {
        $path = $nativePaths[$nativeIndex++ % count($nativePaths)];

        return strlen((string) file_get_contents($path));
    },
    syncCallback: static function () use (&$syncIndex, $syncPaths): int {
        $path = $syncPaths[$syncIndex++ % count($syncPaths)];

        return strlen(Files::read(path: $path, maxReadBytes: 0));
    },
    asyncCallback: static function () use (&$asyncIndex, $asyncPaths): int {
        $path = $asyncPaths[$asyncIndex++ % count($asyncPaths)];

        return strlen(Files::read(path: $path, maxReadBytes: 0));
    },
);
