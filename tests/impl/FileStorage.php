<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

/**
 * Resolves paths under tests/storage/files — a gitignored scratch directory for
 * test and benchmark files (handy for the larger files benchmarks write). Shared
 * by the File feature tests and benchmarks.
 */
trait FileStorage
{
    protected function fileStoragePath(string $name): string
    {
        $directory = dirname(__DIR__) . '/storage/files';

        if (!is_dir($directory)) {
            mkdir(directory: $directory, recursive: true);
        }

        return $directory . '/' . $name;
    }
}
