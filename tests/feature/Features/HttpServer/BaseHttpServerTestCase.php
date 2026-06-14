<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use PHPUnit\Framework\TestCase;

/**
 * Base for HTTP-server tests. They run against the dedicated `http-server`
 * container (see docker-compose.yml) reached over the Docker network, so start
 * it with `make http-server-restart` first. If it is not reachable the tests are
 * skipped rather than failed.
 */
abstract class BaseHttpServerTestCase extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('HTTP_SERVER_HOST') ?: 'http-server';
        $port = (int) (getenv('HTTP_SERVER_PORT') ?: 8080);

        $this->baseUrl = "http://$host:$port";

        $this->skipUnlessReachable($host, $port);
    }

    /**
     * @return array{int, string} [status, body]
     */
    protected function request(string $method, string $path, ?string $body = null): array
    {
        $curl = curl_init($this->baseUrl . $path);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 5,
        ]);

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
        $curl = curl_init($this->baseUrl . $path);

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
            $curl = curl_init($this->baseUrl . $path);

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

    private function skipUnlessReachable(string $host, int $port): void
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 1.0);

        if (is_resource($connection)) {
            fclose($connection);

            return;
        }

        self::markTestSkipped(
            "HTTP server is not reachable at $host:$port; start it with `make http-server-restart`."
        );
    }
}
