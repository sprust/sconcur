<?php

declare(strict_types=1);

// Soak test for the Files feature: runs one scenario in a loop and prints, every five
// seconds, what is held on both sides — the PHP heap and its dangling tasks, plus the
// process RSS, because what a file stream holds is native memory and the PHP heap would
// not show it moving.
//
// Everything a cycle creates is released inside that cycle, so any value that only grows
// is a leak. The scenario worth the most attention is `abandoned`: an iterator broken out
// of halfway and a writer dropped without a close are released by nothing but the flow
// ending, which is the one path no ordinary test exercises for long.
//
// Run it through `make mem-leak-files scenario=<name> seconds=<n>`, or by hand:
//
//   php -d extension=./ext/build/sconcur.so \
//       tests/mem-leak/files-soak.php <scenario> <seconds>

use SConcur\Connection\Extension;
use SConcur\Features\Files\Files;
use SConcur\Features\Files\FileWriteMode;
use SConcur\Tests\Impl\TestApplication;
use SConcur\WaitGroup;

require_once __DIR__ . '/../../vendor/autoload.php';

TestApplication::init();

$scenario        = (string) ($_SERVER['argv'][1] ?? 'read-large');
$durationSeconds = (int) ($_SERVER['argv'][2] ?? 120);

$directory = sys_get_temp_dir() . '/sconcur-soak-files-' . getmypid();

if (!is_dir($directory)) {
    mkdir($directory, 0777, true);
}

/** Removes everything the run made, whatever ends it. */
$cleanup = static function () use ($directory): void {
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    $remove($path . '/' . $name);
                }
            }

            @rmdir($path);

            return;
        }

        @unlink($path);
    };

    $remove($directory);
};

register_shutdown_function($cleanup);

/** The payload the read and write scenarios move: big enough that a leak of one shows. */
$contents = str_repeat('x', 4 * 1024 * 1024);

$sourcePath = $directory . '/source.bin';

file_put_contents($sourcePath, $contents);

// A tree for the walk scenario: deep enough that a frontier left behind would grow.
for ($branch = 0; $branch < 10; ++$branch) {
    $branchPath = $directory . '/tree/branch-' . $branch;

    if (!is_dir($branchPath)) {
        mkdir($branchPath, 0777, true);
    }

    for ($leaf = 0; $leaf < 20; ++$leaf) {
        file_put_contents($branchPath . '/leaf-' . $leaf . '.txt', "line one\nline two\n");
    }
}

/**
 * One cycle of the chosen scenario. Everything it opens, it closes — except in
 * `abandoned`, where the point is that it does not.
 */
