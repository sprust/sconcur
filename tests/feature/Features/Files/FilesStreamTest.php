<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Files;

use ReflectionProperty;
use SConcur\Exceptions\Files\FileNotFoundException;
use SConcur\Exceptions\Files\FilesException;
use SConcur\Exceptions\Files\FileTimeoutException;
use SConcur\Exceptions\Files\FileTooLargeException;
use SConcur\Exceptions\Files\FileStreamClosedException;
use SConcur\Exceptions\Files\UnexpectedFileTypeException;
use SConcur\Features\Files\Dto\DirectoryEntry;
use SConcur\Features\Files\Files;
use SConcur\Features\Files\FileWriteMode;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\State;
use SConcur\WaitGroup;

/**
 * The streaming half of the feature: reads in batches, the tree walk and the writer.
 *
 * The abandoned-stream tests observe the file descriptor the extension holds, not the
 * dangling-task count: tasksCount() counts running tasks, and a registered stream state
 * is not one — so a stream that was never released would leave that count at zero and
 * say nothing.
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

    public function testAnAbandonedChunkStreamReleasesTheFileItHeld(): void
    {
        $path = $this->path(name: 'abandoned.bin');

        Files::write(path: $path, contents: str_repeat('x', 500_000));

        self::assertSame(0, $this->openDescriptorsFor(path: $path));

        $stream = Files::readChunks(path: $path, bufferSizeBytes: 1024);
        $seen   = 0;

        foreach ($stream as $chunk) {
            ++$seen;

            break;
        }

        self::assertSame(1, $seen);

        // While the iterator is alive the extension is holding the file open. This is
        // what proves there is something to release — without it the next assertion
        // would pass against a feature that never opened anything.
        self::assertSame(1, $this->openDescriptorsFor(path: $path));

        unset($stream);

        self::assertSame(0, $this->awaitDescriptorsFor(path: $path));
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
            iterator_to_array(Files::readLines(path: $path, batchLines: 7)),
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
            iterator_to_array(Files::walk(path: $this->directory, batchEntries: 4)),
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

    public function testAnAbandonedWalkLetsGoOfTheTree(): void
    {
        Files::makeDirectory(path: $this->path(name: 'deep/deeper'), recursive: true);

        for ($index = 0; $index < 30; ++$index) {
            Files::write(path: $this->path(name: "deep/deeper/file-$index.txt"), contents: 'x');
        }

        self::assertSame(0, $this->heldSyncFlows());

        $walk = Files::walk(path: $this->directory, batchEntries: 2);
        $seen = 0;

        foreach ($walk as $entry) {
            ++$seen;

            break;
        }

        self::assertSame(1, $seen);

        // A walk holds no descriptor between batches — counting those would assert zero
        // whether or not anything was ever held. What it does hold is the synchronous
        // flow the state hangs on, so that is what is watched: one while the abandoned
        // walk is alive, none once it is dropped. Delete BatchIterator::releaseTask()
        // and the second assertion fails.
        self::assertSame(1, $this->heldSyncFlows());

        unset($walk);

        self::assertSame(0, $this->heldSyncFlows());
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

        $this->expectException(FileStreamClosedException::class);

        $writer->write(chunk: 'more');
    }

    public function testClosingTwiceIsRefused(): void
    {
        $writer = Files::openWriter(path: $this->path(name: 'twice.txt'));

        $writer->close();

        $this->expectException(FileStreamClosedException::class);

        $writer->close();
    }

    public function testAnAbandonedWriterThatCreatedTheFileTakesItWithIt(): void
    {
        $path = $this->path(name: 'abandoned-create.bin');

        $writer = Files::openWriter(path: $path, mode: FileWriteMode::Create);

        $writer->write(chunk: 'half a file');

        // Dropped without a close: the flow is released, and the extension removes what
        // the write never finished — it created this file, so the half of it is the whole
        // of what would be lost.
        unset($writer);

        $deadline = microtime(true) + 2;

        while (microtime(true) < $deadline && Files::exists(path: $path)) {
            usleep(10_000);
        }

        self::assertFalse(Files::exists(path: $path));
    }

    public function testAnAbandonedReplacingWriterLeavesThePartialFileRatherThanTheOldOne(): void
    {
        $path = $this->path(name: 'abandoned-replace.bin');

        Files::write(path: $path, contents: 'the previous version');

        $writer = Files::openWriter(path: $path, mode: FileWriteMode::Replace);

        $writer->write(chunk: 'half');

        unset($writer);

        self::assertSame(0, $this->awaitDescriptorsFor(path: $path));

        // Replace truncated the old contents on open — that is what was asked for — but
        // removing the file on top of that would destroy an inode, its permissions and
        // its ownership that this call never created. A partial file is the lesser evil,
        // and the caller's own file either way. Open with Create when the file must be
        // this writer's or nothing.
        self::assertTrue(Files::exists(path: $path));
        self::assertSame('half', Files::read(path: $path));
    }

    public function testAnAbandonedAppendingWriterKeepsWhatWasAlreadyThere(): void
    {
        $path = $this->path(name: 'abandoned-append.log');

        Files::write(path: $path, contents: 'existing');

        $writer = Files::openWriter(path: $path, mode: FileWriteMode::Append);

        $writer->write(chunk: ' more');

        unset($writer);

        // Asserting a non-event after a fixed wait would pass whenever a wrongful removal
        // is merely slower than the wait. Wait for the release that does happen — the
        // descriptor — and only then assert the file survived it.
        self::assertSame(0, $this->awaitDescriptorsFor(path: $path));

        self::assertTrue(Files::exists(path: $path));
        self::assertStringStartsWith('existing', Files::read(path: $path));
    }

    public function testEveryStreamInOneGroupReadsItsOwnFile(): void
    {
        // Named for what it checks: that results do not cross between coroutines. That
        // they overlap in time is proved by FilesTest, which runs on BaseAsyncTestCase
        // and asserts the event order only interleaving can produce.
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

                    foreach (Files::readLines(path: $path, batchLines: 8) as $line) {
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

    public function testEveryWriterInOneGroupFillsItsOwnFile(): void
    {
        // Named for what it checks: that results do not cross between coroutines. That
        // they overlap in time is proved by FilesTest, which runs on BaseAsyncTestCase
        // and asserts the event order only interleaving can produce.
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

    /**
     * The deadline reaches the core on a stream, which it did not until a review found
     * the parameter documented, defaulted and dropped on the floor — neither the open nor
     * any batch was bounded, so a read from a hung mount could not be ended by anything.
     *
     * A deadline of one millisecond over a file that needs many batches is what a stalled
     * mount looks like from here.
     */
    public function testAStreamHonoursItsDeadline(): void
    {
        $path = $this->path(name: 'deadline.log');

        Files::write(path: $path, contents: str_repeat("line\n", 200_000));

        $this->expectException(FileTimeoutException::class);

        // The deadline bounds one batch, so the batch has to be the slow thing: every
        // line at once, read through a 64-byte buffer, is sixteen thousand trips to the
        // blocking pool inside a single next(). Asking for one line at a time instead
        // would give each batch its own fresh millisecond and never trip the deadline —
        // which is how this test first passed by luck.
        iterator_to_array(
            Files::readLines(
                path: $path,
                batchLines: 1_000_000,
                bufferSizeBytes: 64,
                timeoutMs: 1,
            ),
        );
    }

    public function testAStreamWithoutADeadlineReadsToTheEnd(): void
    {
        $path = $this->path(name: 'no-deadline.log');

        Files::write(path: $path, contents: "one\ntwo\nthree\n");

        self::assertCount(
            3,
            iterator_to_array(Files::readLines(path: $path, timeoutMs: 0)),
        );
    }

    public function testAStreamCanBeIteratedAgain(): void
    {
        $path = $this->path(name: 're-read.log');

        Files::write(path: $path, contents: "one\ntwo\nthree\n");

        $lines = Files::readLines(path: $path);

        self::assertSame(['one', 'two', 'three'], iterator_to_array($lines));

        // rewind() opens a second stream and releases the first. Pinned here because the
        // release is what stops the second pass from leaking the first one's state.
        self::assertSame(['one', 'two', 'three'], iterator_to_array($lines));

        unset($lines);

        self::assertSame(0, $this->awaitDescriptorsFor(path: $path));
    }

    public function testAClosedWriterCannotBeReopenedByItsHandle(): void
    {
        $path = $this->path(name: 'spent.bin');

        $writer = Files::openWriter(path: $path);

        $writer->write(chunk: 'contents');

        self::assertSame(8, $writer->close());
        self::assertSame(8, $writer->writtenBytes());
        self::assertSame($path, $writer->path());

        // The session is gone on both sides: the handle refuses locally, so a second
        // close cannot reach a session somebody else has since opened under the same id.
        $this->expectException(FileStreamClosedException::class);

        $writer->close();
    }

    /**
     * A close that fails because the writer was cut off mid-chunk is terminal: the file
     * holds bytes no total accounts for, so the handle is spent and the flow given back
     * rather than held for a retry that cannot help.
     *
     * The previous round of this feature claimed the opposite — that every failed close
     * was retryable — and leaked the flow for as long as the handle lived.
     */
    public function testACloseAfterACutOffChunkIsTerminalAndGivesTheFlowBack(): void
    {
        $path = $this->path(name: 'cut-off.bin');

        $before = $this->heldSyncFlows();

        // One millisecond against a chunk far larger than the disk answers in it.
        $writer = Files::openWriter(
            path: $path,
            mode: FileWriteMode::Create,
            timeoutMs: 1,
        );

        self::assertSame($before + 1, $this->heldSyncFlows());

        try {
            $writer->write(chunk: str_repeat('x', 48 * 1024 * 1024));
        } catch (FilesException) {
            //
        }

        try {
            $writer->close();

            self::fail('A writer cut off mid-chunk was closed as if it were whole.');
        } catch (FileStreamClosedException) {
            //
        }

        // The flow is back even though nobody destroyed the handle, and a second call
        // refuses locally rather than reaching for a session that is gone.
        self::assertSame($before, $this->heldSyncFlows());

        $this->expectException(FileStreamClosedException::class);

        $writer->close();
    }

    protected function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }

    /**
     * How many synchronous flows the package is still holding for a stream or a writer.
     *
     * Read by reflection because it is internal bookkeeping with no public reader — and
     * it is the only observable that distinguishes a released walk from one the
     * extension still keeps, since a walk holds no file descriptor between batches.
     */
    protected function heldSyncFlows(): int
    {
        $property = new ReflectionProperty(State::class, 'syncTaskFlows');

        /** @var array<string, string> $flows */
        $flows = $property->getValue();

        return count($flows);
    }

    /**
     * How many of this process's open descriptors point at the path. The extension's
     * handles are this process's handles, so this is the one thing that shows whether a
     * stream really let go.
     */
    protected function openDescriptorsFor(string $path): int
    {
        $real  = realpath($path) ?: $path;
        $count = 0;

        foreach (glob('/proc/self/fd/*') ?: [] as $descriptor) {
            // Silenced deliberately: the listing and the readlink are two steps, and a
            // descriptor closed in between — by this process or by the extension —
            // makes the second one fail. That is the ordinary case here, not an error.
            if (@readlink($descriptor) === $real) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The same count, once the extension has had a chance to act: the release happens on
     * the runtime after the flow ends, so it is waited for rather than raced.
     */
    protected function awaitDescriptorsFor(string $path): int
    {
        $deadline = microtime(true) + 2;

        while (microtime(true) < $deadline) {
            $count = $this->openDescriptorsFor(path: $path);

            if ($count === 0) {
                return 0;
            }

            usleep(10_000);
        }

        return $this->openDescriptorsFor(path: $path);
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
