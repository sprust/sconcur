<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpClient;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use RuntimeException;
use SConcur\Features\HttpClient\HttpClientOptions;
use SConcur\WaitGroup;

/**
 * Synchronous (outside a WaitGroup) edge cases: status/headers/body, multi-value
 * headers, binary echo, response streaming, early abandon, error statuses,
 * network errors and request timeouts. The async/concurrency path is covered by
 * HttpClientConcurrencyTest.
 */
class HttpClientTest extends BaseHttpClientTestCase
{
    public function testStatusHeadersAndBody(): void
    {
        $response = $this->client()->sendRequest($this->request('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string) $response->getBody());
        // A tiny body is sent with Content-Length, so the stream knows its size.
        self::assertSame(2, $response->getBody()->getSize());
    }

    public function testRequestMethodReachesServer(): void
    {
        $response = $this->client()->sendRequest($this->request('DELETE', '/method'));

        self::assertSame('DELETE', (string) $response->getBody());
    }

    public function testRequestBodyIsSent(): void
    {
        $response = $this->client()->sendRequest($this->request('POST', '/echo', 'hello body'));

        self::assertSame('hello body', (string) $response->getBody());
    }

    public function testBinaryBodyRoundTrips(): void
    {
        $binary = random_bytes(2048);

        $response = $this->client()->sendRequest($this->request('POST', '/echo', $binary));

        self::assertSame($binary, (string) $response->getBody());
    }

    public function testQueryStringIsPreserved(): void
    {
        $response = $this->client()->sendRequest($this->request('GET', '/query?a=1&b=two'));

        self::assertSame('a=1&b=two', (string) $response->getBody());
    }

    public function testMultiValueResponseHeaders(): void
    {
        $response = $this->client()->sendRequest($this->request('GET', '/cookies'));

        // Two Set-Cookie headers must survive as two distinct values.
        self::assertSame(['a=1', 'b=2'], $response->getHeader('Set-Cookie'));
    }

    public function testErrorStatusesAreNormalResponses(): void
    {
        foreach ([404, 500] as $code) {
            $response = $this->client()->sendRequest($this->request('GET', '/status/' . $code));

            self::assertSame($code, $response->getStatusCode());
            self::assertSame('status ' . $code, (string) $response->getBody());
        }
    }

    public function testLargeBodyIsStreamedInChunks(): void
    {
        $size = 200_000; // > 64 KiB transport chunk, so the body really streams.

        $response = $this->client()->sendRequest($this->request('GET', '/big/' . $size));

        self::assertSame(200, $response->getStatusCode());

        $stream    = $response->getBody();
        $collected = '';
        $reads     = 0;

        while (!$stream->eof()) {
            $chunk = $stream->read(8192);

            if ($chunk === '') {
                break;
            }

            $collected .= $chunk;
            ++$reads;
        }

        self::assertSame($this->bigBody($size), $collected);
        self::assertSame($size, strlen($collected));
        // Reading 8 KiB at a time, a 200 KB body must take many reads — proof the
        // body was not delivered as one buffered blob.
        self::assertGreaterThan(1, $reads);
    }

    public function testLargeBodyGetContentsReadsWhole(): void
    {
        $size = 150_000;

        $response = $this->client()->sendRequest($this->request('GET', '/big/' . $size));

        self::assertSame($this->bigBody($size), (string) $response->getBody());
    }

    public function testAbandonedBodyLeavesNoDanglingTasks(): void
    {
        $response = $this->client()->sendRequest($this->request('GET', '/big/200000'));

        // Read just the first chunk, then drop the response without draining it.
        self::assertNotSame('', $response->getBody()->read(1024));

        // Dropping the response releases the streaming body (__destruct), which
        // stops the abandoned flow on the Go side.
        unset($response);

        $this->assertNoTasksCount();
    }

    public function testNetworkErrorThrowsNetworkException(): void
    {
        $client  = $this->client(new HttpClientOptions(requestTimeoutMs: 2_000, connectTimeoutMs: 1_000));
        $request = $this->factory->createRequest('GET', 'http://127.0.0.1:1');

        try {
            $client->sendRequest($request);

            self::fail('Expected a NetworkExceptionInterface.');
        } catch (NetworkExceptionInterface $exception) {
            self::assertSame($request, $exception->getRequest());
        }
    }

    public function testRequestTimeoutThrowsNetworkException(): void
    {
        $client  = $this->client(new HttpClientOptions(requestTimeoutMs: 200));
        $request = $this->request('GET', '/msleep/3000');

        $this->expectException(NetworkExceptionInterface::class);

        $client->sendRequest($request);
    }

    public function testRequestHeadersReachTheServer(): void
    {
        $request = $this->request('GET', '/echo-header')->withHeader('X-Echo', ['one', 'two']);

        $response = $this->client()->sendRequest($request);

        // The server joins the X-Echo values with ",": both values must arrive.
        self::assertSame('one,two', (string) $response->getBody());
    }

    public function testEmptyResponseBody(): void
    {
        $response = $this->client()->sendRequest($this->request('GET', '/empty'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame(0, $response->getBody()->getSize());
    }

    public function testGetSizeIsNullForChunkedResponse(): void
    {
        // /stream is a flushed StreamedResponse: chunked transfer, no Content-Length.
        $response = $this->client()->sendRequest($this->request('GET', '/stream'));

        self::assertNull($response->getBody()->getSize());
        self::assertSame("chunk-a\nchunk-b\nchunk-c\n", (string) $response->getBody());
    }

    public function testMaxResponseBodyExceededThrows(): void
    {
        $client = $this->client(new HttpClientOptions(maxResponseBody: 1024));

        // The very first chunk already exceeds the limit, so the failure surfaces
        // from sendRequest as a generic client error (no net/req marker).
        $this->expectException(ClientExceptionInterface::class);

        $client->sendRequest($this->request('GET', '/big/200000'));
    }

    public function testRedirectsAreFollowedByDefault(): void
    {
        $response = $this->client()->sendRequest($this->request('GET', '/redirect/3'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('done', (string) $response->getBody());
    }

    public function testRedirectsCanBeDisabled(): void
    {
        $client = $this->client(new HttpClientOptions(followRedirects: false));

        $response = $client->sendRequest($this->request('GET', '/redirect/3'));

        self::assertSame(302, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('Location'));
    }

    public function testTooManyRedirectsThrowsNetworkException(): void
    {
        $client = $this->client(new HttpClientOptions(maxRedirects: 1));

        $this->expectException(NetworkExceptionInterface::class);

        $client->sendRequest($this->request('GET', '/redirect/5'));
    }

    public function testStreamContractIsReadOnlyAndNonSeekable(): void
    {
        $stream = $this->client()->sendRequest($this->request('GET', '/'))->getBody();

        self::assertTrue($stream->isReadable());
        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isSeekable());
        self::assertSame('', $stream->read(0));

        foreach ([fn() => $stream->seek(0), fn() => $stream->rewind(), fn() => $stream->write('x')] as $operation) {
            try {
                $operation();

                self::fail('Expected a RuntimeException for an unsupported stream operation.');
            } catch (RuntimeException) {
                // expected
            }
        }

        // After close() the stream is spent: reads return '' and it reports eof.
        $stream->close();

        self::assertSame('', $stream->read(10));
        self::assertTrue($stream->eof());
    }

    public function testStreamingResponseInsideCoroutine(): void
    {
        $size      = 200_000;
        $client    = $this->client();
        $factory   = $this->factory;
        $baseUrl   = $this->baseUrl();
        $collected = '';
        $sleptDone = false;

        $waitGroup = WaitGroup::create();

        $waitGroup->add(function () use ($client, $factory, $baseUrl, $size, &$collected): void {
            $stream = $client->sendRequest($factory->createRequest('GET', $baseUrl . '/big/' . $size))->getBody();

            while (!$stream->eof()) {
                $chunk = $stream->read(8192);

                if ($chunk === '') {
                    break;
                }

                $collected .= $chunk;
            }
        });

        $waitGroup->add(function () use ($client, $factory, $baseUrl, &$sleptDone): void {
            $client->sendRequest($factory->createRequest('GET', $baseUrl . '/msleep/50'));

            $sleptDone = true;
        });

        $waitGroup->waitAll();

        // The streamed body was read chunk by chunk inside its coroutine while the
        // other coroutine ran concurrently.
        self::assertSame($this->bigBody($size), $collected);
        self::assertTrue($sleptDone);
    }

    public function testStreamedRequestBodyIsSent(): void
    {
        $client = $this->client(new HttpClientOptions(streamRequestBody: true));

        $response = $client->sendRequest($this->request('POST', '/echo', 'streamed hello'));

        self::assertSame('streamed hello', (string) $response->getBody());
    }

    public function testStreamedLargeRequestBodyArrivesIntact(): void
    {
        $client = $this->client(new HttpClientOptions(streamRequestBody: true));

        // ~240 KB, several upload chunks; /upload reads it streamed and returns its
        // sha256, so every byte must have arrived in order.
        $body = str_repeat('payload-', 30_000);

        $response = $client->sendRequest($this->request('POST', '/upload', $body));

        self::assertSame(hash('sha256', $body), (string) $response->getBody());
    }

    public function testStreamedRequestWithEmptyBody(): void
    {
        $client = $this->client(new HttpClientOptions(streamRequestBody: true));

        $response = $client->sendRequest($this->request('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string) $response->getBody());
    }

    public function testStreamedRequestToUnreachableHostThrows(): void
    {
        $client = $this->client(new HttpClientOptions(
            requestTimeoutMs: 2_000,
            connectTimeoutMs: 1_000,
            streamRequestBody: true,
        ));
        $request = $this->factory->createRequest('POST', 'http://127.0.0.1:1')
            ->withBody($this->factory->createStream('data'));

        $this->expectException(NetworkExceptionInterface::class);

        $client->sendRequest($request);
    }

    public function testStreamedRequestBodyInsideCoroutine(): void
    {
        $client  = $this->client(new HttpClientOptions(streamRequestBody: true));
        $factory = $this->factory;
        $baseUrl = $this->baseUrl();
        $body    = str_repeat('x', 200_000);
        $hash    = '';
        $slept   = false;

        $waitGroup = WaitGroup::create();

        $waitGroup->add(function () use ($client, $factory, $baseUrl, $body, &$hash): void {
            $request  = $factory->createRequest('POST', $baseUrl . '/upload')->withBody($factory->createStream($body));
            $response = $client->sendRequest($request);

            $hash = (string) $response->getBody();
        });

        $waitGroup->add(function () use ($client, $factory, $baseUrl, &$slept): void {
            $client->sendRequest($factory->createRequest('GET', $baseUrl . '/msleep/50'));

            $slept = true;
        });

        $waitGroup->waitAll();

        self::assertSame(hash('sha256', $body), $hash);
        self::assertTrue($slept);
    }

    private function bigBody(int $size): string
    {
        $pattern = '0123456789abcdef';

        return substr(str_repeat($pattern, intdiv($size, strlen($pattern)) + 1), 0, $size);
    }
}
