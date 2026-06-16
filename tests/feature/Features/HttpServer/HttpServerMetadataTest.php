<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

class HttpServerMetadataTest extends BaseHttpServerTestCase
{
    public function testConnectionMetadataIsExposed(): void
    {
        // /meta echoes "<proto> <host>" from the decoded Request.
        [$status, $body] = $this->request(
            method: 'GET',
            path: '/meta',
        );

        self::assertSame(200, $status);

        [$protocol, $host] = explode(' ', $body, 2);

        self::assertStringStartsWith('HTTP/', $protocol);
        self::assertNotSame('', $host, 'request Host must be populated');
    }

    public function testMultipleSetCookieHeadersArePreserved(): void
    {
        $headers = $this->responseHeaders(
            method: 'GET',
            path: '/cookies',
        );

        self::assertArrayHasKey('set-cookie', $headers);
        self::assertSame(['a=1', 'b=2'], $headers['set-cookie']);
    }
}
