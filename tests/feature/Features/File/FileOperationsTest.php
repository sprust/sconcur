<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\File;

use SConcur\Exceptions\File\FileException;
use SConcur\Exceptions\File\InvalidFileModeException;
use SConcur\Features\File\FileSystem;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\FileStorage;
use Throwable;

class FileOperationsTest extends BaseTestCase
{
    use FileStorage;

    protected FileSystem $fileSystem;

    /** @var list<string> */
    protected array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileSystem = new FileSystem();
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testWriteThenReadRoundTrip(): void
    {
        $path = $this->tempPath();

        $file = $this->fileSystem->open(path: $path, mode: 'w+');

        $written = $file->write('hello world');

        self::assertSame(11, $written);
        self::assertSame(11, $file->tell());

        $file->rewind();

        self::assertSame('hello world', $file->getContents());

        $file->close();
    }

    public function testWriteModeTruncates(): void
    {
        $path = $this->tempPath();

        $this->fileSystem->write(path: $path, data: 'original content');

        $file = $this->fileSystem->open(path: $path, mode: 'w');
        $file->close();

        self::assertSame('', $this->fileSystem->read($path));
    }

    public function testAppendMode(): void
    {
        $path = $this->tempPath();

        $this->fileSystem->write(path: $path, data: 'AAA');
        $this->fileSystem->append(path: $path, data: 'BBB');

        self::assertSame('AAABBB', $this->fileSystem->read($path));
    }

    public function testCreateModeDoesNotTruncate(): void
    {
        $path = $this->tempPath();

        $this->fileSystem->write(path: $path, data: 'AAA');

        $file = $this->fileSystem->open(path: $path, mode: 'c');
        $file->write('B');
        $file->close();

        self::assertSame('BAA', $this->fileSystem->read($path));
    }

    public function testExclusiveModeFailsWhenFileExists(): void
    {
        $path = $this->tempPath();

        $this->fileSystem->write(path: $path, data: 'AAA');

        $exception = $this->catch(function () use ($path): void {
            $this->fileSystem->open(path: $path, mode: 'x');
        });

        self::assertInstanceOf(Throwable::class, $exception);
        self::assertStringContainsString('file:', $exception->getMessage());
    }

    public function testReadWriteMode(): void
    {
        $path = $this->tempPath();

        $this->fileSystem->write(path: $path, data: 'AAA');

        $file = $this->fileSystem->open(path: $path, mode: 'r+');

        self::assertSame('AAA', $file->read(10));
        self::assertTrue($file->eof());

        $file->write('B');
        $file->close();

        self::assertSame('AAAB', $this->fileSystem->read($path));
    }

    public function testSeekAndTell(): void
    {
        $path = $this->tempPath();

        $file = $this->fileSystem->open(path: $path, mode: 'w+');
        $file->write('0123456789');

        self::assertSame(3, $file->seek(3));
        self::assertSame(3, $file->tell());
        self::assertSame('345', $file->read(3));

        self::assertSame(8, $file->seek(-2, SEEK_END));
        self::assertSame('89', $file->read(5));

        $file->close();
    }

    public function testEofTrackingAcrossReads(): void
    {
        $path = $this->tempPath();

        $file = $this->fileSystem->open(path: $path, mode: 'w+');
        $file->write('abc');
        $file->rewind();

        self::assertFalse($file->eof());
        self::assertSame('abc', $file->read(3));

        // The full content is read, but EOF is only reported on the next read.
        self::assertSame('', $file->read(3));
        self::assertTrue($file->eof());

        $file->close();
    }

    public function testTruncate(): void
    {
        $path = $this->tempPath();

        $file = $this->fileSystem->open(path: $path, mode: 'w+');
        $file->write('0123456789');

        $file->truncate(4);
        $file->rewind();

        self::assertSame('0123', $file->getContents());

        $file->close();
    }

    public function testStat(): void
    {
        $path = $this->tempPath();

        $file = $this->fileSystem->open(path: $path, mode: 'w+');
        $file->write('twelve bytes');

        $stat = $file->stat();

        self::assertSame(12, $stat->size);
        self::assertGreaterThan(0, $stat->modifiedAtMs);
        self::assertGreaterThan(0, $stat->mode);

        $file->close();
    }

    public function testFlush(): void
    {
        $path = $this->tempPath();

        $file = $this->fileSystem->open(path: $path, mode: 'w+');
        $file->write('persist me');
        $file->flush();

        self::assertSame('persist me', file_get_contents($path));

        $file->close();
    }

    public function testGetContentsStreamsLargeFile(): void
    {
        $path = $this->tempPath();

        $content = str_repeat('A', 200_000);

        $this->fileSystem->write(path: $path, data: $content);

        $file = $this->fileSystem->open(path: $path, mode: 'r');

        self::assertSame($content, $file->getContents());
        self::assertTrue($file->eof());

        $file->close();
    }

