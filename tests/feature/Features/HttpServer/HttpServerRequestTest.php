<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

class HttpServerRequestTest extends BaseHttpServerTestCase
{
    public function testQueryStringIsExposed(): void
    {
        [$status, $body] = $this->request(
            method: 'GET',
            path: '/query?a=1&b=two&flag',
        );

        self::assertSame(200, $status);
        self::assertSame('a=1&b=two&flag', $body);
    }

    public function testEmptyQueryStringIsEmpty(): void
    {
        [$status, $body] = $this->request(
            method: 'GET',
            path: '/query',
        );

        self::assertSame(200, $status);
        self::assertSame('', $body);
    }

    public function testRequestHeaderIsReceivedByHandler(): void
    {
        [$status, $body] = $this->request(
            method: 'GET',
            path: '/echo-header',
            body: null,
            headers: ['X-Echo: hello world'],
        );

        self::assertSame(200, $status);
        self::assertSame('hello world', $body);
    }

    public function testBinaryBodyRoundTripsExactly(): void
    {
        // Every byte value, including NUL and high bytes.
        $binary = implode('', array_map('chr', range(0, 255)));

        [$status, $body] = $this->request(
            method: 'POST',
            path: '/echo',
            body: $binary,
        );

        self::assertSame(200, $status);
        self::assertSame($binary, $body);
    }

    public function testEmptyResponseBody(): void
    {
        [$status, $body] = $this->request(
            method: 'GET',
            path: '/empty',
        );

        self::assertSame(200, $status);
        self::assertSame('', $body);
    }

    public function testRootRoute(): void
    {
        [$status, $body] = $this->request(
            method: 'GET',
            path: '/',
        );

        self::assertSame(200, $status);
        self::assertSame('ok', $body);
    }
}
