<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use SConcur\Features\HttpServer\Dto\LazyHeadersRequest;
use SConcur\Tests\Feature\BaseTestCase;

/**
 * The request the server hands a handler defers its headers and its query
 * string. What that must not change is anything the handler can observe, so the
 * deferral is asserted against the same request built eagerly.
 *
 * The deferral itself is not directly observable — that is the point of it — so
 * what is asserted instead is that it survives the calls a router makes and that
 * the value still arrives afterwards.
 */
class LazyHeadersRequestTest extends BaseTestCase
{
    public function testAHeaderIsThereWhenAsked(): void
    {
        $request = $this->lazy(['X-Trace' => ['abc'], 'Accept' => ['text/plain']]);

        self::assertTrue($request->hasHeader('x-trace'));
        self::assertSame(['abc'], $request->getHeader('X-Trace'));
        self::assertSame('text/plain', $request->getHeaderLine('accept'));
        self::assertArrayHasKey('X-Trace', $request->getHeaders());
    }

    public function testTheQueryStringIsParsedOnlyWhenAsked(): void
    {
        $request = $this->lazy(query: 'page=3&filter%5Bstatus%5D=paid');

        self::assertSame(
            [
                'page'   => '3',
                'filter' => ['status' => 'paid'],
            ],
            $request->getQueryParams(),
        );
    }

    /**
     * The point of the deferral: a router that reads the method and the path and
     * files its route parameters must not pay for headers it never reads.
     */
    public function testSettingAttributesDoesNotBuildTheHeaders(): void
    {
        $request = $this->lazy(['X-Trace' => ['abc']]);

        $routed = $request
            ->withAttribute('route', 'orders.index')
            ->withAttribute('id', 42);

        self::assertSame('GET', $routed->getMethod());
        self::assertSame('/orders', $routed->getUri()->getPath());
        self::assertSame('orders.index', $routed->getAttribute('route'));

        // Still deferred: the header only materializes on this call, and the
        // value has to survive the two withAttribute hops to prove it was
        // carried rather than lost.
        self::assertSame('abc', $routed->getHeaderLine('X-Trace'));
    }

    public function testItMatchesTheSameRequestBuiltEagerly(): void
    {
        $factory = new Psr17Factory();
        $headers = [
            'Host'         => ['example.test'],
            'X-Trace'      => ['abc'],
            'Accept'       => ['text/plain'],
            'X-Repeated'   => ['one', 'two'],
        ];

        $eager = $factory->createServerRequest('GET', 'http://example.test/orders');

        foreach ($headers as $name => $values) {
            $eager = $eager->withHeader($name, $values);
        }

        $eager = $eager->withQueryParams(['page' => '3']);

        $lazy = $this->lazy($headers, 'page=3');

        self::assertSame($eager->getHeaders(), $lazy->getHeaders());
        self::assertSame($eager->getQueryParams(), $lazy->getQueryParams());
        self::assertSame($eager->getMethod(), $lazy->getMethod());
        self::assertSame((string) $eager->getUri(), (string) $lazy->getUri());
    }

    public function testAHeaderCanBeReplacedAndRemoved(): void
    {
        $request = $this->lazy(['X-Trace' => ['abc'], 'Accept' => ['text/plain']]);

        $changed = $request
            ->withHeader('X-Trace', 'xyz')
            ->withoutHeader('Accept')
            ->withAddedHeader('X-Trace', 'more');

        self::assertSame(['xyz', 'more'], $changed->getHeader('X-Trace'));
        self::assertFalse($changed->hasHeader('Accept'));

        // The original is untouched, as PSR-7 immutability requires.
        self::assertSame(['abc'], $request->getHeader('X-Trace'));
        self::assertTrue($request->hasHeader('Accept'));
    }

    public function testReplacingTheQueryParamsDropsTheRawString(): void
    {
        $request = $this->lazy(query: 'page=3');

        $replaced = $request->withQueryParams(['page' => '9']);

        self::assertSame(['page' => '9'], $replaced->getQueryParams());
        self::assertSame(['page' => '3'], $request->getQueryParams());
    }

    public function testMaterializeReturnsAPlainRequestWithEverythingApplied(): void
    {
        $request = $this->lazy(['X-Trace' => ['abc']], 'page=3');

        $plain = $request->materialize();

        self::assertNotInstanceOf(LazyHeadersRequest::class, $plain);
        self::assertInstanceOf(ServerRequestInterface::class, $plain);
        self::assertSame('abc', $plain->getHeaderLine('X-Trace'));
        self::assertSame(['page' => '3'], $plain->getQueryParams());
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    protected function lazy(array $headers = [], ?string $query = null): LazyHeadersRequest
    {
        $factory = new Psr17Factory();

        return new LazyHeadersRequest(
            request: $factory->createServerRequest('GET', 'http://example.test/orders'),
            pendingHeaders: $headers,
            pendingQuery: $query,
        );
    }
}