    public function testBinarySafeReadWrite(): void
    {
        $path = $this->tempPath();

        $binary = "\x00\x01\x02\xff\xfemiddle\x00tail";

        $file = $this->fileSystem->open(path: $path, mode: 'w+');
        $file->write($binary);
        $file->rewind();

        self::assertSame($binary, $file->getContents());

        $file->close();
    }

    public function testFileSystemReadWriteAppendHelpers(): void
    {
        $path = $this->tempPath();

        self::assertSame(5, $this->fileSystem->write(path: $path, data: 'first'));
        self::assertSame('first', $this->fileSystem->read($path));

        $this->fileSystem->append(path: $path, data: '-second');

        self::assertSame('first-second', $this->fileSystem->read($path));
    }

    public function testExists(): void
    {
        $path = $this->tempPath();

        self::assertFalse($this->fileSystem->exists($path));

        $this->fileSystem->write(path: $path, data: 'x');

        self::assertTrue($this->fileSystem->exists($path));
    }

    public function testInvalidModeThrows(): void
    {
        $this->expectException(InvalidFileModeException::class);

        $this->fileSystem->open(path: $this->tempPath(), mode: 'z+');
    }

    public function testWriteToReadOnlyHandleThrows(): void
    {
        $path = $this->tempPath();

        $this->fileSystem->write(path: $path, data: 'AAA');

        $file = $this->fileSystem->open(path: $path, mode: 'r');

        try {
            $this->expectException(FileException::class);

            $file->write('B');
        } finally {
            $file->close();
        }
    }

    public function testReadFromWriteOnlyHandleThrows(): void
    {
        $path = $this->tempPath();

        $file = $this->fileSystem->open(path: $path, mode: 'w');

        try {
            $this->expectException(FileException::class);

            $file->read(4);
        } finally {
            $file->close();
        }
    }

    public function testOpenMissingFileReadOnlyThrows(): void
    {
        $exception = $this->catch(function (): void {
            $this->fileSystem->open(path: '/nonexistent/sconcur/missing', mode: 'r');
        });

        self::assertInstanceOf(Throwable::class, $exception);
        self::assertStringContainsString('file:', $exception->getMessage());
    }

    public function testOperationAfterCloseThrows(): void
    {
        $path = $this->tempPath();

        $file = $this->fileSystem->open(path: $path, mode: 'w+');
        $file->close();

        $this->expectException(FileException::class);

        $file->read(4);
    }

    public function testWriteAfterCloseThrows(): void
    {
        $path = $this->tempPath();

        $file = $this->fileSystem->open(path: $path, mode: 'w+');
        $file->close();

        $this->expectException(FileException::class);

        $file->write('x');
    }

    public function testDoubleCloseIsNoop(): void
    {
        $path = $this->tempPath();

        $file = $this->fileSystem->open(path: $path, mode: 'w+');

        $file->close();
        $file->close();

        // No exception, and the held handle task is released exactly once.
        self::assertSame(0, $this->extension->count());
    }

    public function testInvalidSeekThrows(): void
    {
        $path = $this->tempPath();

        $file = $this->fileSystem->open(path: $path, mode: 'w+');

        $exception = $this->catch(function () use ($file): void {
            $file->seek(-5, SEEK_SET);
        });

        $file->close();

        self::assertInstanceOf(Throwable::class, $exception);
        self::assertStringContainsString('file:', $exception->getMessage());
    }

    public function testNegativeTruncateThrows(): void
    {
        $path = $this->tempPath();

        $file = $this->fileSystem->open(path: $path, mode: 'w+');

        $exception = $this->catch(function () use ($file): void {
            $file->truncate(-1);
        });

        $file->close();

        self::assertInstanceOf(Throwable::class, $exception);
        self::assertStringContainsString('file:', $exception->getMessage());
    }

    public function testWriteToNonexistentDirectoryThrows(): void
    {
        $exception = $this->catch(function (): void {
            $this->fileSystem->write(path: '/nonexistent/sconcur/dir/file', data: 'x');
        });

        self::assertInstanceOf(Throwable::class, $exception);
        self::assertStringContainsString('file:', $exception->getMessage());
    }

    public function testReadZeroLengthReturnsEmpty(): void
    {
        $path = $this->tempPath();

        $file = $this->fileSystem->open(path: $path, mode: 'w+');
        $file->write('abc');
        $file->rewind();

        self::assertSame('', $file->read(0));
        self::assertSame(0, $file->tell());

        $file->close();
    }

    protected function catch(callable $callback): ?Throwable
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    protected function tempPath(): string
    {
        $path = $this->fileStoragePath('ops_' . getmypid() . '_' . count($this->paths));

        $this->paths[] = $path;

        return $path;
    }
}
