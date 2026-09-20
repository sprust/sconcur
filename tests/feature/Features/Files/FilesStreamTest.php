<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Files;

use SConcur\Exceptions\Files\FileNotFoundException;
use SConcur\Exceptions\Files\FileTooLargeException;
use SConcur\Exceptions\Files\FileWriterClosedException;
use SConcur\Exceptions\Files\UnexpectedFileTypeException;
use SConcur\Features\Files\Dto\DirectoryEntry;
use SConcur\Features\Files\Files;
use SConcur\Features\Files\FileWriteMode;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

/**
 * The streaming half of the feature: reads in batches, the tree walk and the writer.
 *
 * BaseTestCase::tearDown checks that no task is left dangling, which is what makes the
 * abandoned-stream tests here mean anything — an iterator broken out of halfway has to
 * leave the extension with nothing held.
 */
class FilesStreamTest extends BaseTestCase
{
    protected string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/sconcur-files-stream-' . uniqid();

        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree(path: $this->directory);

        parent::tearDown();
    }

    public function testChunksReadTheWholeFile(): void
    {
        $path = $this->path(name: 'chunked.bin');

        $contents = random_bytes(300_000);

        Files::write(path: $path, contents: $contents);

        $collected = '';
        $batches   = 0;

        foreach (Files::readChunks(path: $path, bufferSizeBytes: 65_536) as $chunk) {
            $collected .= $chunk;

            ++$batches;
        }

        self::assertSame($contents, $collected);

        // Five buffers over 300 000 bytes: the point of the stream is that it is more
        // than one, or nothing was streamed.
        self::assertSame(5, $batches);
    }

    public function testChunksOfAnEmptyFileYieldNothing(): void
    {
        $path = $this->path(name: 'empty.bin');

        Files::write(path: $path, contents: '');

        self::assertSame([], iterator_to_array(Files::readChunks(path: $path)));
    }

    public function testChunksKeyTheBatchesInOrder(): void
    {
        $path = $this->path(name: 'keyed.bin');

        Files::write(path: $path, contents: str_repeat('x', 10));

        $keys = [];

        foreach (Files::readChunks(path: $path, bufferSizeBytes: 4) as $key => $chunk) {
            $keys[] = $key;
        }

        self::assertSame([0, 1, 2], $keys);
    }

    public function testChunksOfAMissingFileAreRefused(): void
    {
        $this->expectException(FileNotFoundException::class);

        iterator_to_array(Files::readChunks(path: $this->path(name: 'absent.bin')));
    }

    public function testAnAbandonedChunkStreamLeavesNothingHeld(): void
    {
        $path = $this->path(name: 'abandoned.bin');

        Files::write(path: $path, contents: str_repeat('x', 500_000));

        $seen = 0;

        foreach (Files::readChunks(path: $path, bufferSizeBytes: 1024) as $chunk) {
            ++$seen;

            break;
        }

        self::assertSame(1, $seen);

        // The real assertion is BaseTestCase::tearDown, which refuses to pass while the
        // extension still holds a task — an abandoned stream that was never released
        // would be one.
    }

    public function testLinesAreCutTheWayTheNativeReadCutsThem(): void
    {
        $path = $this->path(name: 'lines.log');

        $contents = "first\nsecond\nthird\n";

        Files::write(path: $path, contents: $contents);

        self::assertSame(
            ['first', 'second', 'third'],
            iterator_to_array(Files::readLines(path: $path)),
        );
    }

    public function testALineSplitAcrossBuffersStaysOneLine(): void
    {
        $path = $this->path(name: 'split.log');

        // Lines far longer than the buffer, so every one of them straddles several reads.
        $lines = [
            str_repeat('a', 5_000),
            str_repeat('b', 7_000),
            str_repeat('c', 3_000),
        ];

        Files::write(path: $path, contents: implode("\n", $lines) . "\n");

        self::assertSame(
            $lines,
            iterator_to_array(Files::readLines(path: $path, bufferSizeBytes: 512)),
        );
    }

    public function testCarriageReturnsAndAMissingFinalNewlineAreHandled(): void
    {
        $path = $this->path(name: 'crlf.log');

        Files::write(path: $path, contents: "one\r\ntwo\r\nthree");

        self::assertSame(
            ['one', 'two', 'three'],
            iterator_to_array(Files::readLines(path: $path)),
        );
    }

    public function testEmptyLinesAreKept(): void
    {
        $path = $this->path(name: 'gaps.log');

        Files::write(path: $path, contents: "one\n\n\ntwo\n");

        self::assertSame(
            ['one', '', '', 'two'],
            iterator_to_array(Files::readLines(path: $path)),
        );
    }

    public function testLinesCrossInBatches(): void
    {
        $path = $this->path(name: 'batched.log');

        $lines = array_map(static fn(int $index): string => "line-$index", range(0, 99));

        Files::write(path: $path, contents: implode("\n", $lines) . "\n");

        self::assertSame(
            $lines,
            iterator_to_array(Files::readLines(path: $path, batchSize: 7)),
        );
    }

    public function testALineOverTheLimitIsRefused(): void
    {
        $path = $this->path(name: 'endless.log');

        Files::write(path: $path, contents: str_repeat('x', 20_000));

        $this->expectException(FileTooLargeException::class);

        iterator_to_array(Files::readLines(path: $path, maxLineBytes: 1024));
    }

    public function testWalkFindsEveryFileInTheTree(): void
    {
        Files::makeDirectory(path: $this->path(name: 'a/b'), recursive: true);
        Files::write(path: $this->path(name: 'root.txt'), contents: 'x');
        Files::write(path: $this->path(name: 'a/one.txt'), contents: 'x');
        Files::write(path: $this->path(name: 'a/b/two.txt'), contents: 'x');

        $names = array_map(
            static fn(DirectoryEntry $entry): string => $entry->name,
            iterator_to_array(Files::walk(path: $this->directory, pattern: '*.txt')),
        );

        sort($names);

        self::assertSame(['one.txt', 'root.txt', 'two.txt'], $names);
    }

    public function testWalkReportsDirectoriesTooWhenNothingFilters(): void
    {
        Files::makeDirectory(path: $this->path(name: 'sub'));
        Files::write(path: $this->path(name: 'sub/file.txt'), contents: 'x');

        $entries = iterator_to_array(Files::walk(path: $this->directory));

        $directories = array_filter(
            $entries,
            static fn(DirectoryEntry $entry): bool => $entry->isDirectory,
        );

        self::assertCount(1, $directories);
        self::assertCount(2, $entries);
    }

    public function testWalkCrossesInBatches(): void
    {
        for ($index = 0; $index < 25; ++$index) {
            Files::write(path: $this->path(name: "many-$index.txt"), contents: 'x');
        }

        self::assertCount(
            25,
            iterator_to_array(Files::walk(path: $this->directory, batchSize: 4)),
        );
    }

    public function testWalkDoesNotDescendIntoADirectorySymlink(): void
    {
        Files::makeDirectory(path: $this->path(name: 'real'));
        Files::write(path: $this->path(name: 'real/inside.txt'), contents: 'x');

        symlink($this->path(name: 'real'), $this->path(name: 'link'));

        $names = array_map(
            static fn(DirectoryEntry $entry): string => $entry->name,
            iterator_to_array(Files::walk(path: $this->directory, pattern: 'inside.txt')),
        );

        // Once, through the real directory. Following the link would find it twice — and
        // a link pointing at an ancestor would never end.
        self::assertSame(['inside.txt'], $names);
    }

    public function testWalkOfAFileIsRefusedByType(): void
    {
        $path = $this->path(name: 'not-a-tree.txt');

        Files::write(path: $path, contents: 'x');

        $this->expectException(UnexpectedFileTypeException::class);

        iterator_to_array(Files::walk(path: $path));
    }

    public function testAnAbandonedWalkLeavesNothingHeld(): void
    {
        Files::makeDirectory(path: $this->path(name: 'deep/deeper'), recursive: true);

        for ($index = 0; $index < 30; ++$index) {
            Files::write(path: $this->path(name: "deep/deeper/file-$index.txt"), contents: 'x');
        }

        $seen = 0;

        foreach (Files::walk(path: $this->directory, batchSize: 2) as $entry) {
            ++$seen;

            break;
        }

        self::assertSame(1, $seen);

        // As above: tearDown is what proves the walk let go of the tree.
    }

    public function testTheWriterFillsAFileChunkByChunk(): void
    {
        $path = $this->path(name: 'written.csv');

        $writer = Files::openWriter(path: $path);

        self::assertSame($path, $writer->path());

        $total = 0;

        foreach (['one,1' . PHP_EOL, 'two,2' . PHP_EOL, 'three,3' . PHP_EOL] as $line) {
            $total = $writer->write(chunk: $line);
        }

        self::assertSame(20, $total);
        self::assertSame(20, $writer->close());

        self::assertSame(
            'one,1' . PHP_EOL . 'two,2' . PHP_EOL . 'three,3' . PHP_EOL,
            Files::read(path: $path),
        );
    }

    public function testTheWriterAppendsWhenAskedTo(): void
    {
        $path = $this->path(name: 'appended.log');

        Files::write(path: $path, contents: 'before ');

        $writer = Files::openWriter(path: $path, mode: FileWriteMode::Append);

        $writer->write(chunk: 'after');
        $writer->close();

        self::assertSame('before after', Files::read(path: $path));
    }

    public function testWritingAfterCloseIsRefused(): void
    {
        $path = $this->path(name: 'closed.txt');

        $writer = Files::openWriter(path: $path);

        $writer->write(chunk: 'x');
        $writer->close();

        $this->expectException(FileWriterClosedException::class);

        $writer->write(chunk: 'more');
    }

    public function testClosingTwiceIsRefused(): void
    {
        $writer = Files::openWriter(path: $this->path(name: 'twice.txt'));

        $writer->close();

        $this->expectException(FileWriterClosedException::class);

        $writer->close();
    }

    public function testAnAbandonedWriterTakesItsUnfinishedFileWithIt(): void
    {
        $path = $this->path(name: 'abandoned.bin');

        $writer = Files::openWriter(path: $path);

        $writer->write(chunk: 'half a file');

        // Dropped without a close: the flow is released, and the extension removes what
        // the write never finished.
        unset($writer);

        // The removal happens in the extension after the flow ends, so the check waits
        // for it rather than racing it.
        $deadline = microtime(true) + 2;

        while (microtime(true) < $deadline && Files::exists(path: $path)) {
            usleep(10_000);
        }

        self::assertFalse(Files::exists(path: $path));
    }

    public function testAnAbandonedAppendingWriterKeepsWhatWasAlreadyThere(): void
    {
        $path = $this->path(name: 'abandoned-append.log');

        Files::write(path: $path, contents: 'existing');

        $writer = Files::openWriter(path: $path, mode: FileWriteMode::Append);

        $writer->write(chunk: ' more');

        unset($writer);

        usleep(100_000);

        // Append never removes: the file held data before the call, and none of it was
        // this writer's to destroy.
        self::assertTrue(Files::exists(path: $path));
        self::assertStringStartsWith('existing', Files::read(path: $path));
    }

    public function testStreamsRunConcurrentlyInOneGroup(): void
    {
        for ($index = 0; $index < 4; ++$index) {
            Files::write(
                path: $this->path(name: "stream-$index.log"),
                contents: implode("\n", array_fill(0, 50, "line-$index")) . "\n",
            );
        }

        $waitGroup = WaitGroup::create();

        for ($index = 0; $index < 4; ++$index) {
            $path = $this->path(name: "stream-$index.log");

            $waitGroup->add(
                callback: static function () use ($path): int {
                    $count = 0;

                    foreach (Files::readLines(path: $path, batchSize: 8) as $line) {
                        ++$count;
                    }

                    return $count;
                },
            );
        }

        $results = $waitGroup->waitResults();

        self::assertCount(4, $results);

        foreach ($results as $result) {
            self::assertSame(50, $result);
        }
    }

    public function testAWriterWorksInsideACoroutine(): void
    {
        $waitGroup = WaitGroup::create();

        for ($index = 0; $index < 4; ++$index) {
            $path = $this->path(name: "coroutine-$index.bin");

            $waitGroup->add(
                callback: static function () use ($path, $index): int {
                    $writer = Files::openWriter(path: $path);

                    for ($chunk = 0; $chunk < 8; ++$chunk) {
                        $writer->write(chunk: str_repeat((string) $index, 1024));
                    }

                    return $writer->close();
                },
            );
        }

        $results = $waitGroup->waitResults();

        foreach ($results as $result) {
            self::assertSame(8192, $result);
        }
    }

    protected function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }

    protected function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $child = $path . '/' . $name;

            is_dir($child) && !is_link($child) ? $this->removeTree(path: $child) : unlink($child);
        }

        rmdir($path);
    }
}
