<?php

declare(strict_types=1);

// Reading a file in batches: fopen/fread in a loop against Files::readChunks.
//
// The number to watch here is not the time but the memory: the native loop and the
// stream both hold one buffer, while Files::read() of the same file holds it twice. The
// benchmark prints the peak so the difference is visible next to the timing.
//
// Size via SCONCUR_BENCH_FILE_BYTES.

use SConcur\Features\Files\Files;

require_once __DIR__ . '/../lib/benchmarker.php';
require_once __DIR__ . '/lib.php';

$benchmarker = new Benchmarker(
    name: 'files-read-stream',
);

$sizeBytes  = bench_files_size_bytes(default: 10_485_760);
$bufferSize = 65_536;
$directory  = bench_files_directory(name: 'read-stream');
$total      = $benchmarker->getTotal();

echo "File size:\t$sizeBytes bytes\n";
echo "Buffer:\t\t$bufferSize bytes\n";

$nativePaths = bench_files_seed($directory, 'native', $total, $sizeBytes);
$syncPaths   = bench_files_seed($directory, 'sync', $total, $sizeBytes);
$asyncPaths  = bench_files_seed($directory, 'async', $total, $sizeBytes);

$nativeIndex = 0;
$syncIndex   = 0;
$asyncIndex  = 0;

$streamed = static function (string $path, int $bufferSize): int {
    $read = 0;

    foreach (Files::readChunks(path: $path, bufferSizeBytes: $bufferSize, timeoutMs: 0) as $chunk) {
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
            $read += strlen((string) fread($handle, $bufferSize));
        }

        fclose($handle);

        return $read;
    },
    syncCallback: static function () use (&$syncIndex, $syncPaths, $bufferSize, $streamed): int {
        return $streamed($syncPaths[$syncIndex++ % count($syncPaths)], $bufferSize);
    },
    asyncCallback: static function () use (&$asyncIndex, $asyncPaths, $bufferSize, $streamed): int {
        return $streamed($asyncPaths[$asyncIndex++ % count($asyncPaths)], $bufferSize);
    },
);

printf("Peak memory:\t%.1f MB\n", memory_get_peak_usage(true) / 1024 / 1024);
