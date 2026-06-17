<?php

declare(strict_types=1);

use SConcur\Features\File\FileSystem;

require_once __DIR__ . '/_benchmarker.php';

const FILE_WRITE_SIZE  = 4 * 1024 * 1024;
const FILE_WRITE_CHUNK = 65_536;

$benchmarker = new Benchmarker(
    name: 'file-write',
);

$storageDirectory = __DIR__ . '/../storage/files';

if (!is_dir($storageDirectory)) {
    mkdir(directory: $storageDirectory, recursive: true);
}

$buffer = str_repeat('A', FILE_WRITE_SIZE);

$fileSystem = new FileSystem();

$nativeIndex = 0;
$syncIndex   = 0;
$asyncIndex  = 0;

$benchmarker->run(
    nativeCallback: static function () use ($buffer, $storageDirectory, &$nativeIndex): int {
        $path = $storageDirectory . '/bench-write-native-' . $nativeIndex++;

        $handle = fopen($path, 'w');

        $total = 0;

        foreach (str_split($buffer, FILE_WRITE_CHUNK) as $chunk) {
            $total += (int) fwrite($handle, $chunk);
        }

        fclose($handle);

        return $total;
    },
    syncCallback: static function () use ($fileSystem, $buffer, $storageDirectory, &$syncIndex): int {
        $path = $storageDirectory . '/bench-write-sync-' . $syncIndex++;

        $file = $fileSystem->open(path: $path, mode: 'w+');

        $total = 0;

        foreach (str_split($buffer, FILE_WRITE_CHUNK) as $chunk) {
            $total += $file->write($chunk);
        }

        $file->close();

        return $total;
    },
    asyncCallback: static function () use ($fileSystem, $buffer, $storageDirectory, &$asyncIndex): int {
        $path = $storageDirectory . '/bench-write-async-' . $asyncIndex++;

        $file = $fileSystem->open(path: $path, mode: 'w+');

        $total = 0;

        foreach (str_split($buffer, FILE_WRITE_CHUNK) as $chunk) {
            $total += $file->write($chunk);
        }

        $file->close();

        return $total;
    },
);

foreach (glob($storageDirectory . '/bench-write-*') ?: [] as $path) {
    unlink($path);
}
