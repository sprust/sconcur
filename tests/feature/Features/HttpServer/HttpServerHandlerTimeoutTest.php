<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

class HttpServerHandlerTimeoutTest extends BaseHttpServerTestCase
{
    public function testSlowHandlerIsAnsweredWith504WithoutWaitingForIt(): void
    {
        // The handler would sleep 10s; the 250ms deadline must answer 504 long
        // before that, freeing the connection.
        $start = microtime(true);

        [$status] = $this->request(
            method: 'GET',
            path: '/msleep/10000',
        );

        $elapsed = microtime(true) - $start;

        self::assertSame(504, $status);
        self::assertLessThan(2.0, $elapsed, sprintf('504 took %.3fs; the deadline did not fire promptly.', $elapsed));
    }

    public function testFastHandlerIsNotAffected(): void
    {
        [$status, $body] = $this->request(
            method: 'GET',
            path: '/msleep/50',
        );

        self::assertSame(200, $status);
        self::assertSame('slept', $body);
    }

    public function testStreamingHandlerIsCutOffByTheTotalDeadline(): void
    {
        // /slow-stream emits "p0\n".."p3\n" 100ms apart (~400ms total). The 250ms
        // total deadline cuts it mid-stream: the head (200) is already on the wire,
        // so the client gets a truncated body, not the full one.
        $full = "p0\np1\np2\np3\n";

        [$status, $body] = $this->captureStream($this->baseUrl() . '/slow-stream');

        self::assertSame(200, $status, 'the head is sent before the deadline');
        self::assertNotSame('', $body, 'some chunks should arrive before the cut');
        self::assertStringStartsWith($body, $full, 'the received body must be a prefix of the full one');
        self::assertNotSame($full, $body, 'the stream must be cut before completing');
    }

    /**
     * @return array<string, int>
     */
    protected static function serverOptions(): array
    {
        return ['handlerTimeoutMs' => 250];
    }

    /**
     * Performs a GET and returns [status, received body], keeping whatever bytes
     * arrived even if the connection is aborted mid-stream.
     *
     * @return array{int, string}
     */
    private function captureStream(string $url): array
    {
        $received = '';

        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_TIMEOUT       => 5,
            CURLOPT_WRITEFUNCTION => static function ($_curl, string $data) use (&$received): int {
                $received .= $data;

                return strlen($data);
            },
        ]);

        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        return [$status, $received];
    }
}
