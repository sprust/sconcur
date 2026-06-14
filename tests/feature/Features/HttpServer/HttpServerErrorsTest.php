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
}
