<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpClient;

use Nyholm\Psr7\Factory\Psr17Factory;
use SConcur\Features\HttpClient\HttpClient;
use SConcur\Features\HttpClient\HttpClientOptions;
use SConcur\Tests\Feature\BaseAsyncTestCase;
use SConcur\Tests\Impl\HttpServer\TestHttpServer;
use Throwable;

/**
 * The concurrency contract via BaseAsyncTestCase: two coroutines each fire two
 * sequential requests; running them concurrently must take about as long as one
 * coroutine's chain, not the sum. Also checks the network-error path (sync +
 * async). The request target is a real SConcur HTTP server.
 */
class HttpClientConcurrencyTest extends BaseAsyncTestCase
{
    private const int REQUEST_SLEEP_MS = 60;

    private static ?TestHttpServer $server = null;

    private Psr17Factory $factory;
    private HttpClient $client;

    private float $startTime = 0;
    private float $endTime   = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$server = TestHttpServer::start();
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
        $this->client  = new HttpClient(
            responseFactory: $this->factory,
        );
    }

    protected function on_1_start(): void
    {
        $this->startTime = microtime(true);

        $this->sleepRequest();
    }

    protected function on_1_middle(): void
    {
        $this->sleepRequest();
    }

    protected function on_2_start(): void
    {
        $this->sleepRequest();
    }

    protected function on_2_middle(): void
    {
        $this->sleepRequest();
    }

    protected function on_iterate(): void
    {
        $this->endTime = microtime(true);
    }

    protected function on_exception(): void
    {
        // An unreachable port: the connection is refused → a network-class error.
        $client = new HttpClient(
            responseFactory: $this->factory,
            options: new HttpClientOptions(
                requestTimeoutMs: 2_000,
                connectTimeoutMs: 1_000,
            ),
        );
        $request = $this->factory->createRequest('GET', 'http://127.0.0.1:1');

        $client->sendRequest($request);
    }

    protected function assertException(Throwable $exception): void
    {
        // The Go side tags network failures with a "net:" marker, preserved through
        // the wrapping exceptions.
        self::assertTrue(
            str_contains($exception->getMessage(), 'net:'),
            'Expected a network-marked message, got: ' . $exception->getMessage(),
        );
    }

    protected function assertResult(array $results): void
    {
        // Each coroutine issues two REQUEST_SLEEP_MS requests in sequence, so its
        // own chain takes >= 2 * sleep. Run concurrently the two coroutines overlap,
        // so the wall-clock total stays well under the sequential sum (4 * sleep).
        $totalTimeMs = ($this->endTime - $this->startTime) * 1000;

        self::assertGreaterThanOrEqual(
            self::REQUEST_SLEEP_MS * 2 * 0.8,
            $totalTimeMs,
            "Total time $totalTimeMs ms is too low to have actually waited.",
        );

        self::assertLessThan(
            self::REQUEST_SLEEP_MS * 4,
            $totalTimeMs,
            "Total time $totalTimeMs ms suggests sequential, not concurrent, requests.",
        );
    }

    private function sleepRequest(): void
    {
        $request  = $this->factory->createRequest('GET', self::server()->baseUrl() . '/msleep/' . self::REQUEST_SLEEP_MS);
        $response = $this->client->sendRequest($request);

        self::assertSame('slept', (string) $response->getBody());
    }

    private static function server(): TestHttpServer
    {
        if (self::$server === null) {
            self::fail('Test HTTP server is not started.');
        }

        return self::$server;
    }
}
