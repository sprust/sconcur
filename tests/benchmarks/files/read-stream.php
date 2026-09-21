<?php

declare(strict_types=1);

// Reading a file in batches: fopen/fread in a loop against Files::readChunks.
//
// The number to watch here is not the time but the memory: the native loop and the
// stream both hold one buffer, while Files::read() of the same file holds it twice.
//
// The peak printed at the end is the process's, over the whole run — seeding included —
// so it says "no mode of this benchmark held a file" and nothing finer. Comparing a
// streamed read against a whole-file one is what the read/readChunks rows of
// docs/benchmarks.md are for.
//
// Size via SCONCUR_BENCH_FILE_BYTES.

use SConcur\Features\Files\Files;

require_once __DIR__ . '/../lib/benchmarker.php';
require_once __DIR__ . '/lib.php';

$benchmarker = new Benchmarker(
    name: 'files-read-stream',
);

$sizeBytes  = benchFilesSizeBytes(defaultBytes: 10_485_760);
$bufferSizeBytes = 65_536;
$directory  = benchFilesDirectory(name: 'read-stream');
$total      = $benchmarker->getTotal();

echo "File size:\t$sizeBytes bytes\n";
echo "Buffer:\t\t$bufferSizeBytes bytes\n";

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

$streamed = static function (string $path, int $bufferSizeBytes): int {
    $read = 0;

    foreach (Files::readChunks(path: $path, bufferSizeBytes: $bufferSizeBytes, timeoutMs: 0) as $chunk) {
        $read += strlen($chunk);
    }

    return $read;
};

$benchmarker->run(
    nativeCallback: static function () use (&$nativeIndex, $nativePaths, $bufferSize): int {
        $handle = fopen($nativePaths[$nativeIndex++ % count($nativePaths)], 'rb');

        if ($handle === false) {
            return 0;
        }

        $read = 0;

        while (!feof($handle)) {
            $read += strlen((string) fread($handle, $bufferSizeBytes));
        }

        fclose($handle);

        return $read;
    },
    syncCallback: static function () use (&$syncIndex, $syncPaths, $bufferSizeBytes, $streamed): int {
        return $streamed($syncPaths[$syncIndex++ % count($syncPaths)], $bufferSize);
    },
    asyncCallback: static function () use (&$asyncIndex, $asyncPaths, $bufferSizeBytes, $streamed): int {
        return $streamed($asyncPaths[$asyncIndex++ % count($asyncPaths)], $bufferSize);
    },
);

printf("Peak memory:\t%.1f MB\n", memory_get_peak_usage(true) / 1024 / 1024);
