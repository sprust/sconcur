<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

class HttpServerMetadataTest extends BaseHttpServerTestCase
{
    public function testConnectionMetadataIsExposed(): void
    {
        // /meta echoes "<proto> <host>" from the decoded Request.
        [$status, $body] = $this->request('GET', '/meta');

        self::assertSame(200, $status);

        [$proto, $host] = explode(' ', $body, 2);

        self::assertStringStartsWith('HTTP/', $proto);
        self::assertNotSame('', $host, 'request Host must be populated');
    }

    public function testMultipleSetCookieHeadersArePreserved(): void
    {
        $headers = $this->responseHeaders('GET', '/cookies');

        self::assertArrayHasKey('set-cookie', $headers);
        self::assertSame(['a=1', 'b=2'], $headers['set-cookie']);
    }
}
