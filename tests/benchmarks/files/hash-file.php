<?php

declare(strict_types=1);

// Checksumming a file: native hash_file against the feature.
//
// hash_file() already reads in a loop without holding the file whole, so this is not
// about memory. It is about where the loop runs: natively it holds the PHP thread for
// the whole file, here it does not.
//
// Size via SCONCUR_BENCH_FILE_BYTES.

use SConcur\Features\Files\FileHashAlgorithm;
use SConcur\Features\Files\Files;

require_once __DIR__ . '/../lib/benchmarker.php';
require_once __DIR__ . '/lib.php';

$benchmarker = new Benchmarker(
    name: 'files-hash-file',
);

$sizeBytes = benchFilesSizeBytes(defaultBytes: 10_485_760);
$directory = benchFilesDirectory(name: 'hash');
$total     = $benchmarker->getTotal();

echo "File size:\t$sizeBytes bytes\n";

$nativePaths = benchFilesSeed(
    directory: $directory,
    prefix: 'native',
    count: $total,
    sizeBytes: $sizeBytes,
);
$syncPaths   = benchFilesSeed(
    directory: $directory,
    prefix: 'sync',
    count: $total,
    sizeBytes: $sizeBytes,
);
$asyncPaths  = benchFilesSeed(
    directory: $directory,
    prefix: 'async',
    count: $total,
    sizeBytes: $sizeBytes,
);

$nativeIndex = 0;
$syncIndex   = 0;
$asyncIndex  = 0;

$benchmarker->run(
    nativeCallback: static function () use (&$nativeIndex, $nativePaths): string {
        return (string) hash_file('sha256', $nativePaths[$nativeIndex++ % count($nativePaths)]);
    },
    syncCallback: static function () use (&$syncIndex, $syncPaths): string {
        return Files::hashFile(
            path: $syncPaths[$syncIndex++ % count($syncPaths)],
            algorithm: FileHashAlgorithm::Sha256,
        );
    },
    asyncCallback: static function () use (&$asyncIndex, $asyncPaths): string {
        return Files::hashFile(
            path: $asyncPaths[$asyncIndex++ % count($asyncPaths)],
            algorithm: FileHashAlgorithm::Sha256,
        );
    },
);
