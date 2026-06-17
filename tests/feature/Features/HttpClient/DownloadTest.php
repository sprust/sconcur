<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpClient;

use SConcur\Exceptions\HttpClient\DownloadException;
use SConcur\Features\HttpClient\DownloadFileMode;
use SConcur\Features\HttpClient\HttpClientOptions;
use SConcur\WaitGroup;

class DownloadTest extends BaseHttpClientTestCase
{
    /** @var list<string> */
    protected array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testDownloadsBodyToFile(): void
    {
        $size   = 200_000;
        $path   = $this->tempPath();
        $client = $this->client();

        $result = $client->download(
            request: $this->request('GET', '/big/' . $size),
            path: $path,
        );

        self::assertSame(200, $result->statusCode);
        self::assertSame($size, $result->filesizeBytes);
        self::assertNotEmpty($result->headers);
        self::assertGreaterThanOrEqual(0, $result->executionMs);
        self::assertSame($size, filesize($path));

        // The file must match the streamed body of the same route byte for byte.
        $streamed = (string) $client->sendRequest($this->request('GET', '/big/' . $size))->getBody();

        self::assertSame($streamed, (string) file_get_contents($path));
    }

    public function testNon2xxThrowsWithStatusAndLeavesNoFile(): void
    {
        $path = $this->tempPath();

        try {
            $this->client()->download(
                request: $this->request('GET', '/status/404'),
                path: $path,
            );

            self::fail('Expected a DownloadException.');
        } catch (DownloadException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }

        self::assertFileDoesNotExist($path);
    }

    public function testCreateModeFailsWhenFileExists(): void
    {
        $path = $this->tempPath();

        file_put_contents($path, 'existing');

        $this->expectException(DownloadException::class);

        $this->client()->download(
            request: $this->request('GET', '/big/100'),
            path: $path,
            mode: DownloadFileMode::Create,
        );
    }

    public function testReplaceModeTruncates(): void
    {
        $path   = $this->tempPath();
        $client = $this->client();

        $client->download(
            request: $this->request('GET', '/big/500'),
            path: $path,
            mode: DownloadFileMode::Replace,
        );

        $client->download(
            request: $this->request('GET', '/big/50'),
            path: $path,
            mode: DownloadFileMode::Replace,
        );

        clearstatcache(true, $path);

        self::assertSame(50, filesize($path));
    }

    public function testAppendModeAppends(): void
    {
        $path   = $this->tempPath();
        $client = $this->client();

        $client->download(
            request: $this->request('GET', '/big/100'),
            path: $path,
            mode: DownloadFileMode::Replace,
        );

        $client->download(
            request: $this->request('GET', '/big/100'),
            path: $path,
            mode: DownloadFileMode::Append,
        );

        clearstatcache(true, $path);

        self::assertSame(200, filesize($path));
    }

    public function testCustomBufferSize(): void
    {
        $size   = 200_000;
        $path   = $this->tempPath();
        $client = $this->client();

        $result = $client->download(
            request: $this->request('GET', '/big/' . $size),
            path: $path,
            bufferSizeBytes: 4096,
        );

        self::assertSame(200, $result->statusCode);
        self::assertSame($size, $result->filesizeBytes);
        self::assertSame($size, filesize($path));
    }

    public function testSinkFileOpenErrorThrows(): void
    {
        // A 2xx response, but the sink file cannot be opened for writing (missing
        // directory here; the same path covers a permission denial or a directory
        // path). The error is a file error, so getStatusCode() is null.
        $exception = null;

        try {
            $this->client()->download(
                request: $this->request('GET', '/big/100'),
                path: '/nonexistent/sconcur/dir/file',
            );
        } catch (DownloadException $exception) {
            //
        }

        self::assertInstanceOf(DownloadException::class, $exception);
        self::assertNull($exception->getStatusCode());
    }

    public function testNetworkErrorThrows(): void
    {
        $client  = $this->client(new HttpClientOptions(connectTimeoutMs: 500));
        $request = $this->factory->createRequest('GET', 'http://127.0.0.1:1/nope');

        $exception = null;

        try {
            $client->download(
                request: $request,
                path: $this->tempPath(),
            );
        } catch (DownloadException $exception) {
            //
        }

        self::assertInstanceOf(DownloadException::class, $exception);
        self::assertNull($exception->getStatusCode());
    }

    public function testConcurrentDownloadsFanOut(): void
    {
        $client = $this->client();
        $sizes  = [1_000, 2_000, 3_000, 4_000];
        $paths  = [];

        foreach ($sizes as $index => $size) {
            $paths[$index] = $this->tempPath();
        }

        $waitGroup = WaitGroup::create();

        foreach ($sizes as $index => $size) {
            $waitGroup->add(
                callback: function () use ($client, $size, $paths, $index): int {
                    return $client->download(
                        request: $this->request('GET', '/big/' . $size),
                        path: $paths[$index],
                    )->statusCode;
                },
            );
        }

        $waitGroup->waitAll();

        foreach ($sizes as $index => $size) {
            clearstatcache(true, $paths[$index]);

            self::assertSame($size, filesize($paths[$index]));
        }
    }

    protected function tempPath(): string
    {
        $path = sys_get_temp_dir() . '/sconcur_download_' . getmypid() . '_' . count($this->paths);

        $this->paths[] = $path;

        return $path;
    }
}
