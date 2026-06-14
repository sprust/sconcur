<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

class HttpServerErrorsTest extends BaseHttpServerTestCase
{
    public function testUnknownRouteReturns404(): void
    {
        [$status, $body] = $this->request('GET', '/does-not-exist');

        self::assertSame(404, $status);
        self::assertSame('not found', $body);
    }

    public function testThrowingHandlerReturns500(): void
    {
        [$status] = $this->request('GET', '/throw');

        self::assertSame(500, $status);
    }

    public function testServerKeepsServingAfterAnError(): void
    {
        $this->request('GET', '/throw');

        [$status, $body] = $this->request('GET', '/');

        self::assertSame(200, $status);
        self::assertSame('ok', $body);
    }

    public function testExplicitStatusCodes(): void
    {
        foreach ([400, 404, 418, 500, 503] as $code) {
            [$status, $body] = $this->request('GET', '/status/' . $code);

            self::assertSame($code, $status);
            self::assertSame('status ' . $code, $body);
        }
    }

    public function testNonGetMethodOnGetRouteReturns405(): void
    {
        [$status] = $this->request('DELETE', '/sleep');

        self::assertSame(405, $status);
    }

    public function testBodyOverTheLimitReturns413(): void
    {
        // The demo server caps the body at 64 KiB; send a little over that.
        $body = str_repeat('x', 70000);

        [$status] = $this->request('POST', '/echo', $body);

        self::assertSame(413, $status);
    }

    public function testBodyUnderTheLimitIsAccepted(): void
    {
        $body = str_repeat('x', 60000);

        [$status, $echoed] = $this->request('POST', '/echo', $body);

        self::assertSame(200, $status);
        self::assertSame($body, $echoed);
    }
}
