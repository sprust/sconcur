<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\File;

use SConcur\Features\File\File;
use SConcur\Features\File\FileSystem;
use SConcur\Tests\Feature\BaseAsyncTestCase;
use SConcur\Tests\Impl\FileStorage;
use Throwable;

class FileTest extends BaseAsyncTestCase
{
    use FileStorage;

    protected FileSystem $fileSystem;

    protected string $path1;
    protected string $path2;
    protected string $scratchPath;

    protected ?File $file1 = null;
    protected ?File $file2 = null;

    protected string $read1 = '';
    protected string $read2 = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileSystem = new FileSystem();

        $this->path1       = $this->tempPath('1');
        $this->path2       = $this->tempPath('2');
        $this->scratchPath = $this->tempPath('scratch');
    }

    protected function tearDown(): void
    {
        foreach ([$this->path1, $this->path2, $this->scratchPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    protected function on_1_start(): void
    {
        $this->file1 = $this->fileSystem->open(path: $this->path1, mode: 'w+');

        $this->file1->write('hello-1');
    }

    protected function on_1_middle(): void
    {
        $this->file1->rewind();

        $this->read1 = $this->file1->getContents();

        $this->file1->close();
    }

    protected function on_2_start(): void
    {
        $this->file2 = $this->fileSystem->open(path: $this->path2, mode: 'w+');

        $this->file2->write('hello-2');
    }

    protected function on_2_middle(): void
    {
        $this->file2->rewind();

        $this->read2 = $this->file2->getContents();

        $this->file2->close();
    }

    protected function on_iterate(): void
    {
        $this->fileSystem->write(path: $this->scratchPath, data: 'x');
    }

    protected function on_exception(): void
    {
        // Opening a missing file read-only fails on the Go side.
        $this->fileSystem->open(path: '/nonexistent/sconcur/file', mode: 'r');
    }

    protected function assertException(Throwable $exception): void
    {
        self::assertStringContainsString('file:', $exception->getMessage());
    }

    protected function assertResult(array $results): void
    {
        self::assertSame('hello-1', $this->read1);
        self::assertSame('hello-2', $this->read2);
    }

    protected function tempPath(string $suffix): string
    {
        return $this->fileStoragePath('test_' . getmypid() . '_' . $suffix);
    }
}
