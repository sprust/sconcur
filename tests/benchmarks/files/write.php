<?php

declare(strict_types=1);

// Writing a whole file: native file_put_contents against the feature.
//
// Size via SCONCUR_BENCH_FILE_BYTES.

use SConcur\Features\Files\Files;

require_once __DIR__ . '/../lib/benchmarker.php';
require_once __DIR__ . '/lib.php';

$benchmarker = new Benchmarker(
    name: 'files-write',
);

$sizeBytes = bench_files_size_bytes(default: 1_048_576);
$directory = bench_files_directory(name: 'write');
$contents  = str_repeat('x', $sizeBytes);

echo "File size:\t$sizeBytes bytes\n";

$nativeIndex = 0;
$syncIndex   = 0;
$asyncIndex  = 0;

$benchmarker->run(
    nativeCallback: static function () use (&$nativeIndex, $directory, $contents): int {
        return (int) file_put_contents($directory . '/native-' . $nativeIndex++ . '.bin', $contents);
    },
    syncCallback: static function () use (&$syncIndex, $directory, $contents): int {
        return Files::write(path: $directory . '/sync-' . $syncIndex++ . '.bin', contents: $contents);
    },
    asyncCallback: static function () use (&$asyncIndex, $directory, $contents): int {
        return Files::write(path: $directory . '/async-' . $asyncIndex++ . '.bin', contents: $contents);
    },
);
