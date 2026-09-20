<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Files;

use SConcur\Exceptions\Files\FileAlreadyExistsException;
use SConcur\Exceptions\Files\FileNotFoundException;
use SConcur\Exceptions\Files\FilesException;
use SConcur\Exceptions\Files\FileTooLargeException;
use SConcur\Exceptions\Files\InvalidFileArgumentException;
use SConcur\Exceptions\Files\UnexpectedFileTypeException;
use SConcur\Features\Files\Files;
use SConcur\Features\Files\FileWriteMode;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

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
        $source      = $this->path(name: 'source.bin');
        $destination = $this->path(name: 'destination.bin');

        $contents = random_bytes(512 * 1024);

        Files::write(path: $source, contents: $contents);

        $copied = Files::copy(source: $source, destination: $destination);

        self::assertSame(512 * 1024, $copied);
        self::assertSame($contents, file_get_contents($destination));
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

    public function testOperationsInOneGroupRunConcurrently(): void
    {
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
