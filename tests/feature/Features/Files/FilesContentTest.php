<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Files;

use SConcur\Exceptions\Files\FileAlreadyExistsException;
use SConcur\Exceptions\Files\FileNotFoundException;
use SConcur\Exceptions\Files\FilesException;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Exceptions\Files\FileTooLargeException;
use SConcur\Exceptions\Files\InvalidFileArgumentException;
use SConcur\Exceptions\Files\UnexpectedFileTypeException;
use SConcur\Features\Files\Files;
use SConcur\Features\Files\FileWriteMode;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;
use Throwable;

/**
 * The single-shot content operations, checked against the native functions they replace
 * and along their error paths. Every call here runs on the synchronous path — outside a
 * WaitGroup — which is the second half of the feature's contract.
 */
class FilesContentTest extends BaseTestCase
{
    protected string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/sconcur-files-' . uniqid();

        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function testReadsWhatTheNativeFunctionWrote(): void
    {
        $path = $this->path(name: 'native.txt');

        file_put_contents($path, "line one\nline two\n");

        self::assertSame(
            file_get_contents($path),
            Files::read(path: $path),
        );
    }

    public function testWritesWhatTheNativeFunctionReads(): void
    {
        $path = $this->path(name: 'written.bin');

        // Binary, not text: the contents cross as a MessagePack string and must come back
        // byte for byte, null bytes and invalid UTF-8 included.
        $contents = random_bytes(4096);

        $written = Files::write(path: $path, contents: $contents);

        self::assertSame(4096, $written);
        self::assertSame($contents, file_get_contents($path));
    }

    public function testReadsAByteRange(): void
    {
        $path = $this->path(name: 'range.txt');

        Files::write(path: $path, contents: 'abcdefghij');

        self::assertSame('cde', Files::read(path: $path, offsetBytes: 2, lengthBytes: 3));
        self::assertSame('hij', Files::read(path: $path, offsetBytes: 7));

        // A length past the end is clamped, as fread() clamps it.
        self::assertSame('ij', Files::read(path: $path, offsetBytes: 8, lengthBytes: 100));
    }

    public function testAppendModeKeepsWhatWasThere(): void
    {
        $path = $this->path(name: 'append.log');

        Files::write(path: $path, contents: 'first ');
        Files::write(path: $path, contents: 'second', mode: FileWriteMode::Append);

        self::assertSame('first second', Files::read(path: $path));
    }

    public function testReplaceModeTruncatesAShorterWriteInstead(): void
    {
        $path = $this->path(name: 'replace.txt');

        Files::write(path: $path, contents: 'a long first version');
        Files::write(path: $path, contents: 'short');

        self::assertSame('short', Files::read(path: $path));
    }

    public function testCreateModeRefusesAnExistingFile(): void
    {
        $path = $this->path(name: 'create.txt');

        Files::write(path: $path, contents: 'first', mode: FileWriteMode::Create);

        $this->expectException(FileAlreadyExistsException::class);

        Files::write(path: $path, contents: 'second', mode: FileWriteMode::Create);
    }

    public function testCreateModeLeavesTheExistingContentsAlone(): void
    {
        $path = $this->path(name: 'create-keeps.txt');

        Files::write(path: $path, contents: 'original');

        try {
            Files::write(path: $path, contents: 'replacement', mode: FileWriteMode::Create);
        } catch (FileAlreadyExistsException) {
            //
        }

        // The refused write must not have taken the file with it: the cleanup of a failed
        // write removes what it created, and here it created nothing.
        self::assertSame('original', Files::read(path: $path));
    }

    public function testWritePermissionsApplyToACreatedFile(): void
    {
        $path = $this->path(name: 'perm.txt');

        Files::write(path: $path, contents: 'x', permissions: 0600);

        clearstatcache(true, $path);

        self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
    }

    public function testAtomicWriteReplacesTheContentsWhole(): void
    {
        $path = $this->path(name: 'atomic.json');

        Files::write(path: $path, contents: '{"old":true}');
        Files::writeAtomic(path: $path, contents: '{"new":true}');

        self::assertSame('{"new":true}', Files::read(path: $path));

        // The temporary file is a sibling, and it must be gone once the rename landed.
        self::assertSame(
            [],
            glob($this->directory . '/*.sconcur-tmp') ?: [],
        );
    }

    public function testAtomicWriteCreatesAMissingFile(): void
    {
        $path = $this->path(name: 'atomic-new.json');

        $written = Files::writeAtomic(path: $path, contents: 'fresh');

        self::assertSame(5, $written);
        self::assertSame('fresh', Files::read(path: $path));
    }

    public function testTruncateCutsAFile(): void
    {
        $path = $this->path(name: 'truncate.txt');

        Files::write(path: $path, contents: 'abcdefghij');
        Files::truncate(path: $path, sizeBytes: 4);

        self::assertSame('abcd', Files::read(path: $path));
    }

