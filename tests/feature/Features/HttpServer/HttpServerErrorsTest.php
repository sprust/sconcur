<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

class HttpServerErrorsTest extends BaseHttpServerTestCase
{
    public function testUnknownRouteReturns404(): void
    {
        [$status, $body] = $this->request(
            method: 'GET',
            path: '/does-not-exist',
        );

        self::assertSame(404, $status);
        self::assertSame('not found', $body);
    }

    public function testThrowingHandlerReturns500(): void
    {
        [$status] = $this->request(
            method: 'GET',
            path: '/throw',
        );

        self::assertSame(500, $status);
    }

    public function testServerKeepsServingAfterAnError(): void
    {
        $this->request(
            method: 'GET',
            path: '/throw',
        );

        [$status, $body] = $this->request(
            method: 'GET',
            path: '/',
        );

        self::assertSame(200, $status);
        self::assertSame('ok', $body);
    }

    public function testExplicitStatusCodes(): void
    {
        foreach ([400, 404, 418, 500, 503] as $code) {
            [$status, $body] = $this->request(
                method: 'GET',
                path: '/status/' . $code,
            );

            self::assertSame($code, $status);
            self::assertSame('status ' . $code, $body);
        }
    }

    public function testNonGetMethodOnGetRouteReturns405(): void
    {
        [$status] = $this->request(
            method: 'DELETE',
            path: '/sleep',
        );

        self::assertSame(405, $status);
    }

    public function testBodyOverTheLimitReturns413(): void
    {
        // The demo server caps the body at 64 KiB; send a little over that.
        $body = str_repeat('x', 70000);

        [$status] = $this->request(
            method: 'POST',
            path: '/echo',
            body: $body,
        );

        self::assertSame(413, $status);
    }

    public function testBodyUnderTheLimitIsAccepted(): void
    {
        $body = str_repeat('x', 60000);

        [$status, $echoed] = $this->request(
            method: 'POST',
            path: '/echo',
            body: $body,
        );

        self::assertSame(200, $status);
        self::assertSame($body, $echoed);
    }

    /**
     * @return array<string, int>
     */
    protected static function serverOptions(): array
    {
        // Small body limit so the 413 test only needs a few KB over it (a large
        // over-limit upload risks a connection reset before the 413 is read).
        return ['maxRequestBody' => 65536];
    }
}
