<?php

declare(strict_types=1);

use SConcur\Features\File\FileSystem;

require_once __DIR__ . '/_benchmarker.php';

const FILE_READ_SIZE  = 4 * 1024 * 1024;
const FILE_READ_CHUNK = 65_536;

$benchmarker = new Benchmarker(
    name: 'file-read',
);

$storageDirectory = __DIR__ . '/../storage/files';

if (!is_dir($storageDirectory)) {
    mkdir(directory: $storageDirectory, recursive: true);
}

$sourcePath = $storageDirectory . '/bench-read-source.bin';

file_put_contents($sourcePath, str_repeat('A', FILE_READ_SIZE));

$fileSystem = new FileSystem();

$benchmarker->run(
    nativeCallback: static function () use ($sourcePath): int {
        $handle = fopen($sourcePath, 'r');

        $total = 0;

        while (!feof($handle)) {
            $chunk = fread($handle, FILE_READ_CHUNK);

            $total += strlen($chunk);
        }

        fclose($handle);

        return $total;
    },
    syncCallback: static function () use ($fileSystem, $sourcePath): int {
        $file = $fileSystem->open(path: $sourcePath, mode: 'r');

        $total = strlen($file->getContents());

        $file->close();

        return $total;
    },
    asyncCallback: static function () use ($fileSystem, $sourcePath): int {
        $file = $fileSystem->open(path: $sourcePath, mode: 'r');

        $total = 0;

        while (!$file->eof()) {
            $total += strlen($file->read(FILE_READ_CHUNK));
        }

        $file->close();

        return $total;
    },
);

unlink($sourcePath);
