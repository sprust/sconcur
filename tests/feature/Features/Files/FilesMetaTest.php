<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Files;

use SConcur\Exceptions\Files\FileAlreadyExistsException;
use SConcur\Exceptions\Files\FileNotFoundException;
use SConcur\Exceptions\Files\InvalidFileArgumentException;
use SConcur\Exceptions\Files\UnexpectedFileTypeException;
use SConcur\Features\Files\Dto\DirectoryEntry;
use SConcur\Features\Files\Files;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

/**
 * Metadata and directories, checked against the native functions they replace.
 */
class FilesMetaTest extends BaseTestCase
{
    protected string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/sconcur-files-meta-' . uniqid();

        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree(path: $this->directory);

        parent::tearDown();
    }

    public function testStatAnswersTheSameAsTheNativeFunctions(): void
    {
        $path = $this->path(name: 'stat.txt');

        Files::write(path: $path, contents: 'abcdef', permissions: 0640);

        clearstatcache(true, $path);

        $fileStat = Files::stat(path: $path);

        self::assertTrue($fileStat->exists);
        self::assertTrue($fileStat->isFile);
        self::assertFalse($fileStat->isDirectory);
        self::assertFalse($fileStat->isSymlink);
        self::assertSame(filesize($path), $fileStat->sizeBytes);
        self::assertSame(0640, $fileStat->permissions);
        self::assertSame(fileowner($path), $fileStat->userId);

        // The times come back in milliseconds, so the native seconds are the truncation.
        self::assertSame(filemtime($path), intdiv($fileStat->modifiedAtMs, 1000));
    }

    public function testStatOfAMissingPathIsAnAnswerNotAFailure(): void
    {
        $fileStat = Files::stat(path: $this->path(name: 'nothing-here'));

        self::assertFalse($fileStat->exists);
        self::assertSame(0, $fileStat->sizeBytes);
    }

    public function testStatDescribesADirectory(): void
    {
        $fileStat = Files::stat(path: $this->directory);

        self::assertTrue($fileStat->exists);
        self::assertTrue($fileStat->isDirectory);
        self::assertFalse($fileStat->isFile);
    }

    public function testStatSeesASymlinkOrWhatItPointsAt(): void
    {
        $target = $this->path(name: 'target.txt');
        $link   = $this->path(name: 'link.txt');

        Files::write(path: $target, contents: 'pointed at');
        symlink($target, $link);

        $followed = Files::stat(path: $link);

        self::assertTrue($followed->isFile);
        self::assertFalse($followed->isSymlink);
        self::assertSame(10, $followed->sizeBytes);

        $itself = Files::stat(path: $link, followSymlinks: false);

        self::assertTrue($itself->isSymlink);
        self::assertFalse($itself->isFile);
    }

    public function testExistsIsAReadingOfStat(): void
    {
        $path = $this->path(name: 'exists.txt');

        self::assertFalse(Files::exists(path: $path));

        Files::write(path: $path, contents: 'here');

        self::assertTrue(Files::exists(path: $path));
    }

    public function testChmodChangesThePermissionBits(): void
    {
        $path = $this->path(name: 'chmod.txt');

        Files::write(path: $path, contents: 'x');
        Files::chmod(path: $path, permissions: 0600);

        clearstatcache(true, $path);

        self::assertSame(0600, Files::stat(path: $path)->permissions);
    }

    public function testChmodOutsideTheBitRangeIsAUsageError(): void
    {
        $path = $this->path(name: 'chmod-bad.txt');

        Files::write(path: $path, contents: 'x');

        $this->expectException(InvalidFileArgumentException::class);

        Files::chmod(path: $path, permissions: 0o10000);
    }

    public function testTouchCreatesAMissingFileAndLeavesContentsAlone(): void
    {
        $path = $this->path(name: 'touched.txt');

        Files::touch(path: $path);

        self::assertTrue(Files::exists(path: $path));
        self::assertSame('', Files::read(path: $path));

        Files::write(path: $path, contents: 'kept');
        Files::touch(path: $path);

        self::assertSame('kept', Files::read(path: $path));
    }

    public function testTouchSetsTheModificationTime(): void
    {
        $path = $this->path(name: 'stamped.txt');

        Files::write(path: $path, contents: 'x');

        $modifiedAtMs = 1_700_000_000_000;

        Files::touch(path: $path, modifiedAtMs: $modifiedAtMs);

        clearstatcache(true, $path);

        self::assertSame($modifiedAtMs, Files::stat(path: $path)->modifiedAtMs);
    }

    public function testRealPathResolvesTheSameWayTheNativeOneDoes(): void
    {
        $path = $this->path(name: 'real.txt');

        Files::write(path: $path, contents: 'x');

        $crooked = $this->directory . '/./sub/../real.txt';

        mkdir($this->directory . '/sub');

        self::assertSame(realpath($crooked), Files::realPath(path: $crooked));
    }

    public function testRealPathOfAMissingPathIsRefused(): void
    {
        $this->expectException(FileNotFoundException::class);

        Files::realPath(path: $this->path(name: 'not-real.txt'));
    }

    public function testTemporaryFileIsCreatedAndUnique(): void
    {
        $first  = Files::temporaryFile(directory: $this->directory, prefix: 'upload-');
        $second = Files::temporaryFile(directory: $this->directory, prefix: 'upload-');

        self::assertNotSame($first, $second);

        foreach ([$first, $second] as $path) {
            self::assertTrue(Files::exists(path: $path));
            self::assertStringStartsWith($this->directory . '/upload-', $path);
            self::assertSame(0600, Files::stat(path: $path)->permissions);
        }
    }

    public function testTemporaryFileSuffixEndsTheName(): void
    {
        $path = Files::temporaryFile(
            directory: $this->directory,
            prefix: 'export-',
            suffix: '.csv',
        );

        self::assertStringEndsWith('.csv', $path);
    }

    public function testATemporaryNameMayNotEscapeItsDirectory(): void
    {
        $this->expectException(InvalidFileArgumentException::class);

        Files::temporaryFile(directory: $this->directory, prefix: '../escaped-');
    }

    public function testMakeDirectoryCreatesOneLevel(): void
    {
        $path = $this->path(name: 'level');

        Files::makeDirectory(path: $path);

        self::assertTrue(Files::stat(path: $path)->isDirectory);
    }

    public function testMakeDirectoryNeedsRecursiveForParents(): void
    {
        $path = $this->path(name: 'a/b/c');

        try {
            Files::makeDirectory(path: $path);

            self::fail('A missing parent was not refused.');
        } catch (FileNotFoundException) {
            //
        }

        Files::makeDirectory(path: $path, recursive: true);

        self::assertTrue(Files::stat(path: $path)->isDirectory);

        // mkdir -p semantics: doing it again is a success, not a collision.
        Files::makeDirectory(path: $path, recursive: true);
    }

    public function testMakeDirectoryWithoutRecursiveRefusesAnExistingOne(): void
    {
        $path = $this->path(name: 'twice');

        Files::makeDirectory(path: $path);

        $this->expectException(FileAlreadyExistsException::class);

        Files::makeDirectory(path: $path);
    }

    public function testRemoveDirectoryNeedsRecursiveForAFullOne(): void
    {
        $path = $this->path(name: 'full');

        Files::makeDirectory(path: $path);
        Files::write(path: $path . '/inside.txt', contents: 'x');

        try {
            Files::removeDirectory(path: $path);

            self::fail('A directory with entries was not refused.');
        } catch (UnexpectedFileTypeException) {
            //
        }

        Files::removeDirectory(path: $path, recursive: true);

        self::assertFalse(Files::exists(path: $path));
    }

    public function testRemoveDirectoryOfAMissingPathIsRefusedUnlessAllowed(): void
    {
        $path = $this->path(name: 'never-made');

        Files::removeDirectory(path: $path, missingOk: true);

        $this->expectException(FileNotFoundException::class);

        Files::removeDirectory(path: $path);
    }

    public function testListAnswersTheEntriesSortedByName(): void
    {
        Files::write(path: $this->path(name: 'b.txt'), contents: 'bb');
        Files::write(path: $this->path(name: 'a.txt'), contents: 'a');
        Files::makeDirectory(path: $this->path(name: 'sub'));

        $entries = Files::list(path: $this->directory);

        self::assertSame(
            ['a.txt', 'b.txt', 'sub'],
            array_map(static fn(DirectoryEntry $entry): string => $entry->name, $entries),
        );

        self::assertFalse($entries[0]->isDirectory);
        self::assertTrue($entries[2]->isDirectory);

        // Without metadata those fields say "not asked for" rather than zero.
        self::assertNull($entries[0]->sizeBytes);
        self::assertNull($entries[0]->modifiedAtMs);
    }

    public function testListWithMetadataCarriesTheSizeAndTheTime(): void
    {
        $path = $this->path(name: 'sized.txt');

        Files::write(path: $path, contents: 'abcde');

        $entries = Files::list(path: $this->directory, withMetadata: true);

        self::assertCount(1, $entries);
        self::assertSame(5, $entries[0]->sizeBytes);
        self::assertSame($path, $entries[0]->path);

        clearstatcache(true, $path);

        self::assertSame(filemtime($path), intdiv((int) $entries[0]->modifiedAtMs, 1000));
    }

    public function testListFiltersByPatternInsideTheExtension(): void
    {
        foreach (['one.csv', 'two.csv', 'three.txt', 'log1.csv'] as $name) {
            Files::write(path: $this->path(name: $name), contents: 'x');
        }

        $names = static fn(array $entries): array => array_map(
            static fn(DirectoryEntry $entry): string => $entry->name,
            $entries,
        );

        self::assertSame(
            ['log1.csv', 'one.csv', 'two.csv'],
            $names(Files::list(path: $this->directory, pattern: '*.csv')),
        );

        self::assertSame(
            ['log1.csv'],
            $names(Files::list(path: $this->directory, pattern: 'log?.csv')),
        );

        self::assertSame(
            [],
            $names(Files::list(path: $this->directory, pattern: '*.json')),
        );
    }

    public function testListOfAnEmptyDirectoryIsAnEmptyList(): void
    {
        self::assertSame([], Files::list(path: $this->directory));
    }

    public function testListOfAFileIsRefusedByType(): void
    {
        $path = $this->path(name: 'not-a-directory.txt');

        Files::write(path: $path, contents: 'x');

        $this->expectException(UnexpectedFileTypeException::class);

        Files::list(path: $path);
    }

    public function testEveryStatInOneGroupAnswersItsOwnPath(): void
    {
        // Named for what it checks: that results do not cross between coroutines. That
        // they overlap in time is proved by FilesTest, which runs on BaseAsyncTestCase
        // and asserts the event order only interleaving can produce.
        for ($index = 0; $index < 8; ++$index) {
            Files::write(path: $this->path(name: "stat-$index.txt"), contents: str_repeat('x', $index));
        }

        $waitGroup = WaitGroup::create();

        for ($index = 0; $index < 8; ++$index) {
            $path = $this->path(name: "stat-$index.txt");

            $waitGroup->add(
                callback: static fn(): int => Files::stat(path: $path)->sizeBytes,
            );
        }

        $results = $waitGroup->waitResults();

        sort($results);

        self::assertSame([0, 1, 2, 3, 4, 5, 6, 7], $results);
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
