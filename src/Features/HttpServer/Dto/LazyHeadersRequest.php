<?php

declare(strict_types=1);

namespace SConcur\Features\HttpServer\Dto;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * A ServerRequestInterface that has not paid for its headers or its query string
 * yet, and pays only if the handler asks.
 *
 * The measurement behind it (`make bench-request`): decoding one request costs
 * 7.75 us, of which seven `withHeader` calls are 4.17 and parsing a query string
 * another 1.9 — while a typical handler reads the method and the path, 0.12 us
 * worth. A router that dispatches on those two and a handler that answers from
 * them never touch the rest, and this defers exactly that rest.
 *
 * The delegate is still built by the application's PSR-17 factory, so whatever
 * request implementation it returns is the one the handler ends up holding: this
 * only postpones the two steps, it does not replace the object.
 *
 * Laziness survives the withers a router uses. `withAttribute` and its siblings
 * return a new lazy request around the new delegate, carrying the same pending
 * headers — so setting route attributes does not force the headers to be built.
 * Anything that reads or changes a header materializes them first, once.
 */
class LazyHeadersRequest implements ServerRequestInterface
{
    /**
     * @param array<string, array<int, string>> $pendingHeaders headers not yet applied to the
     *                                                          delegate; empty once materialized
     * @param null|string                       $pendingQuery   the raw query string, not yet parsed;
     *                                                          null once parsed or replaced
     */
    public function __construct(
        protected ServerRequestInterface $request,
        protected array $pendingHeaders = [],
        protected ?string $pendingQuery = null,
    ) {
    }

    public function getProtocolVersion(): string
    {
        return $this->request->getProtocolVersion();
    }

    public function withProtocolVersion(string $version): static
    {
        return $this->derive($this->request->withProtocolVersion($version));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getHeaders(): array
    {
        return $this->withHeaders()->getHeaders();
    }

    public function hasHeader(string $name): bool
    {
        return $this->withHeaders()->hasHeader($name);
    }

    /**
     * @return array<int, string>
     */
    public function getHeader(string $name): array
    {
        return $this->withHeaders()->getHeader($name);
    }

    public function getHeaderLine(string $name): string
    {
        return $this->withHeaders()->getHeaderLine($name);
    }

    public function withHeader(string $name, mixed $value): static
    {
        return $this->derive($this->withHeaders()->withHeader($name, $value));
    }

    public function withAddedHeader(string $name, mixed $value): static
    {
        return $this->derive($this->withHeaders()->withAddedHeader($name, $value));
    }

    public function withoutHeader(string $name): static
    {
        return $this->derive($this->withHeaders()->withoutHeader($name));
    }

    public function getBody(): StreamInterface
    {
        return $this->request->getBody();
    }

    public function withBody(StreamInterface $body): static
    {
        return $this->derive($this->request->withBody($body));
    }

    public function getRequestTarget(): string
    {
        return $this->request->getRequestTarget();
    }

    public function withRequestTarget(string $requestTarget): static
    {
        return $this->derive($this->request->withRequestTarget($requestTarget));
    }

    public function getMethod(): string
    {
        return $this->request->getMethod();
    }

    public function withMethod(string $method): static
    {
        return $this->derive($this->request->withMethod($method));
    }

    public function getUri(): UriInterface
    {
        return $this->request->getUri();
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        return $this->derive($this->request->withUri($uri, $preserveHost));
    }

    /**
     * @return array<string, mixed>
     */
    public function getServerParams(): array
    {
        return $this->request->getServerParams();
    }

    /**
     * @return array<string, mixed>
     */
    public function getCookieParams(): array
    {
        return $this->request->getCookieParams();
    }

    /**
     * @param array<string, mixed> $cookies
     */
    public function withCookieParams(array $cookies): static
    {
        return $this->derive($this->request->withCookieParams($cookies));
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->withQuery()->getQueryParams();
    }

    /**
     * @param array<string, mixed> $query
     */
    public function withQueryParams(array $query): static
    {
        // The caller replaces the params outright, so the raw string is dropped
        // rather than parsed into what is about to be overwritten.
        $derived = $this->derive($this->request->withQueryParams($query));

        $derived->pendingQuery = null;

        return $derived;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUploadedFiles(): array
    {
        return $this->request->getUploadedFiles();
    }

    /**
     * @param array<string, mixed> $uploadedFiles
     */
    public function withUploadedFiles(array $uploadedFiles): static
    {
        return $this->derive($this->request->withUploadedFiles($uploadedFiles));
    }

    /**
     * @return null|array<mixed>|object
     */
    public function getParsedBody(): mixed
    {
        return $this->request->getParsedBody();
    }

    /**
     * @param null|array<mixed>|object $data
     */
    public function withParsedBody(mixed $data): static
    {
        return $this->derive($this->request->withParsedBody($data));
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->request->getAttributes();
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->request->getAttribute($name, $default);
    }

    public function withAttribute(string $name, mixed $value): static
    {
        return $this->derive($this->request->withAttribute($name, $value));
    }

    public function withoutAttribute(string $name): static
    {
        return $this->derive($this->request->withoutAttribute($name));
    }

    /**
     * The delegate with everything applied — what a caller needs when it is
     * about to hand the request to code that expects a plain PSR-7 object.
     */
    public function materialize(): ServerRequestInterface
    {
        $this->withHeaders();

        return $this->withQuery();
    }

    /**
     * The delegate with the pending headers applied, once. It returns the
     * delegate rather than $this on purpose: every caller here forwards the
     * call to it, and returning $this would send the call back into the method
     * that made it.
     */
    protected function withHeaders(): ServerRequestInterface
    {
        if ($this->pendingHeaders === []) {
            return $this->request;
        }

        $request = $this->request;

        foreach ($this->pendingHeaders as $name => $values) {
            $request = $request->withHeader($name, $values);
        }

        $this->request        = $request;
        $this->pendingHeaders = [];

        return $request;
    }

    /**
     * The delegate with the raw query string parsed into its params, once.
     */
    protected function withQuery(): ServerRequestInterface
    {
        if ($this->pendingQuery === null) {
            return $this->request;
        }

        parse_str($this->pendingQuery, $params);

        $this->request      = $this->request->withQueryParams($params);
        $this->pendingQuery = null;

        return $this->request;
    }

    /**
     * A new lazy request around a changed delegate, still carrying whatever this
     * one had not paid for. This is what keeps a router's withAttribute chain
     * from forcing the headers.
     */
    protected function derive(ServerRequestInterface $request): static
    {
        $derived = clone $this;

        $derived->request = $request;

        return $derived;
    }
}
