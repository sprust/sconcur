<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketClient;

use SConcur\Features\SocketClient\Dto\Connection;
use SConcur\Features\SocketClient\SocketClient;
use SConcur\Features\SocketClient\SocketClientOptions;
use SConcur\Tests\Feature\BaseAsyncTestCase;
use SConcur\Tests\Impl\SocketServer\TestSocketServer;
use Throwable;

/**
 * The concurrency contract via BaseAsyncTestCase: two coroutines each open a
 * connection and do two sequential "msleep" round-trips; running them concurrently
 * must take about as long as one coroutine's chain, not the sum. Also checks the
 * connect-error path (sync + async). The target is a real SConcur SocketServer.
 */
class SocketClientConcurrencyTest extends BaseAsyncTestCase
{
    private const int SLEEP_MS = 60;

    private static ?TestSocketServer $server = null;

    private SocketClient $client;

    private ?Connection $connectionOne = null;
    private ?Connection $connectionTwo = null;

    private float $startTime = 0;
    private float $endTime   = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$server = TestSocketServer::start();
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

        $this->client = new SocketClient();
    }

    protected function on_1_start(): void
    {
        $this->startTime = microtime(true);

        $this->connectionOne = $this->client->connect($this->address());

        $this->sleepRoundTrip($this->connectionOne);
    }

    protected function on_1_middle(): void
    {
        $this->sleepRoundTrip(self::connection($this->connectionOne));

        self::connection($this->connectionOne)->close();
    }

    protected function on_2_start(): void
    {
        $this->connectionTwo = $this->client->connect($this->address());

        $this->sleepRoundTrip($this->connectionTwo);
    }

    protected function on_2_middle(): void
    {
        $this->sleepRoundTrip(self::connection($this->connectionTwo));

        self::connection($this->connectionTwo)->close();
    }

    protected function on_iterate(): void
    {
        $this->endTime = microtime(true);
    }

    protected function on_exception(): void
    {
        // An unreachable port: the connection is refused → a connect error.
        $client = new SocketClient(
            new SocketClientOptions(
                connectTimeoutMs: 1_000,
            ),
        );

        $client->connect('127.0.0.1:1');
    }

    protected function assertException(Throwable $exception): void
    {
        // The Go side tags network failures with a "net:" marker, preserved through the
        // wrapping exceptions (SocketClientConnectException on the sync path, wrapped
        // again in CallbackExecutionException on the async path).
        self::assertStringContainsString(
            'net:',
            $exception->getMessage(),
        );
    }

    protected function assertResult(array $results): void
    {
        // Each coroutine does two SLEEP_MS round-trips in sequence, so its own chain
        // takes >= 2 * sleep. Run concurrently the two coroutines overlap, so the
        // wall-clock total stays well under the sequential sum (4 * sleep).
        $totalTimeMs = ($this->endTime - $this->startTime) * 1000;

        self::assertGreaterThanOrEqual(
            self::SLEEP_MS * 2 * 0.8,
            $totalTimeMs,
            "Total time $totalTimeMs ms is too low to have actually waited.",
        );

        self::assertLessThan(
            self::SLEEP_MS * 4,
            $totalTimeMs,
            "Total time $totalTimeMs ms suggests sequential, not concurrent, work.",
        );
    }

    private function sleepRoundTrip(Connection $connection): void
    {
        $connection->write('msleep:' . self::SLEEP_MS);

        self::assertSame('slept', $connection->read());
    }

    private function address(): string
    {
        return self::server()->host() . ':' . self::server()->port();
    }

    private static function server(): TestSocketServer
    {
        if (self::$server === null) {
            self::fail('Test socket server is not started.');
        }

        return self::$server;
    }

    private static function connection(?Connection $connection): Connection
    {
        if ($connection === null) {
            self::fail('Connection is not open.');
        }

        return $connection;
    }
}
