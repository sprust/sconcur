<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Files;

use PHPUnit\Framework\Attributes\DataProvider;
use SConcur\Exceptions\Files\FileNotFoundException;
use SConcur\Exceptions\Files\UnexpectedFileTypeException;
use SConcur\Features\Files\FileHashAlgorithm;
use SConcur\Features\Files\Files;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

/**
 * hashFile against the native hash_file it replaces. Every algorithm is checked both
 * ways: a digest the extension computed must be the one PHP computes, or a checksum
 * written by one side and verified by the other is worthless.
 */
class FilesHashTest extends BaseTestCase
{
    protected string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/sconcur-files-hash-' . uniqid();

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

    /**
     * @return array<string, array{FileHashAlgorithm, string}>
     */
    public static function algorithmProvider(): array
    {
        return [
            'sha256' => [FileHashAlgorithm::Sha256, 'sha256'],
            'sha512' => [FileHashAlgorithm::Sha512, 'sha512'],
            'sha1'   => [FileHashAlgorithm::Sha1, 'sha1'],
            'md5'    => [FileHashAlgorithm::Md5, 'md5'],
        ];
    }

    #[DataProvider('algorithmProvider')]
    public function testEveryAlgorithmAgreesWithTheNativeOne(
        FileHashAlgorithm $algorithm,
        string $nativeName,
    ): void {
        $path = $this->path(name: 'hashed.bin');

        // Binary, and longer than the extension's 64 KiB read buffer, so the loop runs
        // several turns — where a buffered digest goes wrong if it goes wrong at all.
        $contents = random_bytes(200_000);

        Files::write(path: $path, contents: $contents);

        self::assertSame(
            hash_file($nativeName, $path),
            Files::hashFile(path: $path, algorithm: $algorithm),
        );
    }

    public function testTheDefaultAlgorithmIsSha256(): void
    {
        $path = $this->path(name: 'default.txt');

        Files::write(path: $path, contents: 'contents');

        self::assertSame(
            hash_file('sha256', $path),
            Files::hashFile(path: $path),
        );
    }

    public function testAnEmptyFileHashesToThePublishedVector(): void
    {
        $path = $this->path(name: 'empty.txt');

        Files::write(path: $path, contents: '');

        self::assertSame(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            Files::hashFile(path: $path),
        );
    }

    public function testADigestIsLowercaseHex(): void
    {
        $path = $this->path(name: 'case.txt');

        Files::write(path: $path, contents: 'x');

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            Files::hashFile(path: $path),
        );
    }

    public function testHashingAMissingFileIsRefused(): void
    {
        $this->expectException(FileNotFoundException::class);

        Files::hashFile(path: $this->path(name: 'absent.bin'));
    }

    public function testHashingADirectoryIsRefusedByType(): void
    {
        $this->expectException(UnexpectedFileTypeException::class);

        Files::hashFile(path: $this->directory);
    }

    public function testManyFilesAreHashedConcurrentlyInOneGroup(): void
    {
        $paths = [];

        for ($index = 0; $index < 8; ++$index) {
            $path = $this->path(name: "concurrent-$index.bin");

            Files::write(path: $path, contents: str_repeat((string) $index, 100_000));

            $paths[] = $path;
        }

        $waitGroup = WaitGroup::create();

        foreach ($paths as $path) {
            $waitGroup->add(
                callback: static fn(): string => Files::hashFile(path: $path),
            );
        }

        $results = $waitGroup->waitResults();

        self::assertCount(8, $results);

        $expected = array_map(
            static fn(string $path): string => (string) hash_file('sha256', $path),
            $paths,
        );

        sort($expected);
        sort($results);

        self::assertSame($expected, $results);
    }

    protected function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }
}
