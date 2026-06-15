<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpClient;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use SConcur\Features\HttpClient\HttpClient;
use SConcur\Features\HttpClient\HttpClientOptions;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\HttpServer\TestHttpServer;

/**
 * Base for HTTP-client tests: extends BaseTestCase (extension lifecycle +
 * no-dangling-tasks assertion) and spawns a real SConcur HTTP server as the
 * request target for the whole class — a true client↔server check in one codebase.
 */
abstract class BaseHttpClientTestCase extends BaseTestCase
{
    private static ?TestHttpServer $server = null;

    protected Psr17Factory $factory;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$server = TestHttpServer::start(static::serverOptions());
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new Psr17Factory();
    }

    /**
     * @return array<string, int|bool>
     */
    protected static function serverOptions(): array
    {
        return [];
    }

    protected static function server(): TestHttpServer
    {
        if (self::$server === null) {
            throw new RuntimeException('Test HTTP server is not started.');
        }

        return self::$server;
    }

    protected function baseUrl(): string
    {
        return self::server()->baseUrl();
    }

    protected function client(HttpClientOptions $options = new HttpClientOptions()): HttpClient
    {
        return new HttpClient($this->factory, $options);
    }

    protected function request(string $method, string $path, ?string $body = null): RequestInterface
    {
        $request = $this->factory->createRequest($method, $this->baseUrl() . $path);

        if ($body !== null) {
            $request = $request->withBody($this->factory->createStream($body));
        }

        return $request;
    }
}
