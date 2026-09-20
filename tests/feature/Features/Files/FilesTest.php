<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Files;

use SConcur\Exceptions\Files\FileNotFoundException;
use SConcur\Features\Files\Files;
use SConcur\Features\Files\FileWriteMode;
use SConcur\Tests\Feature\BaseAsyncTestCase;
use Throwable;

/**
 * The feature's async contract: two coroutines each doing file work interleave instead of
 * running one after the other.
 *
 * Concurrency is asserted through the event order the parent enforces, not through a
 * stopwatch: '2:start' can only be reached because the first coroutine suspended inside
 * its file operation, and on a page-cached filesystem no timing assertion would be stable
 * enough to mean anything.
 */
class FilesTest extends BaseAsyncTestCase
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
            unlink($path);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    protected function on_1_start(): void
    {
        Files::write(
            path: $this->path(name: 'one.txt'),
            contents: str_repeat('a', 1_048_576),
        );
    }

    protected function on_1_middle(): void
    {
        Files::copy(
            source: $this->path(name: 'one.txt'),
            destination: $this->path(name: 'one.copy.txt'),
        );
    }

    protected function on_2_start(): void
    {
        Files::write(
            path: $this->path(name: 'two.txt'),
            contents: str_repeat('b', 1_048_576),
        );
    }

    protected function on_2_middle(): void
    {
        Files::copy(
            source: $this->path(name: 'two.txt'),
            destination: $this->path(name: 'two.copy.txt'),
        );
    }

    protected function on_iterate(): void
    {
        Files::writeAtomic(
            path: $this->path(name: 'iterate.txt'),
            contents: 'iterate',
        );
    }

    protected function on_exception(): void
    {
        Files::read(path: $this->path(name: 'does-not-exist.txt'));
    }

    protected function assertException(Throwable $exception): void
    {
        self::assertStringContainsString('does-not-exist.txt', $exception->getMessage());

        // Synchronously the caller gets the named exception itself; inside a coroutine the
        // group wraps a failed callback, so the named one is further down the chain.
        $current = $exception;

        while ($current !== null) {
            if ($current instanceof FileNotFoundException) {
                return;
            }

            $current = $current->getPrevious();
        }

        self::fail('No FileNotFoundException in the chain: ' . $exception::class);
    }

    /**
     * @param array<string, mixed> $results
     */
    protected function assertResult(array $results): void
    {
        // Each coroutine wrote its own megabyte and copied it; the copies are what prove
        // the work actually happened rather than the calls merely returning.
        self::assertSame(
            str_repeat('a', 1_048_576),
            Files::read(path: $this->path(name: 'one.copy.txt')),
        );

        self::assertSame(
            str_repeat('b', 1_048_576),
            Files::read(path: $this->path(name: 'two.copy.txt')),
        );

        self::assertSame(
            'iterate',
            Files::read(path: $this->path(name: 'iterate.txt')),
        );

        // The mode reached the extension: a second Create on the same path is refused.
        $written = Files::write(
            path: $this->path(name: 'created.txt'),
            contents: 'once',
            mode: FileWriteMode::Create,
        );

        self::assertSame(4, $written);
    }

    protected function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }
}