$cycle = static function (int $iteration) use ($scenario, $directory, $sourcePath, $contents): void {
    switch ($scenario) {
        // Whole-file reads of a multi-megabyte file, concurrently: the payload crosses
        // the boundary every time, so a buffer kept on either side would show here first.
        case 'read-large':
            $waitGroup = WaitGroup::create();

            for ($index = 0; $index < 4; ++$index) {
                $waitGroup->add(
                    callback: static fn (): int => strlen(
                        Files::read(path: $sourcePath, maxReadBytes: 0),
                    ),
                );
            }

            $waitGroup->waitAll();

            break;

        // Streamed reads, walked to the end: the state is deleted by its last batch
        // rather than by the flow, which is the other of the two ways a stream can end.
        case 'read-stream':
            $waitGroup = WaitGroup::create();

            $waitGroup->add(
                callback: static function () use ($sourcePath): int {
                    $read = 0;

                    foreach (Files::readChunks(path: $sourcePath, timeoutMs: 0) as $chunk) {
                        $read += strlen($chunk);
                    }

                    return $read;
                },
            );

            $waitGroup->waitAll();

            break;

        // A file filled chunk by chunk and closed properly, then removed.
        case 'write-stream':
            $path = $directory . '/written-' . ($iteration % 4) . '.bin';

            $waitGroup = WaitGroup::create();

            $waitGroup->add(
                callback: static function () use ($path): int {
                    $writer = Files::openWriter(path: $path);

                    for ($chunk = 0; $chunk < 16; ++$chunk) {
                        $writer->write(chunk: str_repeat('y', 65_536));
                    }

                    return $writer->close();
                },
            );

            $waitGroup->waitAll();

            Files::delete(path: $path, missingOk: true);

            break;

        // Copies: the bytes never cross the boundary, so this watches the extension's
        // own buffers rather than PHP's.
        case 'copy':
            $path = $directory . '/copied-' . ($iteration % 4) . '.bin';

            Files::copy(source: $sourcePath, destination: $path, timeoutMs: 0);
            Files::delete(path: $path, missingOk: true);

            break;

        // The tree walk and a listing of it, both read to the end.
        case 'walk':
            $waitGroup = WaitGroup::create();

            $waitGroup->add(
                callback: static function () use ($directory): int {
                    $seen = 0;

                    foreach (Files::walk(path: $directory . '/tree', batchEntries: 16, timeoutMs: 0) as $ignored) {
                        ++$seen;
                    }

                    return $seen;
                },
            );

            $waitGroup->waitAll();

            Files::list(path: $directory . '/tree', withMetadata: true);

            break;

        // The scenario that matters most: streams abandoned halfway and a writer dropped
        // without a close. Nothing but the flow ending releases any of them, and inside a
        // coroutine the PHP-side destructor is a no-op — so this is the extension's
        // cleanup hook under load, and nothing else.
        case 'abandoned':
            $waitGroup = WaitGroup::create();

            $waitGroup->add(
                callback: static function () use ($sourcePath, $directory, $contents): void {
                    foreach (Files::readChunks(path: $sourcePath, bufferSizeBytes: 4096, timeoutMs: 0) as $chunk) {
                        break;
                    }

                    foreach (Files::walk(path: $directory . '/tree', batchEntries: 4, timeoutMs: 0) as $entry) {
                        break;
                    }

                    foreach (Files::readLines(path: $directory . '/tree/branch-0/leaf-0.txt', timeoutMs: 0) as $line) {
                        break;
                    }

                    $writer = Files::openWriter(path: $directory . '/abandoned.bin');

                    $writer->write(chunk: substr($contents, 0, 65_536));

                    // Dropped without a close. The flow ends with this coroutine, and the
                    // extension is what has to close the file and remove it.
                },
            );

            $waitGroup->waitAll();

            break;

        default:
            throw new RuntimeException("unknown scenario $scenario");
    }
};

/**
 * The process resident size, in bytes. What a file stream holds is native memory, and the
 * PHP heap says nothing about it.
 */
$residentBytes = static function (): int {
    $status = @file_get_contents('/proc/self/status');

    if ($status === false || preg_match('/^VmRSS:\s+(\d+) kB$/m', $status, $matches) !== 1) {
        return 0;
    }

    return ((int) $matches[1]) * 1024;
};

echo "files soak: scenario=$scenario, seconds=$durationSeconds\n";
echo str_repeat('-', 80) . "\n";

$startTime        = microtime(true);
$lastReport       = $startTime;
$iteration        = 0;
$baselineHeap     = 0;
$baselineResident = 0;

while ((microtime(true) - $startTime) < $durationSeconds) {
    $cycle($iteration);

    ++$iteration;

    // The state after a hundred cycles is the baseline: everything before that is the
    // fibers, the buffers and the interned strings settling.
    if ($iteration === 100) {
        $baselineHeap     = memory_get_usage(true);
        $baselineResident = $residentBytes();
    }

    if ((microtime(true) - $lastReport) < 5.0) {
        continue;
    }

    $lastReport = microtime(true);

    $heapBytes     = memory_get_usage(true);
    $residentNow   = $residentBytes();
    $heapGrowth    = $baselineHeap === 0 ? 0 : $heapBytes - $baselineHeap;
    $residentGrow  = $baselineResident === 0 ? 0 : $residentNow - $baselineResident;
    $tasks         = Extension::get()->count();
    $elapsed       = (int) (microtime(true) - $startTime);

    printf(
        "%4ds  cycles %-8d heap %6.1f MB (%+6.1f)  rss %7.1f MB (%+7.1f)  tasks %d\n",
        $elapsed,
        $iteration,
        $heapBytes / 1024 / 1024,
        $heapGrowth / 1024 / 1024,
        $residentNow / 1024 / 1024,
        $residentGrow / 1024 / 1024,
        $tasks,
    );
}

echo str_repeat('-', 80) . "\n";
echo "done: $iteration cycles\n";
