<?php

declare(strict_types=1);

// Copying a file. The one operation where the verdict does not depend on the size or on
// the page cache: native copy() moves the bytes through the PHP process, and the feature
// moves them inside the extension, so nothing crosses the boundary but a path and a
// count.
//
// Size via SCONCUR_BENCH_FILE_BYTES.

use SConcur\Features\Files\Files;

require_once __DIR__ . '/../lib/benchmarker.php';
require_once __DIR__ . '/lib.php';

$benchmarker = new Benchmarker(
    name: 'files-copy',
);

$sizeBytes = bench_files_size_bytes(default: 1_048_576);
$directory = bench_files_directory(name: 'copy');
$total     = $benchmarker->getTotal();

echo "File size:\t$sizeBytes bytes\n";

// A set of sources per mode, not one shared set. Sharing would hand the page cache to
// whichever mode ran second and third: the native column runs first and would pay for
// every cold read, which on a 64 MiB file is most of the measurement and would show the
// feature winning by a factor it has not earned.
$nativeSources = bench_files_seed($directory, 'native-source', max($total, 1), $sizeBytes);
$syncSources   = bench_files_seed($directory, 'sync-source', max($total, 1), $sizeBytes);
$asyncSources  = bench_files_seed($directory, 'async-source', max($total, 1), $sizeBytes);

$nativeIndex = 0;
$syncIndex   = 0;
$asyncIndex  = 0;

$benchmarker->run(
    nativeCallback: static function () use (&$nativeIndex, $directory, $nativeSources): bool {
        $source = $nativeSources[$nativeIndex % count($nativeSources)];

        return copy($source, $directory . '/native-copy-' . $nativeIndex++ . '.bin');
    },
    syncCallback: static function () use (&$syncIndex, $directory, $syncSources): int {
        $source = $syncSources[$syncIndex % count($syncSources)];

        return Files::copy(
            source: $source,
            destination: $directory . '/sync-copy-' . $syncIndex++ . '.bin',
        );
    },
    asyncCallback: static function () use (&$asyncIndex, $directory, $asyncSources): int {
        $source = $asyncSources[$asyncIndex % count($asyncSources)];

        return Files::copy(
            source: $source,
            destination: $directory . '/async-copy-' . $asyncIndex++ . '.bin',
        );
    },
);
