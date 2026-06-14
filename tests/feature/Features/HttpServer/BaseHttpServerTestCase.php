<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SConcur\Tests\Impl\HttpServer\TestHttpServer;

/**
 * Base for HTTP-server tests. Each test class spawns its own real server process
 * (via TestHttpServer) for the whole class, with the launch options it needs —
 * no shared, externally-started container required.
 */
abstract class BaseHttpServerTestCase extends TestCase
{
    private static ?TestHttpServer $server = null;

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

    /**
     * Launch options (kebab-case) overriding the server defaults for this whole
     * test class. Override to tune the server, e.g. ['max-request-body' => 65536].
     *
     * @return array<string, int>
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

    /**
     * @param array<int, string> $headers raw request headers, e.g. ['X-Echo: hi']
     *
     * @return array{int, string} [status, body]
     */
    protected function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $curl = curl_init($this->baseUrl() . $path);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 5,
        ]);

        if ($headers !== []) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($curl);
        $status       = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        return [(int) $status, is_string($responseBody) ? $responseBody : ''];
    }

    /**
     * Performs a request and returns its response headers, each as a list of
     * values (a header may legitimately appear more than once, e.g. Set-Cookie).
     * Header names are lower-cased.
     *
     * @return array<string, array<int, string>>
     */
    protected function responseHeaders(string $method, string $path): array
    {
        $curl = curl_init($this->baseUrl() . $path);

        $headers = [];

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HEADERFUNCTION => static function ($_curl, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))][] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        curl_exec($curl);
        curl_close($curl);

        return $headers;
    }

    /**
     * Fires the given GET paths concurrently and returns their [status, body].
     *
     * @param array<int, string> $paths
     *
     * @return array<int, array{int, string}>
     */
    protected function concurrentGet(array $paths): array
    {
        $multi   = curl_multi_init();
        $handles = [];

        foreach ($paths as $index => $path) {
            $curl = curl_init($this->baseUrl() . $path);

            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
            ]);

            curl_multi_add_handle($multi, $curl);

            $handles[$index] = $curl;
        }

        $running = null;

        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi);
        } while ($running > 0);

        $results = [];

        foreach ($handles as $index => $curl) {
            $results[$index] = [
                (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
                (string) curl_multi_getcontent($curl),
            ];

            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
        }

        curl_multi_close($multi);

        return $results;
    }
}