    public function testCopyMovesTheBytesWithoutThemCrossingIntoPhp(): void
    {
        $sizeBytes = 8 * 1024 * 1024;

        $source      = $this->path(name: 'source.bin');
        $destination = $this->path(name: 'destination.bin');

        // Written natively, so the write does not colour the measurement below.
        file_put_contents($source, str_repeat('x', $sizeBytes));

        // The claim under test is not "the copy works" — it is that the payload never
        // enters this process. So the assertion is on the memory: an eight-megabyte file
        // that crossed the boundary would show up here, and one copied inside the
        // extension cannot.
        // Reset first. The peak is a process-lifetime high-water mark, so without this
        // the delta measures nothing once anything earlier in the suite has peaked above
        // the file's size — and the assertion below would pass by going blind rather
        // than by the bytes staying out of PHP.
        memory_reset_peak_usage();

        $before = memory_get_peak_usage(true);

        $copied = Files::copy(source: $source, destination: $destination);

        $grown = memory_get_peak_usage(true) - $before;

        self::assertSame($sizeBytes, $copied);
        self::assertSame($sizeBytes, filesize($destination));

        // A quarter of the file would be 2 MiB, which is exactly PHP's allocator chunk
        // — a single new chunk would land on the boundary. Half leaves room for one.
        self::assertLessThan(
            $sizeBytes / 2,
            $grown,
            "The copy grew the PHP heap by $grown bytes; the file is $sizeBytes.",
        );

        // And the instrument has bite: the same file read through the boundary does move
        // the peak. Without this the assertion above could pass because nothing is being
        // measured at all.
        memory_reset_peak_usage();

        $beforeRead = memory_get_peak_usage(true);

        $contents = Files::read(path: $source, maxReadBytes: 0);

        self::assertSame($sizeBytes, strlen($contents));

        self::assertGreaterThan(
            $sizeBytes / 2,
            memory_get_peak_usage(true) - $beforeRead,
            'Reading the file did not move the peak, so the measurement proves nothing.',
        );
    }

    public function testCopyRefusesAnExistingDestinationInCreateMode(): void
    {
        $source      = $this->path(name: 'copy-source.txt');
        $destination = $this->path(name: 'copy-destination.txt');

        Files::write(path: $source, contents: 'source');
        Files::write(path: $destination, contents: 'destination');

        try {
            Files::copy(
                source: $source,
                destination: $destination,
                mode: FileWriteMode::Create,
            );

            self::fail('An existing destination was not refused.');
        } catch (FileAlreadyExistsException) {
            //
        }

        self::assertSame('destination', Files::read(path: $destination));
    }

    public function testMoveReplacesAnExistingDestination(): void
    {
        $source      = $this->path(name: 'replacing-source.txt');
        $destination = $this->path(name: 'replacing-destination.txt');

        Files::write(path: $source, contents: 'the new one');
        Files::write(path: $destination, contents: 'the old one');

        Files::move(source: $source, destination: $destination);

        // rename(2) replaces, and there is no portable way to make it refuse — so this
        // is the documented behaviour rather than an oversight. The test exists to make
        // a change to it deliberate.
        self::assertSame('the new one', Files::read(path: $destination));
        self::assertFalse(Files::exists(path: $source));
    }

    public function testCopyTakesItsBufferSizeWithoutChangingTheResult(): void
    {
        $source      = $this->path(name: 'buffered-source.bin');
        $destination = $this->path(name: 'buffered-destination.bin');

        $contents = random_bytes(300_000);

        Files::write(path: $source, contents: $contents);

        // A tiny buffer means many turns of the copy loop; a hostile one is clamped
        // rather than handed to the allocator. Both must move the same bytes.
        foreach ([64, PHP_INT_MAX] as $bufferSizeBytes) {
            Files::delete(path: $destination, missingOk: true);

            $copied = Files::copy(
                source: $source,
                destination: $destination,
                bufferSizeBytes: $bufferSizeBytes,
            );

            self::assertSame(300_000, $copied);
            self::assertSame($contents, Files::read(path: $destination));
        }
    }

    public function testCopyCanAppendToItsDestination(): void
    {
        $source      = $this->path(name: 'append-source.txt');
        $destination = $this->path(name: 'append-destination.txt');

        Files::write(path: $source, contents: 'second');
        Files::write(path: $destination, contents: 'first ');

        Files::copy(
            source: $source,
            destination: $destination,
            mode: FileWriteMode::Append,
        );

        self::assertSame('first second', Files::read(path: $destination));
    }

    public function testAReplaceThatFailsLeavesThePartialFileRatherThanNone(): void
    {
        $path = $this->path(name: 'replace-failure.txt');

        Files::write(path: $path, contents: 'the previous version');

        // A directory cannot be copied, so the copy fails after opening its destination
        // — which is the shape of any failure past the open, a full disk included.
        try {
            Files::copy(source: $this->directory, destination: $path);

            self::fail('Copying a directory was not refused.');
        } catch (FilesException) {
            //
        }

        // Replace truncated the old contents on open; removing the file on top of that
        // would destroy an inode this call never created. Undo the rule in
        // files::creates_the_file and this file is gone.
        self::assertTrue(Files::exists(path: $path));
    }

