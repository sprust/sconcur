<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

class HttpServerStreamingTest extends BaseHttpServerTestCase
{
    public function testStreamedBodyIsAssembledFromChunks(): void
    {
        [$status, $body] = $this->request('GET', '/stream');

        self::assertSame(200, $status);
        self::assertSame("chunk-a\nchunk-b\nchunk-c\n", $body);
    }

    public function testStreamedResponseUsesChunkedTransferEncoding(): void
    {
        $headers = $this->responseHeaders('GET', '/stream');

        // A flushed, unknown-length body is sent without Content-Length, as
        // chunked transfer — proof the response really streamed.
        self::assertArrayHasKey('transfer-encoding', $headers);
        self::assertContains('chunked', $headers['transfer-encoding']);
        self::assertArrayNotHasKey('content-length', $headers);
    }

    public function testServerKeepsServingAfterAStream(): void
    {
        $this->request('GET', '/stream');

        [$status, $body] = $this->request('GET', '/');

        self::assertSame(200, $status);
        self::assertSame('ok', $body);
    }
}