    /**
     * A stopped group must unwind a coroutine that is inside a file stream with the
     * deliberate signal, not with a translated FilesException — Files::execute() and
     * BatchIterator catch only the task exceptions for exactly this reason.
     *
     * The previous version of this test threw FlowStoppedException itself and caught it
     * one line later, so no feature code sat between the two and the assertion was
     * guaranteed by the catch clause.
     */
    public function testAStoppedGroupUnwindsAStreamWithTheStopSignal(): void
    {
        $path = $this->path(name: 'stopped.log');

        Files::write(path: $path, contents: str_repeat("line\n", 200_000));

        $waitGroup = WaitGroup::create();

        $caught = null;

        $waitGroup->add(
            callback: static function () use ($path, &$caught): void {
                try {
                    // One line per crossing over two hundred thousand of them: the
                    // coroutine is inside a batch when the stop lands.
                    foreach (Files::readLines(path: $path, batchLines: 1, bufferSizeBytes: 64) as $line) {
                        // Read until somebody stops us.
                    }
                } catch (Throwable $exception) {
                    $caught = $exception;

                    // Re-thrown, the way a handler must let the unwind signal through:
                    // swallowing it would leave the group thinking the coroutine ended
                    // of its own accord.
                    throw $exception;
                }
            },
        );

        $waitGroup->add(
            callback: static function () use ($waitGroup): void {
                Files::exists(path: '/');

                $waitGroup->stop();
            },
        );

        try {
            $waitGroup->waitAll();
        } catch (Throwable) {
            //
        }

        self::assertInstanceOf(FlowStoppedException::class, $caught);
    }

    public function testMoveRenamesTheFile(): void
    {
        $source      = $this->path(name: 'move-source.txt');
        $destination = $this->path(name: 'move-destination.txt');

        Files::write(path: $source, contents: 'moved');
        Files::move(source: $source, destination: $destination);

        self::assertFalse(file_exists($source));
        self::assertSame('moved', Files::read(path: $destination));
    }

    public function testDeleteRemovesTheFile(): void
    {
        $path = $this->path(name: 'delete.txt');

        Files::write(path: $path, contents: 'gone soon');

        self::assertTrue(Files::delete(path: $path));
        self::assertFalse(file_exists($path));
    }

    public function testDeleteOfAMissingFileIsRefusedUnlessAllowed(): void
    {
        $path = $this->path(name: 'never-existed.txt');

        self::assertFalse(Files::delete(path: $path, missingOk: true));

        $this->expectException(FileNotFoundException::class);

        Files::delete(path: $path);
    }

    public function testReadingAMissingFileNamesThePath(): void
    {
        $path = $this->path(name: 'absent.txt');

        try {
            Files::read(path: $path);

            self::fail('A missing file was not refused.');
        } catch (FileNotFoundException $exception) {
            self::assertStringContainsString($path, $exception->getMessage());
            self::assertInstanceOf(FilesException::class, $exception);
        }
    }

    public function testReadingADirectoryIsRefusedByType(): void
    {
        $this->expectException(UnexpectedFileTypeException::class);

        Files::read(path: $this->directory);
    }

    public function testReadRefusesAFileOverTheLimit(): void
    {
        $path = $this->path(name: 'big.bin');

        Files::write(path: $path, contents: str_repeat('x', 8192));

        try {
            Files::read(path: $path, maxReadBytes: 1024);

            self::fail('A file over the limit was not refused.');
        } catch (FileTooLargeException $exception) {
            self::assertStringContainsString('1024', $exception->getMessage());
        }

        // The limit is a guard, not a property of the file: lifting it reads the same file.
        self::assertSame(8192, strlen(Files::read(path: $path, maxReadBytes: 0)));
    }

    public function testANegativeRangeIsAUsageError(): void
    {
        $path = $this->path(name: 'negative.txt');

        Files::write(path: $path, contents: 'abc');

        // A LogicException on purpose: it is a bug in the call, and it must not be caught
        // by a handler written for the filesystem's own failures.
        $this->expectException(InvalidFileArgumentException::class);

        Files::read(path: $path, offsetBytes: -1);
    }

    public function testEveryOperationInOneGroupAnswersItsOwnResult(): void
    {
        // Named for what it checks: that results do not cross between coroutines. That
        // they overlap in time is proved by FilesTest, which runs on BaseAsyncTestCase
        // and asserts the event order only interleaving can produce.
        $waitGroup = WaitGroup::create();

        for ($index = 0; $index < 8; ++$index) {
            $path = $this->path(name: "concurrent-$index.bin");

            $waitGroup->add(
                callback: static function () use ($path, $index): int {
                    Files::write(path: $path, contents: str_repeat((string) $index, 65_536));

                    return strlen(Files::read(path: $path));
                },
            );
        }

        $results = $waitGroup->waitResults();

        self::assertCount(8, $results);

        foreach ($results as $result) {
            self::assertSame(65_536, $result);
        }
    }

    protected function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }
}
